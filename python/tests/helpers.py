"""Client relié à un transport httpx simulé (aucun appel réseau)."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Union

import httpx

from labpay import LabPay

Reply = Union[httpx.Response, Exception]


@dataclass
class Recorder:
    replies: List[Reply]
    requests: List[httpx.Request] = field(default_factory=list)
    sleeps: List[float] = field(default_factory=list)

    def handler(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        if not self.replies:
            raise AssertionError(f"No response queued for {request.method} {request.url}")
        reply = self.replies.pop(0)
        if isinstance(reply, Exception):
            raise reply
        return reply

    @property
    def last(self) -> httpx.Request:
        return self.requests[-1]

    def body(self, index: int = -1) -> Any:
        return json.loads(self.requests[index].content)


def json_response(status: int, body: Any, headers: Optional[Dict[str, str]] = None) -> httpx.Response:
    return httpx.Response(status, json=body, headers={"X-Labyrinthe-Mode": "test", **(headers or {})})


def make_client(*replies: Reply, api_key: str = "test_abcdefghijklmnop", **options: Any) -> "tuple[LabPay, Recorder]":
    recorder = Recorder(list(replies))
    http = httpx.Client(transport=httpx.MockTransport(recorder.handler))
    client = LabPay(api_key, base_url="https://api.example.test/api", http_client=http, sleep=recorder.sleeps.append, **options)
    return client, recorder


def payment(**overrides: Any) -> Dict[str, Any]:
    data: Dict[str, Any] = {
        "id": "DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6",
        "reference": "ORDER-42",
        "operation": "deposit",
        "livemode": False,
        "status": "pending",
        "gateway_status": "ACCEPTED",
        "amount": "1000.00",
        "fee": "35.00",
        "total_amount": "1035.00",
        "currency": "CDF",
        "country": "COD",
        "phone": "0813456789",
        "provider": "VODACOM_MPESA_COD",
        "provider_transaction_id": None,
        "failure": None,
        "metadata": {"orderId": "42"},
        "created_at": "2026-11-11T10:00:00+00:00",
        "completed_at": None,
        "refunded_amount": "0.00",
    }
    data.update(overrides)
    return data
