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
