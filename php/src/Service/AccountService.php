<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Internal\Data;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Model\Account;
use Labyrinthe\LabPay\Model\Balance;
use Labyrinthe\LabPay\Model\WebhookSecret;

/**
 * The merchant account, keys and balances (`$client->account`).
 */
final class AccountService
{
    /**
     * @internal use $client->account
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Mode, fee rates, test key and (live token only) the signing secret.
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function get(): Account
    {
        return Account::fromArray($this->transport->request('GET', '/account', RetryMode::Safe)->data());
    }

    /**
     * One balance per currency, in the mode of the key (test balances with the test key).
     *
     * @return list<Balance>
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function balances(): array
    {
        $balances = [];
        foreach (Data::array($this->transport->request('GET', '/balances', RetryMode::Safe)->body, 'data') as $item) {
            if (\is_array($item)) {
                $balances[] = Balance::fromArray($item);
            }
        }

        return $balances;
    }

    /**
     * The balance of one currency, null when the account has none.
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function balance(string $currency): ?Balance
    {
        foreach ($this->balances() as $balance) {
            if (strcasecmp($balance->currency, $currency) === 0) {
                return $balance;
            }
        }

        return null;
    }

    /**
     * New notification signing secret; the previous one stays accepted 24 h.
     * Live token only. Never retried automatically (a replay would rotate twice).
     *
     * @throws \Labyrinthe\LabPay\Exception\PermissionException LIVE_TOKEN_REQUIRED
     * @throws ApiException
     * @throws ConnectionException
     */
    public function rotateWebhookSecret(): WebhookSecret
    {
        return WebhookSecret::fromArray($this->transport->request('POST', '/account/webhook-secret/rotate', RetryMode::OnlyWhenThrottled)->data());
    }

    /**
     * New test key, returned; the previous one stops working immediately.
     * Live token only. Never retried automatically.
     *
     * @throws \Labyrinthe\LabPay\Exception\PermissionException LIVE_TOKEN_REQUIRED
     * @throws ApiException
     * @throws ConnectionException
     */
    public function rotateTestKey(): string
    {
        return Data::string($this->transport->request('POST', '/account/test-key/rotate', RetryMode::OnlyWhenThrottled)->data(), 'test_key');
    }
}
