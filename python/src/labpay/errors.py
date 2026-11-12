"""Erreurs typées du SDK.

Toujours se fier à ``code`` (stable), jamais à ``message`` (rédigé en français
par l'API, pour des humains, et susceptible de changer).
"""

from __future__ import annotations

from typing import Any, Dict, List, Mapping, Optional, Type

__all__ = [
    "LabPayError",
    "ApiError",
    "AuthenticationError",
    "PermissionDeniedError",
    "NotFoundError",
    "ConflictError",
    "InvalidRequestError",
    "ValidationError",
    "InvalidPhoneNumberError",
    "AmountOutOfRangeError",
    "InsufficientBalanceError",
    "RateLimitError",
    "DailyLimitReachedError",
    "ServiceUnavailableError",
    "APIConnectionError",
    "APITimeoutError",
    "InvalidArgumentError",
    "UnexpectedResponseError",
    "SignatureVerificationError",
    "InvalidWebhookPayloadError",
    "error_from_response",
]


class LabPayError(Exception):
    """Base of every error raised by the SDK: catch it to handle any LabPay failure in one place."""

    def __init__(self, message: str, code: str, status: Optional[int] = None) -> None:
        super().__init__(message)
        self.message = message
        #: Stable machine-readable code, e.g. ``"INSUFFICIENT_BALANCE"`` or ``"TIMEOUT"``.
        self.code = code
        #: HTTP status of the response; ``None`` when there was no response.
        self.status = status


class ApiError(LabPayError):
    """The API answered with an error (``{"message", "code", "errors"?}``).

    Any code without a dedicated subclass (including codes added later) is
    raised as a plain ``ApiError``.
    """

    status: int

    def __init__(
        self,
        message: str,
        code: str,
        status: int,
        errors: Optional[Dict[str, List[str]]] = None,
        headers: Optional[Mapping[str, str]] = None,
    ) -> None:
        super().__init__(message, code, status)
        self.status = status
        #: Per-field messages of a validation error, ``{}`` otherwise.
        self.errors: Dict[str, List[str]] = errors or {}
        #: Response headers (lower-case names).
        self.headers: Dict[str, str] = {k.lower(): v for k, v in (headers or {}).items()}

    @property
    def mode(self) -> Optional[str]:
        """``"live"`` or ``"test"`` (X-Labyrinthe-Mode), when the API sent it."""
        return self.headers.get("x-labyrinthe-mode")

    @property
    def retry_after(self) -> Optional[int]:
        """Seconds from the Retry-After header, ``None`` when the API does not say."""
        value = self.headers.get("retry-after", "").strip()
        return int(value) if value.isdigit() else None


class AuthenticationError(ApiError):
    """401 ``UNAUTHENTICATED``: missing or unknown API token / test key."""


class PermissionDeniedError(ApiError):
    """403: ``TOKEN_BLOCKED``, ``MERCHANT_INACTIVE``, ``KYC_REQUIRED`` or ``LIVE_TOKEN_REQUIRED``."""


class NotFoundError(ApiError):
    """404: ``PAYMENT_NOT_FOUND``, ``DEPOSIT_NOT_FOUND`` or ``NOTIFICATION_NOT_FOUND``."""


class ConflictError(ApiError):
    """409: ``NOT_A_PAYOUT``, ``NOT_CANCELLABLE``, ``DEPOSIT_NOT_COMPLETED``, ``REFUND_IN_PROGRESS``, ``DEPOSIT_ALREADY_REFUNDED``."""


class InvalidRequestError(ApiError):
    """422 without a payment: refused before any payment was created.

    A payment refused by the provider is not an error: it is returned with the
    ``failed`` status.
    """


class ValidationError(InvalidRequestError):
    """422 ``VALIDATION_ERROR`` (or 422 without code on invalid filters): see ``errors``."""


class InvalidPhoneNumberError(InvalidRequestError):
    """422 ``INVALID_PHONE_NUMBER``."""


class AmountOutOfRangeError(InvalidRequestError):
    """422 ``AMOUNT_TOO_SMALL``, ``AMOUNT_TOO_LARGE`` or ``AMOUNT_OUT_OF_BOUNDS``: the message gives the limit."""


class InsufficientBalanceError(InvalidRequestError):
    """422 ``INSUFFICIENT_BALANCE``: nothing was sent."""


class RateLimitError(ApiError):
    """429: too many requests for this token (120 per minute); see ``retry_after``."""


class DailyLimitReachedError(RateLimitError):
    """429 ``DAILY_LIMIT_REACHED``: token not enabled for production yet. Never retried automatically."""


class ServiceUnavailableError(ApiError):
    """5xx: maintenance, operation disabled, provider closed, aggregator unavailable, server error."""


class APIConnectionError(LabPayError):
    """The API could not be reached (``CONNECTION_ERROR``).

    For a deposit, payout or refund this does NOT mean the payment failed: it
    may have been created. Resend with the same ``reference``.
    """


