"""Objets renvoyés par l'API V2 (lecture tolérante sur la forme, stricte sur les champs obligatoires)."""

from __future__ import annotations

import enum
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, Generic, List, Optional, TypeVar

from .errors import UnexpectedResponseError

__all__ = [
    "PaymentStatus",
    "Operation",
    "Mode",
    "NotificationStatus",
    "WebhookEventType",
    "PaymentFailure",
    "Payment",
    "Page",
    "Balance",
    "Account",
    "WebhookSecret",
    "AmountLimits",
    "Country",
    "ProviderCurrency",
    "Provider",
    "ProviderAvailability",
    "ProviderPrediction",
    "Notification",
]


class _StrEnum(str, enum.Enum):
    @classmethod
    def _missing_(cls, value: object) -> Any:
        return cls.UNKNOWN  # type: ignore[attr-defined]

    def __str__(self) -> str:
        return str(self.value)


class PaymentStatus(_StrEnum):
    """Public status of a payment. ``completed`` and ``failed`` are final."""

    PENDING = "pending"
    COMPLETED = "completed"
    FAILED = "failed"
    #: Value sent by the API that this SDK version does not know yet.
    UNKNOWN = "unknown"


class Operation(_StrEnum):
    DEPOSIT = "deposit"
    PAYOUT = "payout"
    REFUND = "refund"
    UNKNOWN = "unknown"


class Mode(_StrEnum):
    LIVE = "live"
    TEST = "test"
    UNKNOWN = "unknown"


class NotificationStatus(_StrEnum):
    DELIVERED = "delivered"
    PENDING = "pending"
    ABANDONED = "abandoned"
    UNKNOWN = "unknown"


class WebhookEventType(_StrEnum):
    PAYMENT_COMPLETED = "payment.completed"
    PAYMENT_FAILED = "payment.failed"
    UNKNOWN = "unknown"


Json = Dict[str, Any]


def _obj(value: Any, name: str) -> Json:
    if isinstance(value, dict):
        return value
    if value == []:  # PHP encode un objet vide en []
        return {}
    raise UnexpectedResponseError(f'Unexpected API payload: "{name}" should be an object.')


def _list(value: Any, name: str) -> List[Any]:
    if isinstance(value, list):
        return value
    raise UnexpectedResponseError(f'Unexpected API payload: "{name}" should be an array.')


def _str(data: Json, key: str) -> str:
    value = data.get(key)
    if isinstance(value, str):
        return value
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return str(value)
    raise UnexpectedResponseError(f'Unexpected API payload: "{key}" should be a string.')


def _nstr(data: Json, key: str) -> Optional[str]:
    return None if data.get(key) is None else _str(data, key)


def _nint(data: Json, key: str) -> Optional[int]:
    value = data.get(key)
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value.lstrip("-").isdigit():
        return int(value)
    return None


def _bool(data: Json, key: str, default: bool = False) -> bool:
    value = data.get(key)
    return value if isinstance(value, bool) else default


@dataclass(frozen=True)
class PaymentFailure:
    code: str
    message: Optional[str]


@dataclass(frozen=True)
class Payment:
    """A V2 payment: deposit (``DEP-…``), payout (``PAY-…``) or refund (``RFD-…``).

    Same shape in API responses and notifications. Amounts are strings with
    2 decimals (``"1500.00"``): use :class:`decimal.Decimal` to compute.
    """

    id: str
    reference: Optional[str]
    operation: Operation
    livemode: bool
    status: PaymentStatus
    #: Raw aggregator status, informational (e.g. ACCEPTED, ENQUEUED). Rely on ``status``.
    gateway_status: Optional[str]
    #: Deposit: credited to the merchant. Payout: received by the recipient. Refund: refunded.
    amount: str
    fee: Optional[str]
    #: Deposit: paid by the customer. Payout: debited from the merchant balance.
    total_amount: Optional[str]
    currency: str
    country: Optional[str]
    phone: Optional[str]
    provider: Optional[str]
    provider_transaction_id: Optional[str]
    failure: Optional[PaymentFailure]
    metadata: Optional[Dict[str, Optional[str]]]
    created_at: Optional[str]
    completed_at: Optional[str]
    #: Deposits only: amount already refunded or being refunded.
    refunded_amount: Optional[str]
    #: Refunds only: id of the refunded deposit.
    refunded_payment_id: Optional[str]
    raw: Json = field(repr=False, compare=False)

    @classmethod
    def from_api(cls, value: Any) -> Payment:
        data = _obj(value, "payment")
        failure = None if data.get("failure") is None else _obj(data["failure"], "failure")
        metadata: Optional[Dict[str, Optional[str]]] = None
        if data.get("metadata") is not None:
            metadata = {
                str(k): (str(v).lower() if isinstance(v, bool) else str(v)) if isinstance(v, (str, int, float)) else None
                for k, v in _obj(data["metadata"], "metadata").items()
            }
        return cls(
            id=_str(data, "id"),
            reference=_nstr(data, "reference"),
            operation=Operation(data.get("operation")),
            livemode=_bool(data, "livemode", True),
            status=PaymentStatus(data.get("status")),
            gateway_status=_nstr(data, "gateway_status"),
            amount=_str(data, "amount"),
            fee=_nstr(data, "fee"),
            total_amount=_nstr(data, "total_amount"),
            currency=_str(data, "currency"),
            country=_nstr(data, "country"),
            phone=_nstr(data, "phone"),
            provider=_nstr(data, "provider"),
            provider_transaction_id=_nstr(data, "provider_transaction_id"),
            failure=None if failure is None else PaymentFailure(_str(failure, "code"), _nstr(failure, "message")),
            metadata=metadata,
            created_at=_nstr(data, "created_at"),
            completed_at=_nstr(data, "completed_at"),
            refunded_amount=_nstr(data, "refunded_amount"),
            refunded_payment_id=_nstr(data, "refunded_payment_id"),
            raw=data,
        )

    @property
    def is_pending(self) -> bool:
        return self.status is PaymentStatus.PENDING

    @property
    def is_completed(self) -> bool:
        """Deposit: your balance is credited. Payout / refund: the money has left. Deliver only on this status."""
        return self.status is PaymentStatus.COMPLETED

    @property
    def is_failed(self) -> bool:
        return self.status is PaymentStatus.FAILED

    @property
    def is_final(self) -> bool:
        """``completed`` or ``failed``: the payment will no longer change."""
        return self.is_completed or self.is_failed

    @property
    def is_queued(self) -> bool:
        """A payout queued by the provider (``gateway_status: ENQUEUED``): it can be cancelled."""
        return self.is_pending and self.gateway_status == "ENQUEUED"

    @property
    def is_test(self) -> bool:
        return not self.livemode


T = TypeVar("T")


@dataclass(frozen=True)
class Page(Generic[T]):
    """One page of a paginated list."""

    data: List[T]
    current_page: int
    last_page: int
