"""Official Python SDK for LabPay: Mobile Money (M-Pesa, Airtel Money, Orange Money, MTN MoMo…) across Africa.

>>> from labpay import LabPay
>>> labpay = LabPay(api_key="test_…")            # test key: sandbox, no real money
>>> payment = labpay.deposits.create(amount="1000", currency="CDF", phone="0813456789", reference="ORDER-42")
>>> payment.status
<PaymentStatus.PENDING: 'pending'>
"""

import logging as _logging

from . import errors, webhooks
from ._client import DEFAULT_BASE_URL, TEST_KEY_PREFIX, LabPay
from ._transport import ResponseInfo
from ._version import __version__
from .errors import (
    AmountOutOfRangeError,
    APIConnectionError,
    ApiError,
    APITimeoutError,
    AuthenticationError,
    ConflictError,
    DailyLimitReachedError,
    InsufficientBalanceError,
    InvalidArgumentError,
    InvalidPhoneNumberError,
    InvalidRequestError,
    InvalidWebhookPayloadError,
    LabPayError,
    NotFoundError,
    PermissionDeniedError,
    RateLimitError,
    ServiceUnavailableError,
    SignatureVerificationError,
    UnexpectedResponseError,
    ValidationError,
)
from .resources import AUTO_REFERENCE_PREFIX
from .types import (
    Account,
    AmountLimits,
    Balance,
    Country,
    Mode,
    Notification,
    NotificationStatus,
    Operation,
    Page,
    Payment,
    PaymentFailure,
    PaymentStatus,
    Provider,
    ProviderAvailability,
    ProviderCurrency,
    ProviderPrediction,
    WebhookEventType,
    WebhookSecret,
)
from .webhooks import WebhookEvent, construct_event

_logging.getLogger("labpay").addHandler(_logging.NullHandler())

__all__ = [
    "DEFAULT_BASE_URL",
    "TEST_KEY_PREFIX",
    "AUTO_REFERENCE_PREFIX",
    "__version__",
    "LabPay",
    "ResponseInfo",
    "errors",
    "webhooks",
    "construct_event",
    "WebhookEvent",
    "LabPayError",
    "ApiError",
    "AuthenticationError",
    "PermissionDeniedError",
    "NotFoundError",
    "ConflictError",
    "InvalidRequestError",
    "ValidationError",
    "InvalidPhoneNumberError",
    "AmountOutOfRangeError",
    "InsufficientBalanceError",
    "RateLimitError",
    "DailyLimitReachedError",
    "ServiceUnavailableError",
    "APIConnectionError",
    "APITimeoutError",
    "InvalidArgumentError",
    "UnexpectedResponseError",
    "SignatureVerificationError",
    "InvalidWebhookPayloadError",
    "Account",
    "AmountLimits",
    "Balance",
    "Country",
    "Mode",
    "Notification",
    "NotificationStatus",
    "Operation",
    "Page",
    "Payment",
    "PaymentFailure",
    "PaymentStatus",
    "Provider",
    "ProviderAvailability",
    "ProviderCurrency",
    "ProviderPrediction",
    "WebhookEventType",
    "WebhookSecret",
]
