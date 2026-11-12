"""Client de l'API V2 de LabPay."""

from __future__ import annotations

import os
import time
from typing import Any, Callable, Optional
from urllib.parse import urlsplit

import httpx

from ._transport import ResponseInfo, Transport
from .errors import InvalidArgumentError
from .resources import AccountResource, Deposits, Notifications, Payments, Payouts, Providers, Refunds

DEFAULT_BASE_URL = "https://api.labyrinthe-rdc.com/api"
TEST_KEY_PREFIX = "test_"
DEFAULT_TIMEOUT = 30.0
DEFAULT_MAX_RETRIES = 2
DEFAULT_MAX_RETRY_WAIT = 10.0


def _normalize_base_url(value: str) -> str:
    base = value.strip().rstrip("/")
    parts = urlsplit(base)
    if parts.scheme not in ("http", "https") or not parts.netloc:
        raise InvalidArgumentError("base_url must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api.")
    # Un hôte nu ("http://localhost:8000") désigne la racine du backend.
    if parts.path in ("", "/"):
        base += "/api"
    # "…/api/V2" est accepté comme "…/api".
    if base.lower().endswith("/v2"):
        base = base[:-3]
    return base + "/V2"


class LabPay:
    """LabPay API V2 client (synchronous).

    >>> labpay = LabPay(api_key="test_…")
    >>> payment = labpay.deposits.create(amount="1000", currency="CDF", phone="0813456789", reference="ORDER-42")

    A key starting with ``test_`` uses the sandbox: no real money moves, test
    balances and payments are separate. Going live only means switching keys.
    """

    def __init__(
        self,
        api_key: Optional[str] = None,
        *,
        base_url: Optional[str] = None,
        timeout: float = DEFAULT_TIMEOUT,
        max_retries: int = DEFAULT_MAX_RETRIES,
        max_retry_wait: float = DEFAULT_MAX_RETRY_WAIT,
        auto_reference: bool = True,
        http_client: Optional[httpx.Client] = None,
        sleep: Callable[[float], None] = time.sleep,
        user_agent_suffix: Optional[str] = None,
    ) -> None:
        """
        :param api_key: live token or test key (``test_…``); defaults to ``LABPAY_API_KEY``. Keep it server-side.
        :param base_url: API root (default, or ``LABPAY_BASE_URL``); ``/V2`` is added
        :param max_retries: automatic retries when it is safe (0 disables them)
        :param auto_reference: generate a ``reference`` for payments sent without one, so that they
            can be retried without risk of a double payment
        :param http_client: your own ``httpx.Client`` (proxies, tests); not closed by :meth:`close`
        """
        key = (api_key if api_key is not None else os.environ.get("LABPAY_API_KEY", "")).strip()
        if not key:
            raise InvalidArgumentError("A LabPay API token or test key (test_…) is required (api_key or LABPAY_API_KEY).")
        if timeout <= 0:
            raise InvalidArgumentError("timeout must be greater than 0.")
        if isinstance(max_retries, bool) or not 0 <= max_retries <= 10:
            raise InvalidArgumentError("max_retries must be between 0 and 10.")
        if max_retry_wait < 0:
            raise InvalidArgumentError("max_retry_wait cannot be negative.")

        self._api_key = key
        self.base_url = _normalize_base_url(base_url or os.environ.get("LABPAY_BASE_URL") or DEFAULT_BASE_URL)
        self._owns_http = http_client is None
        self._http = http_client or httpx.Client(timeout=timeout, follow_redirects=False)
        self._transport = Transport(
            api_key=key,
            base_url=self.base_url,
            http=self._http,
            max_retries=max_retries,
            max_retry_wait=max_retry_wait,
            sleep=sleep,
            user_agent_suffix=user_agent_suffix,
        )
        self.deposits = Deposits(self._transport, auto_reference)
        self.payouts = Payouts(self._transport, auto_reference)
        self.refunds = Refunds(self._transport, auto_reference)
        self.payments = Payments(self._transport, auto_reference)
        self.providers = Providers(self._transport, auto_reference)
        self.account = AccountResource(self._transport, auto_reference)
        self.notifications = Notifications(self._transport, auto_reference)

    @property
    def is_test_mode(self) -> bool:
        """True with a test key (``test_…``): sandbox, no real money, separate balances."""
        return self._api_key.startswith(TEST_KEY_PREFIX)

    @property
    def last_response(self) -> Optional[ResponseInfo]:
        """Status and headers of the last response received (e.g. its X-Labyrinthe-Mode)."""
        return self._transport.last_response

    def close(self) -> None:
        if self._owns_http:
            self._http.close()

    def __enter__(self) -> LabPay:
        return self

    def __exit__(self, *exc: Any) -> None:
        self.close()

    def __repr__(self) -> str:
        return f"LabPay(base_url={self.base_url!r}, test_mode={self.is_test_mode})"

    def __getstate__(self) -> Any:
        raise TypeError("The LabPay client cannot be pickled (it holds the API key).")
