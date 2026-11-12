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
    per_page: int
    total: int

    @property
    def has_more(self) -> bool:
        return self.current_page < self.last_page

    def __iter__(self) -> Any:
        return iter(self.data)

    def __len__(self) -> int:
        return len(self.data)

    @classmethod
    def from_api(cls, body: Any, parse: Callable[[Any], T]) -> Page[T]:
        data = _obj(body, "body")
        items = [parse(item) for item in _list(data.get("data"), "data")]
        meta = _obj(data.get("meta") or {}, "meta")
        total = _nint(meta, "total")
        return cls(
            data=items,
            current_page=_nint(meta, "current_page") or 1,
            last_page=_nint(meta, "last_page") or 1,
            per_page=_nint(meta, "per_page") or len(items),
            total=len(items) if total is None else total,
        )


@dataclass(frozen=True)
class Balance:
    currency: str
    #: String with 2 decimals, e.g. ``"125000.00"``.
    balance: str
    updated_at: Optional[str]

    @classmethod
    def from_api(cls, value: Any) -> Balance:
        data = _obj(value, "balance")
        return cls(_str(data, "currency"), _str(data, "balance"), _nstr(data, "updated_at"))


@dataclass(frozen=True)
class Account:
    mode: Mode
    merchant: Optional[str]
    #: True once the token is enabled for production; otherwise live collections have a daily limit.
    live_enabled: bool
    kyc_verified: bool
    #: Fee rates between 0 and 1 (e.g. ``"0.0350"`` = 3.5 %).
    deposit_rate: Optional[str]
    payout_rate: Optional[str]
    test_key: Optional[str] = field(repr=False)
    #: Signing secret (``whsec_…``). Live token only: ``None`` with the test key.
    webhook_secret: Optional[str] = field(repr=False)
    webhook_secret_previous_expires_at: Optional[str]

    @classmethod
    def from_api(cls, value: Any) -> Account:
        data = _obj(value, "account")
        fees = _obj(data.get("fees") or {}, "fees")
        return cls(
            mode=Mode(data.get("mode")),
            merchant=_nstr(data, "merchant"),
            live_enabled=_bool(data, "live_enabled"),
            kyc_verified=_bool(data, "kyc_verified"),
            deposit_rate=_nstr(fees, "deposit_rate"),
            payout_rate=_nstr(fees, "payout_rate"),
            test_key=_nstr(data, "test_key"),
            webhook_secret=_nstr(data, "webhook_secret"),
            webhook_secret_previous_expires_at=_nstr(data, "webhook_secret_previous_expires_at"),
        )


@dataclass(frozen=True)
class WebhookSecret:
    secret: str = field(repr=False)
    previous_expires_at: Optional[str]

    @classmethod
    def from_api(cls, value: Any) -> WebhookSecret:
        data = _obj(value, "data")
        return cls(_str(data, "webhook_secret"), _nstr(data, "webhook_secret_previous_expires_at"))


@dataclass(frozen=True)
class AmountLimits:
    min_amount: Optional[str]
    max_amount: Optional[str]

    @classmethod
    def from_api(cls, value: Any) -> AmountLimits:
        data = value if isinstance(value, dict) else {}
        return cls(_nstr(data, "min_amount"), _nstr(data, "max_amount"))


@dataclass(frozen=True)
class Country:
    country: str
    name: Optional[str]
    dial_code: Optional[str]
    currencies: List[str]
    #: Per currency.
    limits: Dict[str, AmountLimits]
    #: Country of a local number sent without ``country``.
    is_default: bool

    @classmethod
    def from_api(cls, value: Any) -> Country:
        data = _obj(value, "country")
        limits = {str(k): AmountLimits.from_api(v) for k, v in _obj(data.get("limits") or {}, "limits").items()}
        currencies = [c for c in data.get("currencies") or [] if isinstance(c, str)]
        return cls(
            country=_str(data, "country"),
            name=_nstr(data, "name"),
            dial_code=_nstr(data, "dial_code"),
            currencies=currencies,
            limits=limits,
            is_default=_bool(data, "default"),
        )


