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

