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
