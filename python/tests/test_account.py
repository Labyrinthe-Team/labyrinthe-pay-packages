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

    (country,) = client.providers.countries()
    assert country.is_default and country.dial_code == "243"
    assert country.limits["USD"].min_amount is None and country.limits["CDF"].max_amount == "20000000"

    (provider,) = client.providers.list(operation="payout", country="cod")
    assert http.last.url.query.decode() == "operation=payout&country=COD"
    assert provider.currencies["CDF"].decimals == "NONE"

    availability = client.providers.availability(country="COD")
    assert availability["VODACOM_MPESA_COD"].is_operational("deposit")
    assert availability["VODACOM_MPESA_COD"].status("PAYOUT") == "DELAYED"
    assert client.providers.availability() == {}

    prediction = client.providers.predict("0813456789", country="COD")
    assert http.body() == {"phone": "0813456789", "country": "COD"}
    assert prediction.phone == "243813456789"


def test_notifications() -> None:
    notification = {
        "id": 12, "event": "payment.completed", "payment_id": "DEP-1", "url": "https://boutique.example/webhooks/labpay",
        "status": "abandoned", "attempts": 6, "last_status_code": 500, "last_error": "HTTP 500",
        "next_attempt_at": None, "delivered_at": None, "created_at": "2026-11-11T10:00:43+00:00",
    }
    client, http = make_client(
        json_response(200, {"data": [notification], "meta": {"current_page": 1, "last_page": 1, "per_page": 20, "total": 1}}),
        json_response(202, {"data": {**notification, "id": 13, "status": "pending", "attempts": 0}}),
    )

    page = client.notifications.list(payment_id="DEP-1", status=NotificationStatus.ABANDONED)
    assert http.last.url.query.decode() == "payment_id=DEP-1&status=abandoned"
    assert len(page) == 1 and not page.has_more
    assert page.data[0].event is WebhookEventType.PAYMENT_COMPLETED and page.data[0].last_status_code == 500

    resent = client.notifications.resend(12)
    assert str(http.last.url).endswith("/V2/notifications/12/resend")
    assert resent.id == 13 and resent.status is NotificationStatus.PENDING


def test_base_url_and_mode() -> None:
    assert LabPay("test_x").base_url == "https://api.labyrinthe-rdc.com/api/V2"
    assert LabPay("test_x", base_url="http://localhost:8000").base_url == "http://localhost:8000/api/V2"
    assert LabPay("test_x", base_url="https://pay.example/api/V2/").base_url == "https://pay.example/api/V2"
    assert LabPay("test_x").is_test_mode
    assert not LabPay("live").is_test_mode


def test_the_key_never_leaks(monkeypatch: pytest.MonkeyPatch) -> None:
    client = LabPay("live_secret_token_value")
    assert "live_secret_token_value" not in repr(client)
    with pytest.raises(TypeError):
        pickle.dumps(client)
    monkeypatch.delenv("LABPAY_API_KEY", raising=False)
    with pytest.raises(InvalidArgumentError):
        LabPay(" ")