@dataclass(frozen=True)
class ProviderCurrency:
    min_amount: Optional[str]
    max_amount: Optional[str]
    #: Decimals accepted in the amount (e.g. ``"NONE"``: whole amount only).
    decimals: Optional[str]
    status: Optional[str]
    pin_prompt: Any
    pin_prompt_revivable: Any
    pin_prompt_instructions: Any


@dataclass(frozen=True)
class Provider:
    #: Provider code, to pass as ``provider``.
    provider: str
    country: Optional[str]
    name: Optional[str]
    name_displayed_to_customer: Optional[str]
    logo: Optional[str]
    currencies: Dict[str, ProviderCurrency]

    @classmethod
    def from_api(cls, value: Any) -> Provider:
        data = _obj(value, "provider")
        currencies: Dict[str, ProviderCurrency] = {}
        for currency, rules in _obj(data.get("currencies") or {}, "currencies").items():
            r = rules if isinstance(rules, dict) else {}
            currencies[str(currency)] = ProviderCurrency(
                min_amount=_nstr(r, "min_amount"),
                max_amount=_nstr(r, "max_amount"),
                decimals=_nstr(r, "decimals"),
                status=_nstr(r, "status"),
                pin_prompt=r.get("pin_prompt"),
                pin_prompt_revivable=r.get("pin_prompt_revivable"),
                pin_prompt_instructions=r.get("pin_prompt_instructions"),
            )
        return cls(
            provider=_str(data, "provider"),
            country=_nstr(data, "country"),
            name=_nstr(data, "name"),
            name_displayed_to_customer=_nstr(data, "name_displayed_to_customer"),
            logo=_nstr(data, "logo"),
            currencies=currencies,
        )


@dataclass(frozen=True)
class ProviderAvailability:
    """Status per operation type (``DEPOSIT``, ``PAYOUT``…): ``OPERATIONAL``, ``DELAYED`` or ``CLOSED``."""

    provider: str
    country: Optional[str]
    statuses: Dict[str, Optional[str]]

    def status(self, operation_type: str) -> Optional[str]:
        return self.statuses.get(operation_type.upper())

    def is_operational(self, operation_type: str) -> bool:
        return self.status(operation_type) == "OPERATIONAL"

    @classmethod
    def from_api(cls, provider: str, value: Any) -> ProviderAvailability:
        data = value if isinstance(value, dict) else {}
        statuses = {str(k): (v if isinstance(v, str) else None) for k, v in data.items() if k != "country"}
        return cls(provider, _nstr(data, "country"), statuses)


@dataclass(frozen=True)
class ProviderPrediction:
    #: International format, without "+", e.g. ``"243813456789"``.
    phone: str
    provider: str
    country: str

    @classmethod
    def from_api(cls, value: Any) -> ProviderPrediction:
        data = _obj(value, "data")
        return cls(_str(data, "phone"), _str(data, "provider"), _str(data, "country"))


@dataclass(frozen=True)
class Notification:
    #: Also the X-Labyrinthe-Delivery header of the delivery.
    id: int
    event: WebhookEventType
    payment_id: Optional[str]
    url: Optional[str]
    status: NotificationStatus
    attempts: int
    last_status_code: Optional[int]
    last_error: Optional[str]
    next_attempt_at: Optional[str]
    delivered_at: Optional[str]
    created_at: Optional[str]

    @classmethod
    def from_api(cls, value: Any) -> Notification:
        data = _obj(value, "notification")
        notification_id = _nint(data, "id")
        if notification_id is None:
            raise UnexpectedResponseError('Unexpected API payload: "id" should be an integer.')
        return cls(
            id=notification_id,
            event=WebhookEventType(data.get("event")),
            payment_id=_nstr(data, "payment_id"),
            url=_nstr(data, "url"),
            status=NotificationStatus(data.get("status")),
            attempts=_nint(data, "attempts") or 0,
            last_status_code=_nint(data, "last_status_code"),
            last_error=_nstr(data, "last_error"),
            next_attempt_at=_nstr(data, "next_attempt_at"),
            delivered_at=_nstr(data, "delivered_at"),
            created_at=_nstr(data, "created_at"),
        )
