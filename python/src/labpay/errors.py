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


