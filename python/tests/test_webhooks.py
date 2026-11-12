from __future__ import annotations

import hashlib
import hmac
import json
from typing import Optional

import pytest

from labpay import InvalidArgumentError, InvalidWebhookPayloadError, SignatureVerificationError, WebhookEventType, webhooks

from .helpers import payment

SECRET = "whsec_test_secret"
NOW = 1_794_391_243
BODY = json.dumps(
    {
        "event": "payment.completed",
        "payment": payment(status="completed", gateway_status="COMPLETED", completed_at="2026-11-11T10:00:42+00:00"),
        "sent_at": "2026-11-11T10:00:43+00:00",
    },
    separators=(",", ":"),
)


def test_signature_matches_the_engine_algorithm() -> None:
    expected = hmac.new(SECRET.encode(), f"{NOW}.{BODY}".encode(), hashlib.sha256).hexdigest()
    assert webhooks.sign(BODY, SECRET, NOW) == f"t={NOW},v1={expected}"


def test_construct_event_from_str_and_bytes() -> None:
    header = webhooks.sign(BODY, SECRET, NOW)

    event = webhooks.construct_event(BODY, header, SECRET, now=NOW + 10)
    assert event.type is WebhookEventType.PAYMENT_COMPLETED and event.is_payment_completed
    assert event.payment.is_completed and event.timestamp == NOW
    assert webhooks.construct_event(BODY.encode(), header, SECRET, now=NOW).payment.id == event.payment.id


def test_either_signature_is_accepted_during_a_rotation() -> None:
    header = webhooks.sign(BODY, ["whsec_new", SECRET], NOW)

    assert header.count("v1=") == 2
    assert webhooks.is_valid(BODY, header, SECRET, now=NOW)
    assert webhooks.is_valid(BODY, header, "whsec_new", now=NOW)
    assert not webhooks.is_valid(BODY, header, "whsec_other", now=NOW)
    assert webhooks.is_valid(BODY, webhooks.sign(BODY, SECRET, NOW), ["whsec_new", SECRET], now=NOW)


def test_tampered_body_and_old_timestamp() -> None:
    header = webhooks.sign(BODY, SECRET, NOW)

    with pytest.raises(SignatureVerificationError):
        webhooks.construct_event(BODY.replace("1000.00", "9000.00"), header, SECRET, now=NOW)
    with pytest.raises(SignatureVerificationError, match="tolerance"):
        webhooks.verify(BODY, header, SECRET, now=NOW + 301)
    assert webhooks.is_valid(BODY, header, SECRET, now=NOW + 301, tolerance=0)


@pytest.mark.parametrize("header", ["", "t=123", "v1=" + "a" * 64, "t=123,v1=abc", None])
def test_malformed_headers(header: Optional[str]) -> None:
    with pytest.raises(SignatureVerificationError):
        webhooks.verify(BODY, header, SECRET, now=NOW)


def test_signed_but_invalid_payload() -> None:
    body = '{"event":"payment.completed"}'
    with pytest.raises(InvalidWebhookPayloadError):
        webhooks.construct_event(body, webhooks.sign(body, SECRET, NOW), SECRET, now=NOW)


def test_unknown_event_is_kept() -> None:
    body = json.dumps({"event": "payment.something", "payment": payment(), "sent_at": None})
    event = webhooks.construct_event(body, webhooks.sign(body, SECRET, NOW), SECRET, now=NOW)
    assert event.type is WebhookEventType.UNKNOWN and event.name == "payment.something"


def test_empty_secret() -> None:
    with pytest.raises(InvalidArgumentError):
        webhooks.verify(BODY, "t=1,v1=" + "a" * 64, "")
