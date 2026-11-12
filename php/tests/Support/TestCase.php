<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Labyrinthe\LabPay\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base of the unit tests: a client wired to a simulated HTTP client.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected MockHttpClient $http;
    protected RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->sleeper = new RecordingSleeper();
    }

    protected function client(string $apiKey = 'test_abcdefghijklmnop', int $maxRetries = 2, bool $autoReference = true): Client
    {
        return new Client(
            apiKey: $apiKey,
            baseUrl: 'https://api.example.test/api',
            maxRetries: $maxRetries,
            autoReference: $autoReference,
            httpClient: $this->http,
            sleeper: $this->sleeper,
        );
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    protected static function json(int $status, array $body, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Labyrinthe-Mode' => 'test'] + $headers, json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * A payment as returned by the API (PaymentPresenter).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function payment(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6',
            'reference' => 'ORDER-42',
            'operation' => 'deposit',
            'livemode' => false,
            'status' => 'pending',
            'gateway_status' => 'ACCEPTED',
            'amount' => '1000.00',
            'fee' => '35.00',
            'total_amount' => '1035.00',
            'currency' => 'CDF',
            'country' => 'COD',
            'phone' => '0813456789',
            'provider' => 'VODACOM_MPESA_COD',
            'provider_transaction_id' => null,
            'failure' => null,
            'metadata' => ['orderId' => '42'],
            'created_at' => '2026-11-11T10:00:00+00:00',
            'completed_at' => null,
            'refunded_amount' => '0.00',
        ];
    }

    protected function lastRequest(): RequestInterface
    {
        $request = $this->http->requests[\count($this->http->requests) - 1] ?? null;
        self::assertNotNull($request, 'No request was sent.');

        return $request;
    }

    /**
     * @return array<mixed>
     */
    protected function lastBody(): array
    {
        $body = json_decode((string) $this->lastRequest()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
