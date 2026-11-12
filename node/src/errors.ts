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
