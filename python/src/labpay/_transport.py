"""Envoi des requêtes, politique de relance et conversion des erreurs.

Mêmes règles que les SDK PHP (php/src/Internal/Transport.php) et Node
(node/src/internal/transport.ts). Usage interne.
"""

from __future__ import annotations

import enum
import json
import logging
import platform
import random
import re
import time
from dataclasses import dataclass
from email.utils import parsedate_to_datetime
from typing import Any, Callable, Dict, Mapping, Optional, Union

import httpx

from ._version import __version__
from .errors import (
    APIConnectionError,
    ApiError,
    APITimeoutError,
    UnexpectedResponseError,
    error_from_response,
)

logger = logging.getLogger("labpay")

_BASE_DELAY = 0.5
_MAX_BACKOFF = 8.0

#: 503 qui refusent la requête avant tout traitement : relancés seulement après un Retry-After.
REFUSED_BEFORE_PROCESSING = frozenset(
    {
        "MAINTENANCE",
        "API_DISABLED",
        "DEPOSITS_DISABLED",
        "WITHDRAWALS_DISABLED",
        "PROVIDER_TEMPORARILY_UNAVAILABLE",
        "V2_DISABLED",
        "TEST_MODE_DISABLED",
        "API_VERSION_DISABLED",
        "FEATURE_DISABLED",
    }
)

QueryValue = Union[str, int, None]


class RetryMode(enum.Enum):
    """Quand une requête peut être renvoyée automatiquement.

    - ``SAFE`` : le rejeu ne produit pas deux fois un effet (GET, predict-provider, sync, et
      dépôts / envois / remboursements avec ``reference``). Relancée sur erreur réseau, 5xx et 429 du limiteur.
    - ``THROTTLED_ONLY`` : rotations, renvoi de notification, annulation, paiement sans référence.
      Relancée seulement sur 429 du limiteur.

    Dans les deux modes, un 503 qui refuse avant traitement (MAINTENANCE…) n'est relancé qu'après un
    ``Retry-After`` ≤ ``max_retry_wait``.
    """

    SAFE = "safe"
    THROTTLED_ONLY = "throttled-only"


@dataclass(frozen=True)
class ResponseInfo:
    """Statut et en-têtes (noms en minuscules) d'une réponse reçue."""

    status: int
    headers: Dict[str, str]

    @property
    def mode(self) -> Optional[str]:
        return self.headers.get("x-labyrinthe-mode")


@dataclass(frozen=True)
class Response:
    status: int
    body: Any
    headers: Dict[str, str]

    def data(self) -> Any:
        if not isinstance(self.body, dict) or "data" not in self.body:
            raise UnexpectedResponseError('Unexpected API response: "data" is missing.', self.status)
        return self.body["data"]


def user_agent(suffix: Optional[str] = None) -> str:
    agent = f"labpay-python/{__version__} Python/{platform.python_version()}"
    return agent if not suffix else f"{agent} {suffix.strip()}"


def _retry_after(header: Optional[str]) -> Optional[float]:
    if header is None:
        return None
    value = header.strip()
    if re.fullmatch(r"\d+(\.\d+)?", value):
        return float(value)
    try:
        return max(0.0, parsedate_to_datetime(value).timestamp() - time.time())
    except (TypeError, ValueError, IndexError):
        return None


