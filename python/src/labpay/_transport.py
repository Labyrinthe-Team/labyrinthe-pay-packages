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
