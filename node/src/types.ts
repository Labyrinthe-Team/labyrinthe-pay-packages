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

function nint(data: Json, key: string): number | null {
  const value = data[key];
  if (typeof value === 'number' && Number.isInteger(value)) return value;
  if (typeof value === 'string' && /^-?\d+$/.test(value)) return Number(value);
  return null;
}

function bool(data: Json, key: string, fallback = false): boolean {
  const value = data[key];
  return typeof value === 'boolean' ? value : fallback;
}

/** @internal */
export function toPayment(value: unknown): Payment {
  const data = asObject(value, 'payment');
  const failure = data['failure'] === null || data['failure'] === undefined ? null : asObject(data['failure'], 'failure');
  let metadata: Record<string, string | null> | null = null;
  if (data['metadata'] !== null && data['metadata'] !== undefined) {
    metadata = {};
    for (const [key, item] of Object.entries(asObject(data['metadata'], 'metadata'))) {
      metadata[key] = typeof item === 'string' || typeof item === 'number' || typeof item === 'boolean' ? String(item) : null;
    }
  }
  return {
    id: str(data, 'id'),
    reference: nstr(data, 'reference'),
    operation: nstr(data, 'operation') ?? 'unknown',
    livemode: bool(data, 'livemode', true),
    status: nstr(data, 'status') ?? 'unknown',
    gatewayStatus: nstr(data, 'gateway_status'),
    amount: str(data, 'amount'),
    fee: nstr(data, 'fee'),
    totalAmount: nstr(data, 'total_amount'),
    currency: str(data, 'currency'),
    country: nstr(data, 'country'),
    phone: nstr(data, 'phone'),
    provider: nstr(data, 'provider'),
    providerTransactionId: nstr(data, 'provider_transaction_id'),
    failure: failure === null ? null : { code: str(failure, 'code'), message: nstr(failure, 'message') },
    metadata,
    createdAt: nstr(data, 'created_at'),
    completedAt: nstr(data, 'completed_at'),
    refundedAmount: nstr(data, 'refunded_amount'),
    refundedPaymentId: nstr(data, 'refunded_payment_id'),
    raw: data,
  };
}

/** @internal */
export function toPage<T>(body: unknown, map: (item: unknown) => T): Page<T> {
  const object = asObject(body, 'body');
  const data = asArray(object['data'], 'data').map(map);
  const meta = object['meta'] === undefined ? {} : asObject(object['meta'], 'meta');
  const currentPage = nint(meta, 'current_page') ?? 1;
  const lastPage = nint(meta, 'last_page') ?? 1;
  return {
    data,
    currentPage,
    lastPage,
    perPage: nint(meta, 'per_page') ?? data.length,
    total: nint(meta, 'total') ?? data.length,
    hasMore: currentPage < lastPage,
  };
}

/** @internal */
export function toBalance(value: unknown): Balance {
  const data = asObject(value, 'balance');
  return { currency: str(data, 'currency'), balance: str(data, 'balance'), updatedAt: nstr(data, 'updated_at') };
}

/** @internal */
export function toAccount(value: unknown): Account {
  const data = asObject(value, 'account');
  const fees = data['fees'] === undefined ? {} : asObject(data['fees'], 'fees');
  return {
    mode: nstr(data, 'mode') ?? 'unknown',
    merchant: nstr(data, 'merchant'),
    liveEnabled: bool(data, 'live_enabled'),
    kycVerified: bool(data, 'kyc_verified'),
    fees: { depositRate: nstr(fees, 'deposit_rate'), payoutRate: nstr(fees, 'payout_rate') },
    testKey: nstr(data, 'test_key'),
    webhookSecret: nstr(data, 'webhook_secret'),
    webhookSecretPreviousExpiresAt: nstr(data, 'webhook_secret_previous_expires_at'),
  };
}

/** @internal */
export function toWebhookSecret(value: unknown): WebhookSecret {
  const data = asObject(value, 'data');
  return { webhookSecret: str(data, 'webhook_secret'), webhookSecretPreviousExpiresAt: nstr(data, 'webhook_secret_previous_expires_at') };
}

function toLimits(value: unknown): AmountLimits {
  const data = typeof value === 'object' && value !== null ? (value as Json) : {};
  return { minAmount: nstr(data, 'min_amount'), maxAmount: nstr(data, 'max_amount') };
}

