from __future__ import annotations

import datetime as dt
import re
from decimal import Decimal
from typing import Any, Callable

import httpx
import pytest

from labpay import APIConnectionError, InvalidArgumentError, LabPay, Operation, PaymentStatus

from .helpers import json_response, make_client, payment


def test_deposit_sends_the_documented_body_and_headers() -> None:
    client, http = make_client(json_response(201, {"data": payment()}))

    result = client.deposits.create(
        amount=1000,
        currency="cdf",
        phone="0813456789",
        reference="ORDER-42",
        callback_url="https://boutique.example/webhooks/labpay",
        customer_message="Commande 42",
        metadata={"orderId": 42},
    )

    request = http.last
    assert request.method == "POST"
    assert str(request.url) == "https://api.example.test/api/V2/deposits"
    assert request.headers["authorization"] == "Bearer test_abcdefghijklmnop"
    assert request.headers["content-type"] == "application/json"
    assert request.headers["user-agent"].startswith("labpay-python/")
    assert http.body() == {
        "amount": "1000",
        "currency": "CDF",
        "phone": "0813456789",
        "reference": "ORDER-42",
        "callback_url": "https://boutique.example/webhooks/labpay",
        "customer_message": "Commande 42",
        "metadata": {"orderId": "42"},
    }
    assert result.id == "DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6"
    assert result.operation is Operation.DEPOSIT
    assert result.status is PaymentStatus.PENDING
    assert result.total_amount == "1035.00"
    assert result.is_test and not result.is_final
    assert client.last_response is not None and client.last_response.mode == "test"


def test_a_reference_is_generated_and_kept_across_retries() -> None:
    client, http = make_client(httpx.Response(500), json_response(201, {"data": payment()}))

    client.deposits.create(amount=Decimal("12.50"), currency="USD", phone="+243813456789")

    assert len(http.requests) == 2
    first = http.body(0)
    assert re.fullmatch(r"sdk_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", first["reference"])
    assert first["amount"] == "12.50"
    assert http.body(1) == first


def test_a_payment_without_reference_is_not_retried_after_a_network_error() -> None:
    client, http = make_client(httpx.ConnectError("boom ?phone=0813456789"), auto_reference=False)

    with pytest.raises(APIConnectionError) as info:
        client.payouts.create(amount="5000", currency="CDF", phone="0813456789")

    assert len(http.requests) == 1
    assert "reference" not in http.body()
    assert str(http.last.url) == "https://api.example.test/api/V2/payouts"
    assert "may have been processed" in info.value.message
    assert "0813456789" not in info.value.message


def test_a_payment_refused_at_initiation_is_returned() -> None:
    refused = payment(status="failed", failure={"code": "PAYER_LIMIT_REACHED", "message": "Limite"})
    client, _ = make_client(json_response(422, {"data": refused}))

    result = client.deposits.create(amount="1000", currency="CDF", phone="243813456019")

    assert result.is_failed
    assert result.failure is not None and result.failure.code == "PAYER_LIMIT_REACHED"
    assert client.last_response is not None and client.last_response.status == 422


def test_refund() -> None:
    client, http = make_client(json_response(201, {"data": payment(id="RFD-1", operation="refund", refunded_payment_id="DEP-1")}))

    refund = client.refunds.create(payment_id="DEP-1", amount="300", reference="REFUND-42-1")

    assert http.body() == {"payment_id": "DEP-1", "amount": "300", "reference": "REFUND-42-1"}
    assert refund.refunded_payment_id == "DEP-1"


def test_get_sync_and_cancel() -> None:
    client, http = make_client(
        json_response(200, {"data": payment(id="PAY-1", operation="payout", gateway_status="ENQUEUED")}),
        json_response(200, {"data": payment(id="PAY-1", operation="payout")}),
        json_response(200, {"data": payment(status="completed", completed_at="2026-11-11T10:00:42+00:00")}),
    )

    assert client.payments.get("PAY-1").is_queued
    assert str(http.last.url) == "https://api.example.test/api/V2/payments/PAY-1"
    client.payouts.cancel("PAY-1")
    assert str(http.last.url) == "https://api.example.test/api/V2/payouts/PAY-1/cancel"
    synced = client.payments.sync("DEP-1")
    assert (http.last.method, str(http.last.url)) == ("POST", "https://api.example.test/api/V2/payments/DEP-1/sync")
    assert synced.is_completed and synced.completed_at == "2026-11-11T10:00:42+00:00"


def test_list_and_iterate() -> None:
    meta = {"current_page": 1, "last_page": 2, "per_page": 1, "total": 2}
    client, http = make_client(
        json_response(200, {"data": [payment()], "links": {}, "meta": meta}),
        json_response(200, {"data": [payment(id="DEP-2")], "links": {}, "meta": {**meta, "current_page": 2}}),
    )

    ids = [p.id for p in client.payments.iterate(status=PaymentStatus.COMPLETED, operation="deposit", from_date=dt.date(2026, 11, 1), per_page=1)]

    assert ids == ["DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6", "DEP-2"]
    assert http.last.url.query.decode() == "status=completed&operation=deposit&from=2026-11-01&per_page=1&page=2"


def test_find_by_reference() -> None:
    client, http = make_client(json_response(200, {"data": [], "meta": {"current_page": 1, "last_page": 1, "per_page": 1, "total": 0}}))

    assert client.payments.find_by_reference("ORDER-404") is None
    assert http.last.url.query.decode() == "reference=ORDER-404&per_page=1"


@pytest.mark.parametrize(
    "call",
    [
        lambda c: c.deposits.create(amount="0", currency="CDF", phone="0813456789"),
        lambda c: c.deposits.create(amount="1.234", currency="CDF", phone="0813456789"),
        lambda c: c.deposits.create(amount=12.5, currency="CDF", phone="0813456789"),
        lambda c: c.deposits.create(amount="10", currency="FC", phone="0813456789"),
        lambda c: c.deposits.create(amount="10", currency="CDF", phone="0813456789", reference="order 42"),
        lambda c: c.deposits.create(amount="10", currency="CDF", phone="0813456789", callback_url="http://example.test"),
        lambda c: c.deposits.create(amount="10", currency="CDF", phone="0813456789", customer_message="Hi"),
        lambda c: c.deposits.create(amount="10", currency="CDF", phone="0813456789", metadata={str(i): "x" for i in range(21)}),
        lambda c: c.payments.list(per_page=101),
        lambda c: c.payments.list(status="done"),
        lambda c: c.payments.list(from_date="01/11/2026"),
    ],
)
def test_invalid_input_fails_before_any_request(call: Callable[[LabPay], Any]) -> None:
    client, http = make_client()

    with pytest.raises(InvalidArgumentError):
        call(client)
    assert http.requests == []
