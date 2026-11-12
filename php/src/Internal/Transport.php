<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Exception\UnexpectedValueException;
use Labyrinthe\LabPay\Http\ResponseInfo;
use Labyrinthe\LabPay\Http\Sleeper;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends requests, applies the retry policy and maps errors.
 *
 * @internal
 */
final class Transport
{
    private const BASE_DELAY = 0.5;
    private const MAX_BACKOFF = 8.0;

    /**
     * 503 codes that refuse the request before any processing (platform
     * switch, maintenance, provider closed): only retried after a Retry-After.
     */
    public const REFUSED_BEFORE_PROCESSING = [
        'MAINTENANCE',
        'API_DISABLED',
        'DEPOSITS_DISABLED',
        'WITHDRAWALS_DISABLED',
        'PROVIDER_TEMPORARILY_UNAVAILABLE',
        'V2_DISABLED',
        'TEST_MODE_DISABLED',
    ];

    private ?ResponseInfo $lastResponse = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly int $maxRetries,
        private readonly float $maxRetryWait,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $logger,
        private readonly string $userAgent,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body             JSON body
     * @param bool                       $paymentOnRefusal a 422 carrying a payment in `data` (payment refused at
     *                                                     initiation) is returned instead of thrown
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function request(
        string $method,
        string $path,
        RetryMode $mode,
        array $query = [],
        #[\SensitiveParameter]
        ?array $body = null,
        bool $paymentOnRefusal = false,
    ): Response {
        $query = array_filter($query, static fn (mixed $v): bool => $v !== null && $v !== '');
        $queryString = $query === [] ? '' : http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        $url = $this->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString);
        $logPath = Redactor::path($path, $queryString);
        $payload = $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        for ($attempt = 0;; ++$attempt) {
            $request = $this->requestFactory->createRequest($method, $url)
                ->withHeader('Authorization', 'Bearer '.$this->apiKey)
                ->withHeader('Accept', 'application/json')
                ->withHeader('User-Agent', $this->userAgent);
            if ($payload !== null) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream($payload));
            }

            $this->logger->debug('LabPay request', ['method' => $method, 'path' => $logPath, 'attempt' => $attempt + 1]);
            $startedAt = microtime(true);

            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $timeout = self::isTimeout($e);
                if ($mode === RetryMode::Safe && $attempt < $this->maxRetries) {
                    $delay = $this->backoff($attempt);
                    $this->logger->warning('LabPay network error, retrying', [
                        'method' => $method, 'path' => $logPath, 'attempt' => $attempt + 1, 'delay' => round($delay, 3), 'timeout' => $timeout,
                    ]);
                    $this->sleeper->sleep($delay);

                    continue;
                }

                $this->logger->error('LabPay network error', ['method' => $method, 'path' => $logPath, 'timeout' => $timeout]);

                throw new ConnectionException($this->connectionMessage($method, $path, $mode, $e), $timeout, $e);
            }

            $status = $response->getStatusCode();
            $headers = self::headers($response);
            $rawBody = (string) $response->getBody();
            $this->lastResponse = new ResponseInfo($status, $headers);
            $this->logger->debug('LabPay response', [
                'method' => $method,
                'path' => $logPath,
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'mode' => $headers['x-labyrinthe-mode'] ?? null,
            ]);

            if ($status >= 200 && $status < 300) {
                return new Response($status, self::decode($rawBody), $headers);
            }

            if ($paymentOnRefusal && $status === 422) {
                $decoded = self::tryDecode($rawBody);
                if (isset($decoded['data']) && \is_array($decoded['data']) && isset($decoded['data']['id'])) {
                    return new Response($status, $decoded, $headers);
                }
            }

            $error = ErrorFactory::fromResponse($status, $rawBody, $headers);

            if ($attempt < $this->maxRetries) {
                $delay = $this->retryDelay($mode, $error, $attempt);
                if ($delay !== null) {
                    $this->logger->warning('LabPay request failed, retrying', [
                        'method' => $method, 'path' => $logPath, 'status' => $status, 'code' => $error->getErrorCode(),
                        'attempt' => $attempt + 1, 'delay' => round($delay, 3),
                    ]);
                    $this->sleeper->sleep($delay);

                    continue;
                }
            }

            $this->logger->info('LabPay API error', [
