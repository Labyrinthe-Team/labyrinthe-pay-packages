<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Enum\Operation;
use Labyrinthe\LabPay\Enum\PaymentStatus;
use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Internal\Input;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Model\Page;
use Labyrinthe\LabPay\Model\Payment;

/**
 * Track payments (`$client->payments`): list, get, refresh the status.
 */
final class PaymentService
{
    /**
     * @internal use $client->payments
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * One payment by its LabPay id (DEP-…, PAY-…, RFD-…), in the mode of the key.
     *
     * @throws \Labyrinthe\LabPay\Exception\NotFoundException PAYMENT_NOT_FOUND
     * @throws ApiException
     * @throws ConnectionException
     */
    public function get(string $id): Payment
    {
        $response = $this->transport->request('GET', '/payments/'.rawurlencode(Input::id($id)), RetryMode::Safe);

        return Payment::fromArray($response->data());
    }

    /**
     * Asks LabPay to re-read the status of a pending payment now, then returns
     * the updated payment (an already final payment is returned as is). A
     * safety net: prefer notifications, never poll in a tight loop.
     *
     * @throws \Labyrinthe\LabPay\Exception\NotFoundException PAYMENT_NOT_FOUND
     * @throws ApiException
     * @throws ConnectionException
     */
    public function sync(string $id): Payment
    {
        $response = $this->transport->request('POST', '/payments/'.rawurlencode(Input::id($id)).'/sync', RetryMode::Safe);

        return Payment::fromArray($response->data());
    }

    /**
     * V2 payments of the token, newest first, in the mode of the key.
     *
     * @param \DateTimeInterface|string|null $from creation date lower bound (YYYY-MM-DD)
     * @param \DateTimeInterface|string|null $to   creation date upper bound (YYYY-MM-DD, ≥ from)
     * @param int|null                       $perPage 1 to 100 (API default: 20)
     *
     * @return Page<Payment>
     *
     * @throws InvalidArgumentException
     * @throws ApiException
