<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client answering from a queue. A queued `null` simulates a network error.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|null> */
    private array $queue = [];

    public function queue(?ResponseInterface ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException('No response queued for '.$request->getMethod().' '.$request->getUri());
        }
        $response = array_shift($this->queue);
        if ($response === null) {
            throw new ConnectException('cURL error 28: Operation timed out after 30001 milliseconds (see https://example.test?phone=0813456789)', $request);
        }

        return $response;
    }
}
