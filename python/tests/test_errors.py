from __future__ import annotations

from typing import Optional, Type

import httpx
import pytest

from labpay import (
    AmountOutOfRangeError,
    ApiError,
    APITimeoutError,
    AuthenticationError,
    ConflictError,
    DailyLimitReachedError,
    InsufficientBalanceError,
    InvalidPhoneNumberError,
    InvalidRequestError,
    LabPayError,
    NotFoundError,
    PermissionDeniedError,
    RateLimitError,
    ServiceUnavailableError,
    UnexpectedResponseError,
    ValidationError,
)

from .helpers import json_response, make_client, payment


@pytest.mark.parametrize(
    ("status", "code", "error_class"),
    [
        (401, "UNAUTHENTICATED", AuthenticationError),
        (403, "KYC_REQUIRED", PermissionDeniedError),
        (403, "LIVE_TOKEN_REQUIRED", PermissionDeniedError),
        (404, "PAYMENT_NOT_FOUND", NotFoundError),
        (409, "REFUND_IN_PROGRESS", ConflictError),
        (422, "INVALID_PHONE_NUMBER", InvalidPhoneNumberError),
        (422, "INVALID_CURRENCY", InvalidRequestError),
        (422, "AMOUNT_TOO_LARGE", AmountOutOfRangeError),
        (422, "INSUFFICIENT_BALANCE", InsufficientBalanceError),
        (429, "DAILY_LIMIT_REACHED", DailyLimitReachedError),
        (503, "MAINTENANCE", ServiceUnavailableError),
        (418, "SOMETHING_NEW", ApiError),
    ],
)
def test_errors_are_typed(status: int, code: str, error_class: Type[ApiError]) -> None:
    client, _ = make_client(json_response(status, {"message": "Message en français", "code": code}), max_retries=0)

    with pytest.raises(LabPayError) as info:
        client.payments.get("DEP-1")

    error = info.value
    assert type(error) is error_class
    assert isinstance(error, ApiError)
    assert (error.code, error.status, error.message, error.mode) == (code, status, "Message en français", "test")


def test_validation_errors() -> None:
    body = {"message": "Montant invalide", "code": "VALIDATION_ERROR", "errors": {"amount": ["Montant invalide"]}}
    client, _ = make_client(json_response(422, body))

    with pytest.raises(ValidationError) as info:
        client.deposits.create(amount="10", currency="CDF", phone="0813456789")
    assert info.value.errors == {"amount": ["Montant invalide"]}


def test_filter_errors_without_code_are_validation_errors() -> None:
    client, _ = make_client(json_response(422, {"message": "Invalid", "errors": {"status": ["Invalid"]}}))

    with pytest.raises(ValidationError):
        client.notifications.list()


def test_invalid_json() -> None:
    client, _ = make_client(httpx.Response(200, text="not json"))

    with pytest.raises(UnexpectedResponseError):
        client.account.get()


def test_rate_limit_is_retried_after_retry_after() -> None:
    client, http = make_client(
        json_response(429, {"message": "Too Many Attempts."}, {"Retry-After": "3"}),
        json_response(200, {"data": payment()}),
    )

    client.payments.get("DEP-1")

    assert len(http.requests) == 2
    assert http.sleeps == [3.0]


def test_rate_limit_longer_than_max_wait_is_raised() -> None:
    client, http = make_client(json_response(429, {"message": "Too Many Attempts."}, {"Retry-After": "60"}))

    with pytest.raises(RateLimitError) as info:
        client.payments.get("DEP-1")
    assert (info.value.code, info.value.retry_after) == ("TOO_MANY_REQUESTS", 60)
    assert len(http.requests) == 1


def test_daily_limit_is_never_retried() -> None:
    client, http = make_client(json_response(429, {"message": "Limite", "code": "DAILY_LIMIT_REACHED"}, {"Retry-After": "1"}))

    with pytest.raises(DailyLimitReachedError):
        client.deposits.create(amount="10", currency="CDF", phone="0813456789")
    assert len(http.requests) == 1


def test_server_errors_on_reads_are_retried() -> None:
    client, http = make_client(
        json_response(503, {"message": "Indisponible", "code": "GATEWAY_UNAVAILABLE"}),
        httpx.Response(502, text="<html>"),
        json_response(200, {"data": []}),
    )

    assert client.providers.list() == []
    assert len(http.requests) == 3
    assert len(http.sleeps) == 2


@pytest.mark.parametrize(("retry_after", "attempts"), [(None, 1), ("2", 2)])
def test_maintenance_is_only_retried_with_retry_after(retry_after: Optional[str], attempts: int) -> None:
    headers = {} if retry_after is None else {"Retry-After": retry_after}
    client, http = make_client(
        json_response(503, {"message": "Maintenance", "code": "MAINTENANCE"}, headers),
        json_response(503, {"message": "Maintenance", "code": "MAINTENANCE"}),
    )

    with pytest.raises(ServiceUnavailableError):
        client.payments.get("DEP-1")
    assert len(http.requests) == attempts


def test_rotations_are_never_retried_after_a_server_error() -> None:
    client, http = make_client(httpx.Response(500), api_key="live_token_abcdefgh")

    with pytest.raises(ServiceUnavailableError):
        client.account.rotate_webhook_secret()
    assert len(http.requests) == 1


def test_timeout() -> None:
    client, http = make_client(httpx.ReadTimeout("timed out"), httpx.ReadTimeout("timed out"), httpx.ReadTimeout("timed out"))

    with pytest.raises(APITimeoutError) as info:
        client.payments.get("DEP-1")
    assert info.value.code == "TIMEOUT"
    assert len(http.requests) == 3
