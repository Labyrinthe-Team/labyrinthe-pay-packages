<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Http\NativeSleeper;
use Labyrinthe\LabPay\Http\ResponseInfo;
use Labyrinthe\LabPay\Http\Sleeper;
use Labyrinthe\LabPay\Internal\Redactor;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Service\AccountService;
use Labyrinthe\LabPay\Service\DepositService;
use Labyrinthe\LabPay\Service\NotificationService;
use Labyrinthe\LabPay\Service\PaymentService;
use Labyrinthe\LabPay\Service\PayoutService;
use Labyrinthe\LabPay\Service\ProviderService;
use Labyrinthe\LabPay\Service\RefundService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * LabPay API V2 client.
 *
 *     $client = new Client('test_…');
 *     $payment = $client->deposits->create(amount: '1000', currency: 'CDF', phone: '0813456789', reference: 'ORDER-42');
 *
 * A key starting with `test_` uses the sandbox: no real money moves, test
 * balances and payments are separate from live ones. Going live only means
 * switching keys.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.labyrinthe-rdc.com/api';
    public const TEST_KEY_PREFIX = 'test_';
    private const API_PATH = '/api';
    private const VERSION_PATH = '/V2';

    public readonly DepositService $deposits;
    public readonly PayoutService $payouts;
    public readonly RefundService $refunds;
    public readonly PaymentService $payments;
    public readonly ProviderService $providers;
    public readonly AccountService $account;
    public readonly NotificationService $notifications;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly Transport $transport;

    /**
     * @param string                       $apiKey          live token or test key (`test_…`); keep it server-side
     * @param string|null                  $baseUrl         API root, e.g. "https://api.labyrinthe-rdc.com/api"; "/V2" is added
     * @param float                        $timeout         total request timeout, in seconds (default Guzzle client only)
     * @param float                        $connectTimeout  connection timeout, in seconds (default Guzzle client only)
     * @param int                          $maxRetries      automatic retries when it is safe (0 disables them)
     * @param float                        $maxRetryWait    longest wait accepted before a retry (Retry-After), in seconds
     * @param bool                         $autoReference   generate a `reference` for payments sent without one, so that
     *                                                      they can be retried without risk of a double payment
     * @param ClientInterface|null         $httpClient      any PSR-18 client (configure its timeouts yourself)
     * @param RequestFactoryInterface|null $requestFactory  PSR-17 factory (defaults to Guzzle's)
     * @param StreamFactoryInterface|null  $streamFactory   PSR-17 factory (defaults to Guzzle's)
     * @param LoggerInterface|null         $logger          PSR-3 logger; never receives the key or query values
     * @param Sleeper|null                 $sleeper         waits between retries (inject a no-op one in tests)
     * @param string|null                  $userAgentSuffix appended to the User-Agent (integrations, e.g. "labpay-laravel/0.1.0")
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        float $connectTimeout = 10.0,
        int $maxRetries = 2,
        float $maxRetryWait = 10.0,
        bool $autoReference = true,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?Sleeper $sleeper = null,
        ?string $userAgentSuffix = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('A LabPay API token or test key (test_…) is required.');
        }
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('timeout and connectTimeout must be greater than 0.');
        }
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new InvalidArgumentException('maxRetries must be between 0 and 10.');
        }
        if ($maxRetryWait < 0) {
            throw new InvalidArgumentException('maxRetryWait cannot be negative.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL);

        $factory = new HttpFactory();

        $this->transport = $transport = new Transport(
            apiKey: $apiKey,
            baseUrl: $this->baseUrl,
            http: $httpClient ?? new GuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'http_errors' => false,
                'allow_redirects' => false,
            ]),
            requestFactory: $requestFactory ?? $factory,
            streamFactory: $streamFactory ?? $factory,
            maxRetries: $maxRetries,
