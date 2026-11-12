import { UnexpectedResponseError } from './errors.js';

/** Public status of a payment. `completed` and `failed` are final. */
export type PaymentStatus = 'pending' | 'completed' | 'failed';
/** Kind of payment. */
export type Operation = 'deposit' | 'payout' | 'refund';
/** Mode of the key used. */
export type Mode = 'live' | 'test';
/** Delivery status of a notification. */
export type NotificationStatus = 'delivered' | 'pending' | 'abandoned';
/** Event of a notification (`X-Labyrinthe-Event`). */
export type WebhookEventType = 'payment.completed' | 'payment.failed';
/** Provider availability per operation type. */
export type AvailabilityStatus = 'OPERATIONAL' | 'DELAYED' | 'CLOSED';

/** Why a payment failed, as passed on by LabPay. */
export interface PaymentFailure {
  code: string;
  message: string | null;
}

/**
 * A V2 payment: deposit (`DEP-…`), payout (`PAY-…`) or refund (`RFD-…`).
 * Same shape in API responses and in notifications. Amounts are strings
 * with 2 decimals ("1500.00"), never numbers.
 */
export interface Payment {
  id: string;
  /** Your reference (unique per token and mode), or null. */
  reference: string | null;
  operation: Operation | (string & {});
  /** False for a test payment (sandbox). */
  livemode: boolean;
  status: PaymentStatus | (string & {});
  /** Raw aggregator status, informational (e.g. ACCEPTED, ENQUEUED). Rely on `status`. */
  gatewayStatus: string | null;
  /** Deposit: credited to the merchant. Payout: received by the recipient. Refund: refunded. */
  amount: string;
  /** LabPay fees. */
  fee: string | null;
  /** Deposit: paid by the customer. Payout: debited from the merchant balance. */
  totalAmount: string | null;
  currency: string;
  /** ISO 3166-1 alpha-3 country of the number, e.g. COD. */
  country: string | null;
  /** Phone number, in the country's local format. */
  phone: string | null;
  /** Provider code, e.g. VODACOM_MPESA_COD. */
  provider: string | null;
  /** Provider reference (the one in the customer's SMS), or null. */
  providerTransactionId: string | null;
  failure: PaymentFailure | null;
  metadata: Record<string, string | null> | null;
  createdAt: string | null;
  /** Final status date (ISO 8601), null while pending. */
  completedAt: string | null;
  /** Deposits only: amount already refunded or being refunded. */
  refundedAmount: string | null;
  /** Refunds only: id of the refunded deposit. */
  refundedPaymentId: string | null;
  /** The payment as returned by the API. */
  raw: Record<string, unknown>;
}

/** One page of a paginated list. */
export interface Page<T> {
  data: T[];
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  /** True when a next page exists. */
  hasMore: boolean;
}

export interface Balance {
  currency: string;
  /** String with 2 decimals, e.g. "125000.00". */
  balance: string;
  updatedAt: string | null;
}

export interface Account {
  mode: Mode | (string & {});
  merchant: string | null;
  /** True once the token is enabled for production; otherwise live collections have a daily limit. */
  liveEnabled: boolean;
  kycVerified: boolean;
  /** Fee rates between 0 and 1 (e.g. "0.0350" = 3.5 %). */
  fees: { depositRate: string | null; payoutRate: string | null };
  testKey: string | null;
  /** Signing secret (whsec_…). Live token only: null with the test key. */
  webhookSecret: string | null;
  /** During a rotation: expiry of the previous secret. */
  webhookSecretPreviousExpiresAt: string | null;
}

export interface WebhookSecret {
  webhookSecret: string;
  webhookSecretPreviousExpiresAt: string | null;
}

export interface AmountLimits {
  minAmount: string | null;
  maxAmount: string | null;
}

export interface Country {
  country: string;
  name: string | null;
  dialCode: string | null;
  currencies: string[];
  /** Per currency. */
  limits: Record<string, AmountLimits>;
  /** Country of a local number sent without `country`. */
  isDefault: boolean;
}

export interface ProviderCurrency extends AmountLimits {
  /** Decimals accepted in the amount (e.g. "NONE": whole amount only). */
  decimals: string | null;
  status: string | null;
  pinPrompt: unknown;
  pinPromptRevivable: unknown;
  pinPromptInstructions: unknown;
}

export interface Provider {
  /** Provider code, to pass as `provider`. */
  provider: string;
  country: string | null;
  name: string | null;
  nameDisplayedToCustomer: string | null;
  logo: string | null;
  currencies: Record<string, ProviderCurrency>;
}

export interface ProviderAvailability {
  provider: string;
  country: string | null;
  /** Per operation type, e.g. `{ DEPOSIT: 'OPERATIONAL', PAYOUT: 'DELAYED' }`. */
  statuses: Record<string, AvailabilityStatus | (string & {}) | null>;
}

export interface ProviderPrediction {
  /** International format, without "+", e.g. 243813456789. */
  phone: string;
  provider: string;
  country: string;
}

export interface Notification {
  /** Also the X-Labyrinthe-Delivery header of the delivery. */
  id: number;
  event: WebhookEventType | (string & {});
  paymentId: string | null;
  url: string | null;
  status: NotificationStatus | (string & {});
  attempts: number;
  lastStatusCode: number | null;
  lastError: string | null;
  nextAttemptAt: string | null;
  deliveredAt: string | null;
  createdAt: string | null;
}

// ─── Lecture des réponses ────────────────────────────────────────────────────

/** @internal */
export type Json = Record<string, unknown>;

/** @internal */
export function asObject(value: unknown, field: string): Json {
  if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
    return value as Json;
  }
  if (Array.isArray(value) && value.length === 0) {
    // PHP encode un objet vide en [].
    return {};
  }
  throw new UnexpectedResponseError(`Unexpected API payload: "${field}" should be an object.`, 'UNEXPECTED_RESPONSE');
}

/** @internal */
export function asArray(value: unknown, field: string): unknown[] {
  if (Array.isArray(value)) return value;
  throw new UnexpectedResponseError(`Unexpected API payload: "${field}" should be an array.`, 'UNEXPECTED_RESPONSE');
}

function str(data: Json, key: string): string {
  const value = data[key];
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return String(value);
  throw new UnexpectedResponseError(`Unexpected API payload: "${key}" should be a string.`, 'UNEXPECTED_RESPONSE');
}

function nstr(data: Json, key: string): string | null {
  const value = data[key];
  if (value === undefined || value === null) return null;
  return str(data, key);
}