/** @internal */
export function toCountry(value: unknown): Country {
  const data = asObject(value, 'country');
  const limits: Record<string, AmountLimits> = {};
  const rawLimits = data['limits'] === undefined || data['limits'] === null ? {} : asObject(data['limits'], 'limits');
  for (const [currency, limit] of Object.entries(rawLimits)) {
    limits[currency] = toLimits(limit);
  }
  const currencies = Array.isArray(data['currencies']) ? data['currencies'].filter((c): c is string => typeof c === 'string') : [];
  return {
    country: str(data, 'country'),
    name: nstr(data, 'name'),
    dialCode: nstr(data, 'dial_code'),
    currencies,
    limits,
    isDefault: bool(data, 'default'),
  };
}

/** @internal */
export function toProvider(value: unknown): Provider {
  const data = asObject(value, 'provider');
  const currencies: Record<string, ProviderCurrency> = {};
  const raw = data['currencies'] === undefined || data['currencies'] === null ? {} : asObject(data['currencies'], 'currencies');
  for (const [currency, rules] of Object.entries(raw)) {
    const r = typeof rules === 'object' && rules !== null ? (rules as Json) : {};
    currencies[currency] = {
      ...toLimits(r),
      decimals: nstr(r, 'decimals'),
      status: nstr(r, 'status'),
      pinPrompt: r['pin_prompt'] ?? null,
      pinPromptRevivable: r['pin_prompt_revivable'] ?? null,
      pinPromptInstructions: r['pin_prompt_instructions'] ?? null,
    };
  }
  return {
    provider: str(data, 'provider'),
    country: nstr(data, 'country'),
    name: nstr(data, 'name'),
    nameDisplayedToCustomer: nstr(data, 'name_displayed_to_customer'),
    logo: nstr(data, 'logo'),
    currencies,
  };
}

/** @internal */
export function toAvailability(provider: string, value: unknown): ProviderAvailability {
  const data = typeof value === 'object' && value !== null ? (value as Json) : {};
  const statuses: Record<string, string | null> = {};
  for (const [type, status] of Object.entries(data)) {
    if (type !== 'country') statuses[type] = typeof status === 'string' ? status : null;
  }
  return { provider, country: nstr(data, 'country'), statuses };
}

/** @internal */
export function toPrediction(value: unknown): ProviderPrediction {
  const data = asObject(value, 'data');
  return { phone: str(data, 'phone'), provider: str(data, 'provider'), country: str(data, 'country') };
}

/** @internal */
export function toNotification(value: unknown): Notification {
  const data = asObject(value, 'notification');
  const id = nint(data, 'id');
  if (id === null) {
    throw new UnexpectedResponseError('Unexpected API payload: "id" should be an integer.', 'UNEXPECTED_RESPONSE');
  }
  return {
    id,
    event: nstr(data, 'event') ?? 'unknown',
    paymentId: nstr(data, 'payment_id'),
    url: nstr(data, 'url'),
    status: nstr(data, 'status') ?? 'unknown',
    attempts: nint(data, 'attempts') ?? 0,
    lastStatusCode: nint(data, 'last_status_code'),
    lastError: nstr(data, 'last_error'),
    nextAttemptAt: nstr(data, 'next_attempt_at'),
    deliveredAt: nstr(data, 'delivered_at'),
    createdAt: nstr(data, 'created_at'),
  };
}

// ─── Aides ───────────────────────────────────────────────────────────────────

/** Deposit: your balance is credited. Payout / refund: the money has left. Deliver only on this status. */
export function isCompleted(payment: Pick<Payment, 'status'>): boolean {
  return payment.status === 'completed';
}

/** Failed or cancelled; see `failure`. */
export function isFailed(payment: Pick<Payment, 'status'>): boolean {
  return payment.status === 'failed';
}

/** `completed` or `failed`: the payment will no longer change. */
export function isFinal(payment: Pick<Payment, 'status'>): boolean {
  return isCompleted(payment) || isFailed(payment);
}

/** A payout queued by the provider (`gateway_status: ENQUEUED`): it can be cancelled. */
export function isQueued(payment: Pick<Payment, 'status' | 'gatewayStatus'>): boolean {
  return payment.status === 'pending' && payment.gatewayStatus === 'ENQUEUED';
}
