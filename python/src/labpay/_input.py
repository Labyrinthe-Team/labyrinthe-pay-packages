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
        raise InvalidArgumentError("customer_message must be 4 to 22 characters (letters, digits, spaces).")
    return value


def callback_url(value: Optional[str]) -> Optional[str]:
    if value is None or value.strip() == "":
        return None
    url = value.strip()
    parts = urlsplit(url)
    if parts.scheme != "https" or not parts.netloc or len(url) > 2048:
        raise InvalidArgumentError("callback_url must be a public HTTPS URL (at most 2048 characters).")
    return url


def metadata(value: Optional[Mapping[str, Any]]) -> Optional[Dict[str, Optional[str]]]:
    if value is None:
        return None
    if len(value) > 20:
        raise InvalidArgumentError("metadata accepts at most 20 entries.")
    result: Dict[str, Optional[str]] = {}
    for key, item in value.items():
        if item is None:
            result[str(key)] = None
            continue
        if isinstance(item, bool):
            text = "true" if item else "false"
        elif isinstance(item, (str, int, float, Decimal)):
            text = str(item)
        else:
            raise InvalidArgumentError(f'metadata["{key}"] must be a string.')
        if len(text) > 255:
            raise InvalidArgumentError(f'metadata["{key}"] must be at most 255 characters.')
        result[str(key)] = text
    return result


def identifier(value: str, name: str = "id") -> str:
    text = value.strip()
    if text == "" or len(text) > 64:
        raise InvalidArgumentError(f'{name} must be a LabPay identifier, e.g. "DEP-01JCX…".')
    return text


def path_id(value: str, name: str = "id") -> str:
    return quote(identifier(value, name), safe="")


def per_page(value: Optional[int]) -> Optional[int]:
    if value is not None and (isinstance(value, bool) or not 1 <= value <= MAX_PER_PAGE):
        raise InvalidArgumentError(f"per_page must be between 1 and {MAX_PER_PAGE}.")
    return value


def page(value: Optional[int]) -> Optional[int]:
    if value is not None and (isinstance(value, bool) or value < 1):
        raise InvalidArgumentError("page must be 1 or more.")
    return value


def date(value: Union[str, _dt.date, None], name: str) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, _dt.date):
        return value.strftime("%Y-%m-%d")
    if not _DATE.match(value):
        raise InvalidArgumentError(f"{name} must be a date in YYYY-MM-DD format.")
    return value


def one_of(value: Optional[str], allowed: Iterable[str], name: str) -> Optional[str]:
    if value is None:
        return None
    text = str(value)
    allowed = list(allowed)
    if text not in allowed:
        raise InvalidArgumentError(f"{name} must be one of: {', '.join(allowed)}.")
    return text


def compact(values: Mapping[str, Any]) -> Dict[str, Any]:
    return {k: v for k, v in values.items() if v is not None}