class Transport:
    def __init__(
        self,
        *,
        api_key: str,
        base_url: str,
        http: httpx.Client,
        max_retries: int,
        max_retry_wait: float,
        sleep: Callable[[float], None],
        user_agent_suffix: Optional[str] = None,
    ) -> None:
        self._api_key = api_key
        self.base_url = base_url
        self._http = http
        self._max_retries = max_retries
        self._max_retry_wait = max_retry_wait
        self._sleep = sleep
        self._user_agent = user_agent(user_agent_suffix)
        self.last_response: Optional[ResponseInfo] = None

    def request(
        self,
        method: str,
        path: str,
        mode: RetryMode,
        *,
        query: Optional[Mapping[str, QueryValue]] = None,
        body: Optional[Mapping[str, Any]] = None,
        payment_on_refusal: bool = False,
    ) -> Response:
        params = {k: str(v) for k, v in (query or {}).items() if v is not None and v != ""}
        headers = {
            "Authorization": f"Bearer {self._api_key}",
            "Accept": "application/json",
            "User-Agent": self._user_agent,
        }
        content: Optional[bytes] = None
        if body is not None:
            headers["Content-Type"] = "application/json"
            content = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")

        attempt = 0
        while True:
            logger.debug("LabPay request %s %s (attempt %d)", method, path, attempt + 1)
            try:
                response = self._http.request(method, self.base_url + path, params=params, headers=headers, content=content)
            except httpx.TransportError as exc:
                timeout = isinstance(exc, httpx.TimeoutException)
                if mode is RetryMode.SAFE and attempt < self._max_retries:
                    delay = self._backoff(attempt)
                    logger.warning("LabPay network error on %s %s, retrying in %.2fs", method, path, delay)
                    self._sleep(delay)
                    attempt += 1
                    continue
                reason = re.sub(r"\?[^\s\"']*", "?…", str(exc))
                message = f"Could not reach the LabPay API ({method} {path}): {reason or type(exc).__name__}"
                if mode is RetryMode.THROTTLED_ONLY:
                    message += " The request may have been processed; it was not retried automatically."
                if timeout:
                    raise APITimeoutError(message, "TIMEOUT") from None
                raise APIConnectionError(message, "CONNECTION_ERROR") from None

            response_headers = {k.lower(): v for k, v in response.headers.items()}
            self.last_response = ResponseInfo(response.status_code, response_headers)
            text = response.text

            if 200 <= response.status_code < 300:
                return Response(response.status_code, self._decode(text, response.status_code), response_headers)

            parsed = self._try_decode(text)
            if (
                payment_on_refusal
                and response.status_code == 422
                and isinstance(parsed, dict)
                and isinstance(parsed.get("data"), dict)
                and "id" in parsed["data"]
            ):
                return Response(response.status_code, parsed, response_headers)

            error = error_from_response(response.status_code, parsed, response_headers)
            if attempt < self._max_retries:
                retry_delay = self._retry_delay(mode, error, attempt)
                if retry_delay is not None:
                    logger.warning("LabPay %s on %s %s, retrying in %.2fs", error.code, method, path, retry_delay)
                    self._sleep(retry_delay)
                    attempt += 1
                    continue
            raise error

    def _retry_delay(self, mode: RetryMode, error: ApiError, attempt: int) -> Optional[float]:
        retry_after = _retry_after(error.headers.get("retry-after"))
        if error.code in REFUSED_BEFORE_PROCESSING:
            return retry_after if retry_after is not None and retry_after <= self._max_retry_wait else None
        if error.status == 429:
            if error.code != "TOO_MANY_REQUESTS":
                return None
            if retry_after is not None:
                return retry_after if retry_after <= self._max_retry_wait else None
            return self._backoff(attempt)
        return self._backoff(attempt) if mode is RetryMode.SAFE and error.status >= 500 else None

    def _backoff(self, attempt: int) -> float:
        delay = min(_MAX_BACKOFF, _BASE_DELAY * (2**attempt))
        return float(min(self._max_retry_wait, delay * (0.5 + random.random() * 0.5)))  # noqa: S311 - gigue

    @staticmethod
    def _decode(text: str, status: int) -> Any:
        if text.strip() == "":
            return {}
        try:
            return json.loads(text)
        except ValueError:
            raise UnexpectedResponseError("Unexpected API response: the body is not valid JSON.", status) from None

    @staticmethod
    def _try_decode(text: str) -> Any:
        try:
            return json.loads(text)
        except ValueError:
            return None

    def __repr__(self) -> str:
        return f"Transport(base_url={self.base_url!r})"
