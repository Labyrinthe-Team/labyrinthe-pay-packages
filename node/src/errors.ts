/** Error codes documented by the V2 API. The list is open: new codes may appear. */
export type ApiErrorCode =
  | 'UNAUTHENTICATED'
  | 'TOKEN_BLOCKED'
  | 'MERCHANT_INACTIVE'
  | 'KYC_REQUIRED'
  | 'LIVE_TOKEN_REQUIRED'
  | 'PAYMENT_NOT_FOUND'
  | 'DEPOSIT_NOT_FOUND'
  | 'NOTIFICATION_NOT_FOUND'
  | 'NOT_A_PAYOUT'
  | 'NOT_CANCELLABLE'
  | 'DEPOSIT_NOT_COMPLETED'
  | 'REFUND_IN_PROGRESS'
  | 'DEPOSIT_ALREADY_REFUNDED'
  | 'VALIDATION_ERROR'
  | 'INVALID_PHONE_NUMBER'
  | 'UNSUPPORTED_COUNTRY'
  | 'INVALID_CURRENCY'
  | 'UNSUPPORTED_PROVIDER'
  | 'INVALID_AMOUNT'
  | 'AMOUNT_TOO_SMALL'
  | 'AMOUNT_TOO_LARGE'
  | 'AMOUNT_OUT_OF_BOUNDS'
  | 'INSUFFICIENT_BALANCE'
  | 'DAILY_LIMIT_REACHED'
  | 'MAINTENANCE'
  | 'API_DISABLED'
  | 'DEPOSITS_DISABLED'
  | 'WITHDRAWALS_DISABLED'
  | 'PROVIDER_TEMPORARILY_UNAVAILABLE'
  | 'GATEWAY_UNAVAILABLE'
  | 'V2_DISABLED'
  | 'TEST_MODE_DISABLED'
  /** Given by the SDK to a 429 of the rate limiter (the API sends no code). */
  | 'TOO_MANY_REQUESTS'
  /** Given by the SDK when the API sent no code. */
  | 'HTTP_ERROR';

/** Codes of the errors raised by the SDK itself (no API response). */
export type SdkErrorCode =
  | 'CONNECTION_ERROR'
  | 'TIMEOUT'
  | 'REQUEST_ABORTED'
  | 'INVALID_ARGUMENT'
  | 'UNEXPECTED_RESPONSE'
  | 'SIGNATURE_VERIFICATION_FAILED'
  | 'INVALID_WEBHOOK_PAYLOAD';

/** Known codes, or any other string (the `string & {}` keeps autocompletion on the known ones). */
export type ErrorCode = ApiErrorCode | SdkErrorCode | (string & {});

/** Per-field messages of a validation error (`errors`). */
export type FieldErrors = Record<string, string[]>;

/**
 * Base class of every error thrown by the SDK: catch it to handle any
 * LabPay failure in one place.
 *
 * Always switch on `code` (stable), never on `message` (API messages are in
 * French, for humans, and may change).
 */
export class LabPayError extends Error {
  /** Stable machine-readable code, e.g. "INSUFFICIENT_BALANCE" or "TIMEOUT". */
  readonly code: ErrorCode;
  /** HTTP status of the response; null when there was no response (network, timeout, local validation…). */
  readonly status: number | null;

  constructor(message: string, code: ErrorCode, status: number | null = null) {
    super(message);
    this.name = new.target.name;
    this.code = code;
    this.status = status;
  }
}

/**
 * The API answered with an error (`{ "message", "code", "errors"? }`).
 * Subclasses exist for the codes an integration usually handles; any other
 * code (including codes added later) is thrown as a plain ApiError.
 */
export class ApiError extends LabPayError {
  declare readonly status: number;
  /** Per-field messages (validation errors), `{}` otherwise. */
  readonly errors: FieldErrors;
  /** Response headers (lower-case names). */
  readonly headers: Readonly<Record<string, string>>;

  constructor(message: string, code: ErrorCode, status: number, errors: FieldErrors = {}, headers: Record<string, string> = {}) {
    super(message, code, status);
    this.errors = errors;
    this.headers = headers;
  }

