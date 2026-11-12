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
