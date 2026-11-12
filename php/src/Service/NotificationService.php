<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Enum\NotificationStatus;
use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Internal\Input;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Model\Notification;
use Labyrinthe\LabPay\Model\Page;

/**
 * History of the notifications sent to your callback_url (`$client->notifications`).
 */
final class NotificationService
{
    /**
     * @internal use $client->notifications
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Notifications of the token's payments (mode of the key), newest first.
     *
     * @return Page<Notification>
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function list(
        ?string $paymentId = null,
        NotificationStatus|string|null $status = null,
        ?int $perPage = null,
        ?int $page = null,
    ): Page {
        $response = $this->transport->request('GET', '/notifications', RetryMode::Safe, query: [
            'payment_id' => $paymentId === null ? null : Input::id($paymentId, 'paymentId'),
            'status' => Input::notificationStatus($status),
            'per_page' => Input::perPage($perPage),
            'page' => Input::page($page),
        ]);

        return Page::fromResponse($response->body, Notification::fromArray(...));
    }

    /**
     * Resends a notification: a new notification (same event, same URL,
     * up-to-date payment) is created and delivered; the previous attempts
     * are kept. Never retried automatically (a replay would send twice).
     *
     * @throws \Labyrinthe\LabPay\Exception\NotFoundException NOTIFICATION_NOT_FOUND
     * @throws ApiException
     * @throws ConnectionException
     */
    public function resend(int $id): Notification
    {
        $response = $this->transport->request('POST', '/notifications/'.$id.'/resend', RetryMode::OnlyWhenThrottled);

        return Notification::fromArray($response->data());
    }
}
