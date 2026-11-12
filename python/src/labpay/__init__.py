"""Official Python SDK for LabPay: Mobile Money (M-Pesa, Airtel Money, Orange Money) in the DR Congo.

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
