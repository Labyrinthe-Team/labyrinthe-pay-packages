"""Ressources de l'API V2 : ``client.deposits``, ``client.payouts``, ``client.refunds``,
``client.payments``, ``client.providers``, ``client.account``, ``client.notifications``."""

from __future__ import annotations

import datetime as _dt
import uuid
from typing import Any, Dict, Iterator, List, Mapping, Optional, Union

from . import _input as check
from ._transport import RetryMode, Transport
from .errors import InvalidArgumentError, UnexpectedResponseError
from .types import (
    Account,
    Balance,
    Country,
    Notification,
    NotificationStatus,
    Operation,
    Page,
    Payment,
    PaymentStatus,
    Provider,
    ProviderAvailability,
    ProviderPrediction,
    WebhookSecret,
)

#: Préfixe des références générées par le SDK pour un paiement envoyé sans référence.
AUTO_REFERENCE_PREFIX = "sdk_"

Metadata = Mapping[str, Any]
DateLike = Union[str, _dt.date, None]


class _Resource:
    def __init__(self, transport: Transport, auto_reference: bool) -> None:
        self._transport = transport
        self._auto_reference = auto_reference

    def _reference(self, value: Optional[str]) -> Optional[str]:
        checked = check.reference(value)
        if checked is None and self._auto_reference:
            return f"{AUTO_REFERENCE_PREFIX}{uuid.uuid4()}"
        return checked

    def _initiate(
        self,
        path: str,
        amount: check.Amount,
        currency: str,
        phone: str,
        country: Optional[str],
        provider: Optional[str],
        reference: Optional[str],
        callback_url: Optional[str],
        customer_message: Optional[str],
        metadata: Optional[Metadata],
    ) -> Payment:
        body = check.compact(
            {
                "amount": check.amount(amount),
                "currency": check.currency(currency),
                "phone": check.phone(phone),
                "country": check.country(country),
                "provider": check.provider(provider),
                "callback_url": check.callback_url(callback_url),
                "customer_message": check.customer_message(customer_message),
                "metadata": check.metadata(metadata),
            }
        )
        ref = self._reference(reference)
        if ref is not None:
            body["reference"] = ref
        # Avec une référence, un renvoi rend le paiement existant : la relance est sûre.
        mode = RetryMode.SAFE if ref is not None else RetryMode.THROTTLED_ONLY
        response = self._transport.request("POST", path, mode, body=body, payment_on_refusal=True)
        return Payment.from_api(response.data())


class Deposits(_Resource):
    """Collect from a customer (``POST /V2/deposits``)."""

    def create(
        self,
        *,
        amount: check.Amount,
        currency: str,
        phone: str,
        country: Optional[str] = None,
        provider: Optional[str] = None,
        reference: Optional[str] = None,
        callback_url: Optional[str] = None,
        customer_message: Optional[str] = None,
        metadata: Optional[Metadata] = None,
    ) -> Payment:
        """Asks the customer to pay: they get a PIN prompt on their phone.

        The returned payment is usually ``pending``; its final status arrives by
        notification on ``callback_url`` (or read it with ``payments.get()``). Only
        deliver an order on ``completed``. A payment refused at initiation is returned
        with the ``failed`` status, not raised. Without ``reference``, one is generated
        (``sdk_<uuid>``) so that the SDK can retry safely.

        :param amount: net amount credited to you, > 0, at most 2 decimals (``"1500"``, ``"12.50"``, ``Decimal``)
        :param currency: ``"CDF"`` or ``"USD"``
        :param phone: local (``"0812345678"``) or international (``"+243812345678"``)
        :raises InvalidArgumentError: before any call when the input is invalid
        :raises APIConnectionError: the payment may have been created: resend with the same reference
        """
        return self._initiate("/deposits", amount, currency, phone, country, provider, reference, callback_url, customer_message, metadata)


class Payouts(_Resource):
    """Send money to a Mobile Money wallet (``POST /V2/payouts``)."""

    def create(
        self,
        *,
        amount: check.Amount,
        currency: str,
        phone: str,
        country: Optional[str] = None,
        provider: Optional[str] = None,
        reference: Optional[str] = None,
        callback_url: Optional[str] = None,
        customer_message: Optional[str] = None,
        metadata: Optional[Metadata] = None,
    ) -> Payment:
        """Sends ``amount`` to the number. Your balance is debited immediately with amount + payout
        fees, and credited back in full if the payout fails. Same rules as ``deposits.create()``."""
        return self._initiate("/payouts", amount, currency, phone, country, provider, reference, callback_url, customer_message, metadata)

    def cancel(self, id: str) -> Payment:  # noqa: A002 - même nom que dans l'API
        """Cancels a payout still queued by the provider (``gateway_status: ENQUEUED``). Asynchronous:
        the payout then moves to ``failed`` and your balance is credited back. Never retried automatically."""
        response = self._transport.request("POST", f"/payouts/{check.path_id(id)}/cancel", RetryMode.THROTTLED_ONLY)
        return Payment.from_api(response.data())