class APITimeoutError(APIConnectionError):
    """The API did not answer in time (``TIMEOUT``). The request may have been processed."""


class InvalidArgumentError(LabPayError, ValueError):
    """Invalid input, detected by the SDK before any request (``INVALID_ARGUMENT``)."""

    def __init__(self, message: str) -> None:
        super().__init__(message, "INVALID_ARGUMENT")


class UnexpectedResponseError(LabPayError):
    """The API answered something the SDK cannot read (``UNEXPECTED_RESPONSE``)."""

    def __init__(self, message: str, status: Optional[int] = None) -> None:
        super().__init__(message, "UNEXPECTED_RESPONSE", status)


class SignatureVerificationError(LabPayError):
    """Wrong or missing notification signature, or timestamp out of tolerance: answer 400."""

    def __init__(self, message: str) -> None:
        super().__init__(message, "SIGNATURE_VERIFICATION_FAILED")


class InvalidWebhookPayloadError(LabPayError):
    """Correctly signed body that is not a valid notification: answer 400."""

    def __init__(self, message: str) -> None:
        super().__init__(message, "INVALID_WEBHOOK_PAYLOAD")


_BY_CODE: Dict[str, Type[ApiError]] = {
    "UNAUTHENTICATED": AuthenticationError,
    "TOKEN_BLOCKED": PermissionDeniedError,
    "MERCHANT_INACTIVE": PermissionDeniedError,
    "KYC_REQUIRED": PermissionDeniedError,
    "LIVE_TOKEN_REQUIRED": PermissionDeniedError,
    "PAYMENT_NOT_FOUND": NotFoundError,
    "DEPOSIT_NOT_FOUND": NotFoundError,
    "NOTIFICATION_NOT_FOUND": NotFoundError,
    "NOT_A_PAYOUT": ConflictError,
    "NOT_CANCELLABLE": ConflictError,
    "DEPOSIT_NOT_COMPLETED": ConflictError,
    "REFUND_IN_PROGRESS": ConflictError,
    "DEPOSIT_ALREADY_REFUNDED": ConflictError,
    "VALIDATION_ERROR": ValidationError,
    "INVALID_PHONE_NUMBER": InvalidPhoneNumberError,
    "UNSUPPORTED_COUNTRY": InvalidRequestError,
    "INVALID_CURRENCY": InvalidRequestError,
    "UNSUPPORTED_PROVIDER": InvalidRequestError,
    "INVALID_AMOUNT": InvalidRequestError,
    "AMOUNT_TOO_SMALL": AmountOutOfRangeError,
    "AMOUNT_TOO_LARGE": AmountOutOfRangeError,
    "AMOUNT_OUT_OF_BOUNDS": AmountOutOfRangeError,
    "INSUFFICIENT_BALANCE": InsufficientBalanceError,
    "DAILY_LIMIT_REACHED": DailyLimitReachedError,
    "MAINTENANCE": ServiceUnavailableError,
    "API_DISABLED": ServiceUnavailableError,
    "DEPOSITS_DISABLED": ServiceUnavailableError,
    "WITHDRAWALS_DISABLED": ServiceUnavailableError,
    "PROVIDER_TEMPORARILY_UNAVAILABLE": ServiceUnavailableError,
    "GATEWAY_UNAVAILABLE": ServiceUnavailableError,
    "V2_DISABLED": ServiceUnavailableError,
    "TEST_MODE_DISABLED": ServiceUnavailableError,
}


def _class_for_status(status: int) -> Type[ApiError]:
    if status == 401:
        return AuthenticationError
    if status == 403:
        return PermissionDeniedError
    if status == 404:
        return NotFoundError
    if status == 409:
        return ConflictError
    if status == 422:
        return InvalidRequestError
    if status == 429:
        return RateLimitError
    if status >= 500:
        return ServiceUnavailableError
    return ApiError


def _field_errors(value: Any) -> Dict[str, List[str]]:
    if not isinstance(value, dict):
        return {}
    result: Dict[str, List[str]] = {}
    for field, messages in value.items():
        items = messages if isinstance(messages, list) else [messages]
        result[str(field)] = [m for m in items if isinstance(m, str)]
    return result


def error_from_response(status: int, body: Any, headers: Mapping[str, str]) -> ApiError:
    """Maps an error response to a typed error: by code, then by HTTP status. Internal use."""
    data = body if isinstance(body, dict) else {}
    errors = _field_errors(data.get("errors"))
    code = data.get("code") if isinstance(data.get("code"), str) and data.get("code") else None
    if code is None:
        if status == 429:
            code = "TOO_MANY_REQUESTS"
        elif status == 422 and errors:
            code = "VALIDATION_ERROR"
        else:
            code = "HTTP_ERROR"
    message = data.get("message") if isinstance(data.get("message"), str) and data.get("message") else None
    error_class = _BY_CODE.get(code) or _class_for_status(status)
    return error_class(message or f"The LabPay API returned HTTP {status}.", code, status, errors, headers)