  /** Mode of the key that made the request (X-Labyrinthe-Mode), when the API sent it. */
  get mode(): 'live' | 'test' | null {
    const mode = this.headers['x-labyrinthe-mode'];
    return mode === 'live' || mode === 'test' ? mode : null;
  }

  /** Seconds from the Retry-After header, null when the API does not say. */
  get retryAfter(): number | null {
    const header = this.headers['retry-after'];
    return header !== undefined && /^\d+$/.test(header.trim()) ? Number(header.trim()) : null;
  }
}

/** 401 `UNAUTHENTICATED`: missing or unknown API token / test key. */
export class AuthenticationError extends ApiError {}

/** 403: `TOKEN_BLOCKED`, `MERCHANT_INACTIVE`, `KYC_REQUIRED` or `LIVE_TOKEN_REQUIRED`. */
export class PermissionError extends ApiError {}

/** 404: `PAYMENT_NOT_FOUND`, `DEPOSIT_NOT_FOUND` or `NOTIFICATION_NOT_FOUND` (for this token and mode). */
export class NotFoundError extends ApiError {}

/** 409: `NOT_A_PAYOUT`, `NOT_CANCELLABLE`, `DEPOSIT_NOT_COMPLETED`, `REFUND_IN_PROGRESS`, `DEPOSIT_ALREADY_REFUNDED`. */
export class ConflictError extends ApiError {}

/**
 * 422 without a payment: refused before any payment was created
 * (`INVALID_CURRENCY`, `UNSUPPORTED_COUNTRY`, `UNSUPPORTED_PROVIDER`,
 * `INVALID_AMOUNT`…). A payment refused by the provider is not an error: it
 * is returned with the `failed` status.
 */
export class InvalidRequestError extends ApiError {}

/** 422 `VALIDATION_ERROR` (or 422 without code on invalid filters): see `errors`. */
export class ValidationError extends InvalidRequestError {}

/** 422 `INVALID_PHONE_NUMBER`. */
export class InvalidPhoneNumberError extends InvalidRequestError {}

/** 422 `AMOUNT_TOO_SMALL`, `AMOUNT_TOO_LARGE` or `AMOUNT_OUT_OF_BOUNDS`: the message gives the limit. */
export class AmountOutOfRangeError extends InvalidRequestError {}

/** 422 `INSUFFICIENT_BALANCE`: nothing was sent. */
export class InsufficientBalanceError extends InvalidRequestError {}

/** 429: too many requests for this token (120 per minute); see `retryAfter`. */
export class RateLimitError extends ApiError {}

/** 429 `DAILY_LIMIT_REACHED`: token not enabled for production yet. Never retried automatically. */
export class DailyLimitReachedError extends RateLimitError {}

/** 5xx: maintenance, operation disabled, provider closed, aggregator unavailable, server error. */
export class ServiceUnavailableError extends ApiError {}

/**
 * The API could not be reached or did not answer in time (`CONNECTION_ERROR`, `TIMEOUT`).
 *
 * For a deposit, payout or refund this does NOT mean the payment failed: it
 * may have been created. Send the same request again with the same
 * `reference`, or look it up with `payments.findByReference()`.
 */
export class ConnectionError extends LabPayError {}

/** The request was aborted with your AbortSignal (`REQUEST_ABORTED`). */
export class AbortedError extends LabPayError {}

/** Invalid input, detected by the SDK before any request (`INVALID_ARGUMENT`). */
export class InvalidArgumentError extends LabPayError {
  constructor(message: string) {
    super(message, 'INVALID_ARGUMENT');
  }
}

/** The API answered something the SDK cannot read (`UNEXPECTED_RESPONSE`). */
export class UnexpectedResponseError extends LabPayError {}

/** Wrong or missing notification signature, or timestamp out of tolerance: answer 400. */
export class SignatureVerificationError extends LabPayError {
  constructor(message: string) {
    super(message, 'SIGNATURE_VERIFICATION_FAILED');
  }
}

/** Correctly signed body that is not a valid notification: answer 400. */
export class InvalidWebhookPayloadError extends LabPayError {
  constructor(message: string) {
    super(message, 'INVALID_WEBHOOK_PAYLOAD');
  }
}

