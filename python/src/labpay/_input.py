"""Contrôles locaux reprenant les règles de validation de l'API V2. Usage interne."""

from __future__ import annotations

import datetime as _dt
import re
from decimal import Decimal, InvalidOperation
from typing import Any, Dict, Iterable, Mapping, Optional, Union
from urllib.parse import quote, urlsplit

from .errors import InvalidArgumentError

MAX_PER_PAGE = 100
_AMOUNT = re.compile(r"^\d{1,16}(\.\d{1,2})?$")
_ZERO = re.compile(r"^0+(\.0+)?$")
_REFERENCE = re.compile(r"^[A-Za-z0-9._:\-]{1,100}$")
_CUSTOMER_MESSAGE = re.compile(r"^[A-Za-z0-9 ]{4,22}$")
_CODE3 = re.compile(r"^[A-Za-z]{3}$")
_PROVIDER = re.compile(r"^[A-Za-z0-9_]{1,64}$")
_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")

Amount = Union[str, int, Decimal]


def amount(value: Amount, name: str = "amount") -> str:
    """``"1500"``, ``1500``, ``Decimal("12.50")`` → ``"1500"`` / ``"12.50"``. Floats are refused (imprecise)."""
    if isinstance(value, bool) or isinstance(value, float):
        raise InvalidArgumentError(f'{name} must be a string, an int or a Decimal (e.g. "12.50"), not a float.')
    if isinstance(value, Decimal):
        try:
            text = format(value, "f")
        except (InvalidOperation, ValueError):
            text = ""
    else:
        text = str(value).strip()
    if not _AMOUNT.match(text) or _ZERO.match(text):
        raise InvalidArgumentError(f'{name} must be a positive number with at most 2 decimals (e.g. "1500" or "12.50").')
    return text


def currency(value: str) -> str:
    if not _CODE3.match(value.strip()):
        raise InvalidArgumentError('currency must be a 3-letter code, e.g. "CDF" or "USD".')
    return value.strip().upper()


def country(value: Optional[str]) -> Optional[str]:
    if value is None or value.strip() == "":
        return None
    if not _CODE3.match(value.strip()):
        raise InvalidArgumentError('country must be an ISO 3166-1 alpha-3 code, e.g. "COD".')
    return value.strip().upper()


def phone(value: str) -> str:
    text = value.strip()
    if text == "" or len(text) > 20:
        raise InvalidArgumentError("phone is required and must be at most 20 characters.")
    return text


def provider(value: Optional[str]) -> Optional[str]:
    if value is None or value.strip() == "":
        return None
    if not _PROVIDER.match(value.strip()):
        raise InvalidArgumentError('provider must be a provider code, e.g. "VODACOM_MPESA_COD".')
    return value.strip()


def reference(value: Optional[str]) -> Optional[str]:
    if value is None:
        return None
    if not _REFERENCE.match(value):
        raise InvalidArgumentError("reference must be 1 to 100 characters among A-Z a-z 0-9 . _ : -")
    return value


def customer_message(value: Optional[str]) -> Optional[str]:
    if value is None or value == "":
        return None
    if not _CUSTOMER_MESSAGE.match(value):
