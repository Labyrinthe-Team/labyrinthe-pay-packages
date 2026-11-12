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
            signatures.append(value.lower())
    return timestamp, signatures


def verify(
    raw_body: RawBody,
    signature_header: Optional[str],
    secret: Secrets,
    *,
    tolerance: int = DEFAULT_TOLERANCE,
    now: Optional[float] = None,
) -> int:
    """Checks the signature (constant time) and the timestamp; returns the signed timestamp.

    :param tolerance: max age of the signed timestamp, in seconds; 0 disables the check (not recommended)
    :raises SignatureVerificationError: answer 400
    """
    secrets = _secrets(secret)
    timestamp, signatures = _parse(signature_header or "")
    if timestamp is None or not signatures:
        raise SignatureVerificationError(
            'Missing or malformed X-Labyrinthe-Signature header (expected "t=<timestamp>,v1=<signature>").'
        )
    body = _bytes(raw_body)
    valid = False
    for candidate in secrets:
        expected = _compute(candidate, timestamp, body)
        for signature in signatures:
            valid = hmac.compare_digest(expected, signature) or valid
    if not valid:
        raise SignatureVerificationError("Invalid notification signature: the body was not signed with this secret.")
    current = time.time() if now is None else now
    if tolerance > 0 and abs(current - timestamp) > tolerance:
        raise SignatureVerificationError(f"Notification timestamp is outside the {tolerance} s tolerance (possible replay).")
    return timestamp


def is_valid(
    raw_body: RawBody,
    signature_header: Optional[str],
    secret: Secrets,
    *,
    tolerance: int = DEFAULT_TOLERANCE,
    now: Optional[float] = None,
) -> bool:
    """True when the signature and the timestamp are valid."""
    try:
        verify(raw_body, signature_header, secret, tolerance=tolerance, now=now)
    except SignatureVerificationError:
        return False
    return True


def construct_event(
    raw_body: RawBody,
    signature_header: Optional[str],
    secret: Secrets,
    *,
    tolerance: int = DEFAULT_TOLERANCE,
    now: Optional[float] = None,
) -> WebhookEvent:
    """Verifies a notification and returns the typed event. Pass the RAW body, never re-encoded JSON.

    :raises SignatureVerificationError: wrong/missing signature or old timestamp: answer 400
    :raises InvalidWebhookPayloadError: signed body that is not a notification: answer 400
    """
    timestamp = verify(raw_body, signature_header, secret, tolerance=tolerance, now=now)
    try:
        body = json.loads(_bytes(raw_body).decode("utf-8"))
    except ValueError:
        raise InvalidWebhookPayloadError("The notification body is not valid JSON.") from None
    if not isinstance(body, dict):
        raise InvalidWebhookPayloadError("The notification body is not a JSON object.")
    name = body.get("event")
    if not isinstance(name, str):
        raise InvalidWebhookPayloadError('Invalid notification payload: "event" should be a string.')
    try:
        payment = Payment.from_api(body.get("payment"))
    except UnexpectedResponseError as exc:
        raise InvalidWebhookPayloadError(exc.message.replace("Unexpected API payload", "Invalid notification payload")) from None
    sent_at = body.get("sent_at")
    return WebhookEvent(
        type=WebhookEventType(name),
        name=name,
        payment=payment,
        sent_at=sent_at if isinstance(sent_at, str) else None,
        timestamp=timestamp,
        raw=body,
    )


def sign(raw_body: RawBody, secret: Secrets, timestamp: Optional[int] = None) -> str:
    """The X-Labyrinthe-Signature value LabPay would send (one ``v1`` per secret): to test your endpoint."""
    ts = int(time.time()) if timestamp is None else timestamp
    body = _bytes(raw_body)
    return ",".join([f"t={ts}"] + [f"v1={_compute(s, ts, body)}" for s in _secrets(secret)])