class Refunds(_Resource):
    """Refund a deposit (``POST /V2/refunds``)."""

    def create(
        self,
        *,
        payment_id: str,
        amount: Optional[check.Amount] = None,
        reference: Optional[str] = None,
        callback_url: Optional[str] = None,
        metadata: Optional[Metadata] = None,
    ) -> Payment:
        """Refunds all (``amount=None``) or part of a completed V2 deposit. Fees are not refunded;
        one refund in progress at a time per deposit."""
        body = check.compact(
            {
                "payment_id": check.identifier(payment_id, "payment_id"),
                "amount": None if amount is None else check.amount(amount),
                "callback_url": check.callback_url(callback_url),
                "metadata": check.metadata(metadata),
            }
        )
        ref = self._reference(reference)
        if ref is not None:
            body["reference"] = ref
        mode = RetryMode.SAFE if ref is not None else RetryMode.THROTTLED_ONLY
        response = self._transport.request("POST", "/refunds", mode, body=body, payment_on_refusal=True)
        return Payment.from_api(response.data())


class Payments(_Resource):
    """Track payments: list, get, refresh the status."""

    def get(self, id: str) -> Payment:  # noqa: A002
        """One payment by its LabPay id (``DEP-…``, ``PAY-…``, ``RFD-…``), in the mode of the key."""
        return Payment.from_api(self._transport.request("GET", f"/payments/{check.path_id(id)}", RetryMode.SAFE).data())

    def sync(self, id: str) -> Payment:  # noqa: A002
        """Re-reads the status of a pending payment now and returns it updated. A safety net:
        prefer notifications, never poll in a tight loop."""
        return Payment.from_api(self._transport.request("POST", f"/payments/{check.path_id(id)}/sync", RetryMode.SAFE).data())

    def list(
        self,
        *,
        status: Union[PaymentStatus, str, None] = None,
        operation: Union[Operation, str, None] = None,
        currency: Optional[str] = None,
        country: Optional[str] = None,
        reference: Optional[str] = None,
        from_date: DateLike = None,
        to_date: DateLike = None,
        per_page: Optional[int] = None,
        page: Optional[int] = None,
    ) -> Page[Payment]:
        """V2 payments of the token, newest first, in the mode of the key."""
        query = {
            "status": check.one_of(None if status is None else str(status), ("pending", "completed", "failed"), "status"),
            "operation": check.one_of(None if operation is None else str(operation), ("deposit", "payout", "refund"), "operation"),
            "currency": None if currency is None else check.currency(currency),
            "country": check.country(country),
            "reference": reference,
            "from": check.date(from_date, "from_date"),
            "to": check.date(to_date, "to_date"),
            "per_page": check.per_page(per_page),
            "page": check.page(page),
        }
        response = self._transport.request("GET", "/payments", RetryMode.SAFE, query=query)
        return Page.from_api(response.body, Payment.from_api)

    def iterate(
        self,
        *,
        status: Union[PaymentStatus, str, None] = None,
        operation: Union[Operation, str, None] = None,
        currency: Optional[str] = None,
        country: Optional[str] = None,
        reference: Optional[str] = None,
        from_date: DateLike = None,
        to_date: DateLike = None,
        per_page: int = check.MAX_PER_PAGE,
    ) -> Iterator[Payment]:
        """Every payment matching the filters, fetching the next pages as you iterate."""
        page: Optional[int] = 1
        while page is not None:
            result = self.list(
                status=status,
                operation=operation,
                currency=currency,
                country=country,
                reference=reference,
                from_date=from_date,
                to_date=to_date,
                per_page=per_page,
                page=page,
            )
            yield from result.data
            page = result.current_page + 1 if result.has_more else None

    def find_by_reference(self, reference: str) -> Optional[Payment]:
        """The payment carrying your reference, or ``None`` (unique per token and mode)."""
        result = self.list(reference=check.reference(reference), per_page=1)
        return result.data[0] if result.data else None