type ApiErrorClass = new (message: string, code: ErrorCode, status: number, errors?: FieldErrors, headers?: Record<string, string>) => ApiError;

const BY_CODE: Record<string, ApiErrorClass> = {
  UNAUTHENTICATED: AuthenticationError,
  TOKEN_BLOCKED: PermissionError,
  MERCHANT_INACTIVE: PermissionError,
  KYC_REQUIRED: PermissionError,
  LIVE_TOKEN_REQUIRED: PermissionError,
  PAYMENT_NOT_FOUND: NotFoundError,
  DEPOSIT_NOT_FOUND: NotFoundError,
  NOTIFICATION_NOT_FOUND: NotFoundError,
  NOT_A_PAYOUT: ConflictError,
  NOT_CANCELLABLE: ConflictError,
  DEPOSIT_NOT_COMPLETED: ConflictError,
  REFUND_IN_PROGRESS: ConflictError,
  DEPOSIT_ALREADY_REFUNDED: ConflictError,
  VALIDATION_ERROR: ValidationError,
  INVALID_PHONE_NUMBER: InvalidPhoneNumberError,
  UNSUPPORTED_COUNTRY: InvalidRequestError,
  INVALID_CURRENCY: InvalidRequestError,
  UNSUPPORTED_PROVIDER: InvalidRequestError,
  INVALID_AMOUNT: InvalidRequestError,
  AMOUNT_TOO_SMALL: AmountOutOfRangeError,
  AMOUNT_TOO_LARGE: AmountOutOfRangeError,
  AMOUNT_OUT_OF_BOUNDS: AmountOutOfRangeError,
  INSUFFICIENT_BALANCE: InsufficientBalanceError,
  DAILY_LIMIT_REACHED: DailyLimitReachedError,
  MAINTENANCE: ServiceUnavailableError,
  API_DISABLED: ServiceUnavailableError,
  DEPOSITS_DISABLED: ServiceUnavailableError,
  WITHDRAWALS_DISABLED: ServiceUnavailableError,
  PROVIDER_TEMPORARILY_UNAVAILABLE: ServiceUnavailableError,
  GATEWAY_UNAVAILABLE: ServiceUnavailableError,
  V2_DISABLED: ServiceUnavailableError,
  TEST_MODE_DISABLED: ServiceUnavailableError,
};

function classForStatus(status: number): ApiErrorClass {
  if (status === 401) return AuthenticationError;
  if (status === 403) return PermissionError;
  if (status === 404) return NotFoundError;
  if (status === 409) return ConflictError;
  if (status === 422) return InvalidRequestError;
  if (status === 429) return RateLimitError;
  if (status >= 500) return ServiceUnavailableError;
  return ApiError;
}

function fieldErrors(value: unknown): FieldErrors {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return {};
  }
  const result: FieldErrors = {};
  for (const [field, messages] of Object.entries(value as Record<string, unknown>)) {
    const list = Array.isArray(messages) ? messages : [messages];
    result[field] = list.filter((m): m is string => typeof m === 'string');
  }
  return result;
}

/**
 * Maps an error response to a typed error: by code first, then by HTTP
 * status, then a plain ApiError.
 *
 * @internal
 */
export function errorFromResponse(status: number, body: unknown, headers: Record<string, string>): ApiError {
  const object = typeof body === 'object' && body !== null ? (body as Record<string, unknown>) : {};
  const errors = fieldErrors(object['errors']);
  let code = typeof object['code'] === 'string' && object['code'] !== '' ? object['code'] : undefined;
  if (code === undefined) {
    code = status === 429 ? 'TOO_MANY_REQUESTS' : status === 422 && Object.keys(errors).length > 0 ? 'VALIDATION_ERROR' : 'HTTP_ERROR';
  }
  const message = typeof object['message'] === 'string' && object['message'] !== '' ? object['message'] : `The LabPay API returned HTTP ${status}.`;
  const ErrorClass = BY_CODE[code] ?? classForStatus(status);
  return new ErrorClass(message, code, status, errors, headers);
}
