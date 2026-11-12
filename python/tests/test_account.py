from __future__ import annotations

import pickle

import pytest

from labpay import InvalidArgumentError, LabPay, Mode, NotificationStatus, WebhookEventType

from .helpers import json_response, make_client


def test_account_balances_and_rotations() -> None:
    client, http = make_client(
        json_response(200, {"data": {
            "mode": "live", "merchant": "Boutique", "live_enabled": True, "kyc_verified": True,
            "fees": {"deposit_rate": "0.0350", "payout_rate": "0.0350"}, "test_key": "test_abc", "webhook_secret": "whsec_secret",
        }}),
        json_response(200, {"data": [
            {"currency": "CDF", "balance": "125000.00", "updated_at": "2026-11-11T10:00:42+00:00"},
            {"currency": "USD", "balance": "0.00", "updated_at": None},
        ]}),
        json_response(200, {"data": {"webhook_secret": "whsec_new", "webhook_secret_previous_expires_at": "2026-11-12T10:00:00+00:00"}}),
        json_response(200, {"data": {"test_key": "test_new"}}),
        api_key="live_token_abcdefgh",
    )

    account = client.account.get()
    assert account.mode is Mode.LIVE and account.live_enabled and account.deposit_rate == "0.0350"
    assert account.webhook_secret == "whsec_secret"
    assert "whsec_secret" not in repr(account)

    balance = client.account.balance("cdf")
    assert balance is not None and balance.balance == "125000.00"
    assert str(http.last.url) == "https://api.example.test/api/V2/balances"

    assert client.account.rotate_webhook_secret().secret == "whsec_new"
    assert str(http.last.url).endswith("/V2/account/webhook-secret/rotate")
    assert client.account.rotate_test_key() == "test_new"
    assert str(http.last.url).endswith("/V2/account/test-key/rotate")


def test_countries_providers_availability_prediction() -> None:
    client, http = make_client(
        json_response(200, {"data": [{
            "country": "COD", "name": "RD Congo", "dial_code": "243", "currencies": ["CDF", "USD"],
            "limits": {"CDF": {"min_amount": "100", "max_amount": "20000000"}, "USD": {"min_amount": None, "max_amount": "10000"}},
            "default": True,
        }]}),
        json_response(200, {"data": [{
            "provider": "VODACOM_MPESA_COD", "country": "COD", "name": "Vodacom M-Pesa", "name_displayed_to_customer": "M-PESA",
            "logo": None, "currencies": {"CDF": {"min_amount": "100", "max_amount": "2500000", "decimals": "NONE", "status": "OPERATIONAL"}},
        }]}),
        json_response(200, {"data": {"VODACOM_MPESA_COD": {"country": "COD", "DEPOSIT": "OPERATIONAL", "PAYOUT": "DELAYED"}}}),
        json_response(200, {"data": []}),
        json_response(200, {"data": {"phone": "243813456789", "provider": "VODACOM_MPESA_COD", "country": "COD"}}),
    )
