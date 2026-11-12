"""Vérification des notifications envoyées sur la ``callback_url``.

``X-Labyrinthe-Signature: t=<timestamp>,v1=<hex>[,v1=<hex>]`` : chaque ``v1`` est le
HMAC-SHA256 de ``"<timestamp>.<corps brut>"`` avec le secret de signature (``whsec_…``).
Pendant les 24 h qui suivent une rotation, l'en-tête porte deux ``v1`` : la notification
est acceptée si l'une d'elles correspond.

>>> event = construct_event(request.body, request.headers["X-Labyrinthe-Signature"], "whsec_…")
"""

from __future__ import annotations

import hashlib
import hmac
import json
import re
import time
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Sequence, Tuple, Union

from .errors import InvalidArgumentError, InvalidWebhookPayloadError, SignatureVerificationError, UnexpectedResponseError
from .types import Payment, WebhookEventType

__all__ = [
    "SIGNATURE_HEADER",
    "EVENT_HEADER",
    "DELIVERY_HEADER",
    "MODE_HEADER",
    "DEFAULT_TOLERANCE",
    "WebhookEvent",
    "construct_event",
    "verify",
    "is_valid",
    "sign",
]

SIGNATURE_HEADER = "X-Labyrinthe-Signature"
EVENT_HEADER = "X-Labyrinthe-Event"
DELIVERY_HEADER = "X-Labyrinthe-Delivery"
MODE_HEADER = "X-Labyrinthe-Mode"
DEFAULT_TOLERANCE = 300

RawBody = Union[str, bytes, bytearray, memoryview]
Secrets = Union[str, Sequence[str]]

_V1 = re.compile(r"^[0-9a-fA-F]{64}$")
_T = re.compile(r"^\d{1,12}$")


@dataclass(frozen=True)
class WebhookEvent:
    """A verified notification. Deduplicate on ``X-Labyrinthe-Delivery`` or ``payment.id``."""

    type: WebhookEventType
    #: The event name as sent (``"payment.completed"``, ``"payment.failed"`` or a newer one).
    name: str
    payment: Payment
    sent_at: Optional[str]
    #: Signed Unix timestamp (``t`` of the signature header).
    timestamp: int
    raw: Dict[str, Any] = field(repr=False, compare=False)

    @property
    def is_payment_completed(self) -> bool:
        return self.type is WebhookEventType.PAYMENT_COMPLETED

    @property
    def is_payment_failed(self) -> bool:
        return self.type is WebhookEventType.PAYMENT_FAILED


def _bytes(body: RawBody) -> bytes:
    return body.encode("utf-8") if isinstance(body, str) else bytes(body)


def _secrets(secret: Secrets) -> List[str]:
    items = [secret] if isinstance(secret, str) else list(secret)
    result = [s for s in items if isinstance(s, str) and s != ""]
    if not result:
        raise InvalidArgumentError("The webhook signing secret cannot be empty (whsec_…, GET /V2/account with the live token).")
    return result


def _compute(secret: str, timestamp: int, body: bytes) -> str:
    return hmac.new(secret.encode("utf-8"), f"{timestamp}.".encode() + body, hashlib.sha256).hexdigest()


def _parse(header: str) -> Tuple[Optional[int], List[str]]:
    timestamp: Optional[int] = None
    signatures: List[str] = []
    for part in header.split(","):
        key, sep, value = part.strip().partition("=")
        if not sep:
            continue
        if key == "t" and _T.match(value):
            timestamp = int(value)
        elif key == "v1" and _V1.match(value):