class Providers(_Resource):
    """Countries, providers, availability and prediction."""

    def countries(self) -> List[Country]:
        """Countries open to payments: dial code, currencies, limits per currency."""
        data = self._transport.request("GET", "/countries", RetryMode.SAFE).data()
        return [Country.from_api(item) for item in _as_list(data)]

    def list(self, *, operation: Union[Operation, str, None] = None, country: Optional[str] = None) -> List[Provider]:
        """Providers and their rules for an operation (``deposit`` by default)."""
        query = {
            "operation": check.one_of(None if operation is None else str(operation), ("deposit", "payout", "refund"), "operation"),
            "country": check.country(country),
        }
        data = self._transport.request("GET", "/providers", RetryMode.SAFE, query=query).data()
        return [Provider.from_api(item) for item in _as_list(data)]

    def availability(self, *, country: Optional[str] = None) -> Dict[str, ProviderAvailability]:
        """Availability per provider code: ``OPERATIONAL``, ``DELAYED`` (payouts queued) or ``CLOSED``."""
        data = self._transport.request("GET", "/availability", RetryMode.SAFE, query={"country": check.country(country)}).data()
        if data == []:
            data = {}
        if not isinstance(data, dict):
            raise UnexpectedResponseError('Unexpected API payload: "data" should be an object.')
        return {str(code): ProviderAvailability.from_api(str(code), statuses) for code, statuses in data.items()}

    def predict(self, phone: str, *, country: Optional[str] = None) -> ProviderPrediction:
        """Validates a number and predicts its provider, without creating a payment."""
        body = check.compact({"phone": check.phone(phone), "country": check.country(country)})
        return ProviderPrediction.from_api(self._transport.request("POST", "/predict-provider", RetryMode.SAFE, body=body).data())


class AccountResource(_Resource):
    """The merchant account, keys and balances."""

    def get(self) -> Account:
        """Mode, fee rates, test key and (live token only) the signing secret."""
        return Account.from_api(self._transport.request("GET", "/account", RetryMode.SAFE).data())

    def balances(self) -> List[Balance]:
        """One balance per currency, in the mode of the key."""
        data = self._transport.request("GET", "/balances", RetryMode.SAFE).data()
        return [Balance.from_api(item) for item in _as_list(data)]

    def balance(self, currency: str) -> Optional[Balance]:
        """The balance of one currency, ``None`` when the account has none."""
        return next((b for b in self.balances() if b.currency.upper() == currency.upper()), None)

    def rotate_webhook_secret(self) -> WebhookSecret:
        """New signing secret; the previous one stays accepted 24 h. Live token only, never retried."""
        data = self._transport.request("POST", "/account/webhook-secret/rotate", RetryMode.THROTTLED_ONLY).data()
        return WebhookSecret.from_api(data)

    def rotate_test_key(self) -> str:
        """New test key, returned; the previous one stops working immediately. Live token only, never retried."""
        data = self._transport.request("POST", "/account/test-key/rotate", RetryMode.THROTTLED_ONLY).data()
        if not isinstance(data, dict) or not isinstance(data.get("test_key"), str):
            raise UnexpectedResponseError('Unexpected API payload: "test_key" should be a string.')
        return str(data["test_key"])


class Notifications(_Resource):
    """History of the notifications sent to your callback_url."""

    def list(
        self,
        *,
        payment_id: Optional[str] = None,
        status: Union[NotificationStatus, str, None] = None,
        per_page: Optional[int] = None,
        page: Optional[int] = None,
    ) -> Page[Notification]:
        """Notifications of the token's payments (mode of the key), newest first."""
        query = {
            "payment_id": None if payment_id is None else check.identifier(payment_id, "payment_id"),
            "status": check.one_of(None if status is None else str(status), ("delivered", "pending", "abandoned"), "status"),
            "per_page": check.per_page(per_page),
            "page": check.page(page),
        }
        response = self._transport.request("GET", "/notifications", RetryMode.SAFE, query=query)
        return Page.from_api(response.body, Notification.from_api)

    def resend(self, id: int) -> Notification:  # noqa: A002
        """Resends a notification (a new one is created and delivered). Never retried automatically."""
        if isinstance(id, bool) or not isinstance(id, int) or id < 1:
            raise InvalidArgumentError("id must be a positive integer.")
        return Notification.from_api(self._transport.request("POST", f"/notifications/{id}/resend", RetryMode.THROTTLED_ONLY).data())


def _as_list(data: Any) -> List[Any]:
    if not isinstance(data, list):
        raise UnexpectedResponseError('Unexpected API payload: "data" should be an array.')
    return data
