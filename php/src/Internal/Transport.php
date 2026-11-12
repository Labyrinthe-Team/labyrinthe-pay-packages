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
                'method' => $method, 'path' => $logPath, 'status' => $status, 'code' => $error->getErrorCode(),
            ]);

            throw $error;
        }
    }

    /** Metadata of the last response received (success or error), null before the first one. */
    public function lastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    /**
     * Seconds to wait before the next attempt, or null when the error must be thrown.
     */
    private function retryDelay(RetryMode $mode, ApiException $error, int $attempt): ?float
    {
        $retryAfter = self::retryAfter($error);

        // Refused before processing: only when the API schedules the re-enable.
        if (\in_array($error->getErrorCode(), self::REFUSED_BEFORE_PROCESSING, true)) {
            return $retryAfter !== null && $retryAfter <= $this->maxRetryWait ? $retryAfter : null;
        }

        // Only the rate limiter: DAILY_LIMIT_REACHED is a business limit.
        if ($error->getStatusCode() === 429) {
            if ($error->getErrorCode() !== ErrorFactory::RATE_LIMITED) {
                return null;
            }
            if ($retryAfter !== null) {
                return $retryAfter <= $this->maxRetryWait ? $retryAfter : null;
            }

            return $this->backoff($attempt);
        }

        return $mode === RetryMode::Safe && $error->getStatusCode() >= 500 ? $this->backoff($attempt) : null;
    }

    /** Exponential backoff with jitter: 0.5 s, 1 s, 2 s… (50 to 100 % of it), capped. */
    private function backoff(int $attempt): float
    {
        $delay = min(self::MAX_BACKOFF, self::BASE_DELAY * (2 ** $attempt));
        $jitter = 0.5 + (mt_rand() / mt_getrandmax()) * 0.5;

        return min($this->maxRetryWait, $delay * $jitter);
    }

    /** Seconds from the Retry-After header (delta-seconds or HTTP date), null when absent or unreadable. */
    private static function retryAfter(ApiException $error): ?float
    {
        $header = $error->getHeader('retry-after');
        if ($header === null) {
            return null;
        }
        $header = trim($header);
        if (preg_match('/^\d+(\.\d+)?$/', $header) === 1) {
            return (float) $header;
        }
        $date = strtotime($header);

        return $date !== false ? (float) max(0, $date - time()) : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UnexpectedValueException('Unexpected API response: the body is not valid JSON.');
        }

        if (!\is_array($decoded)) {
            throw new UnexpectedValueException('Unexpected API response: a JSON object was expected.');
        }

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private static function tryDecode(string $rawBody): array
    {
        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        return $headers;
    }

    private static function isTimeout(ClientExceptionInterface $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'curl error 28') || str_contains($message, 'timeout');
    }

    private function connectionMessage(string $method, string $path, RetryMode $mode, ClientExceptionInterface $e): string
    {
        // The underlying message may contain the full URL: drop its query string (references, numbers).
        $reason = (string) preg_replace('/\?[^\s"\']*/', '?…', $e->getMessage());
        $message = \sprintf('Could not reach the LabPay API (%s %s): %s', $method, $path, $reason);

        if ($mode === RetryMode::OnlyWhenThrottled) {
            $message .= ' The request may have been processed; it was not retried automatically.';
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => Redactor::apiKey($this->apiKey),
            'baseUrl' => $this->baseUrl,
            'maxRetries' => $this->maxRetries,
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('The LabPay transport cannot be serialized (it holds the API key).');
    }
}
