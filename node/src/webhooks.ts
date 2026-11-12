import { InvalidArgumentError, InvalidWebhookPayloadError, LabPayError, SignatureVerificationError } from './errors.js';
import { getCrypto } from './internal/runtime.js';
import { toPayment, type Payment, type WebhookEventType } from './types.js';

/** Header names of a notification. */
export const WEBHOOK_HEADERS = {
  signature: 'x-labyrinthe-signature',
  event: 'x-labyrinthe-event',
  delivery: 'x-labyrinthe-delivery',
  mode: 'x-labyrinthe-mode',
} as const;

export const DEFAULT_TOLERANCE_SECONDS = 300;

/**
 * A verified notification: `{ "event", "payment", "sent_at" }`.
 *
 * The same notification can arrive more than once: deduplicate on the
 * X-Labyrinthe-Delivery header or on `payment.id`.
 */
export interface WebhookEvent {
  /** "payment.completed", "payment.failed", or a newer event. */
  event: WebhookEventType | (string & {});
  payment: Payment;
  sentAt: string | null;
  /** Signed Unix timestamp (`t` of X-Labyrinthe-Signature). */
  timestamp: number;
  /** The decoded body. */
  raw: Record<string, unknown>;
}

export interface VerifyOptions {
  /** Max age of the signed timestamp, in seconds; 0 disables the check (not recommended). Default 300. */
  toleranceSeconds?: number;
  /** Current Unix time in seconds (tests). */
  now?: number;
}

type RawBody = string | Uint8Array | ArrayBuffer;
type Secrets = string | readonly string[];

const encoder = new TextEncoder();

function bytes(body: RawBody): Uint8Array {
  if (typeof body === 'string') return encoder.encode(body);
  return body instanceof Uint8Array ? body : new Uint8Array(body);
}

function secretList(secret: Secrets): string[] {
  const list = (typeof secret === 'string' ? [secret] : [...secret]).filter((s) => s !== '');
  if (list.length === 0) {
    throw new InvalidArgumentError('The webhook signing secret cannot be empty (whsec_…, GET /V2/account with the live token).');
  }
  return list;
}

async function hmacHex(secret: string, timestamp: number, body: Uint8Array): Promise<string> {
  const crypto = await getCrypto();
  const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const prefix = encoder.encode(`${timestamp}.`);
  const message = new Uint8Array(prefix.length + body.length);
  message.set(prefix);
  message.set(body, prefix.length);
  const signature = new Uint8Array(await crypto.subtle.sign('HMAC', key, message));
  return Array.from(signature, (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Comparaison en temps constant de deux chaînes hexadécimales de même longueur. */
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}

function parseHeader(header: string): { timestamp: number | null; signatures: string[] } {
  let timestamp: number | null = null;
  const signatures: string[] = [];
  for (const part of header.split(',')) {
    const index = part.indexOf('=');
    if (index === -1) continue;
    const key = part.slice(0, index).trim();
    const value = part.slice(index + 1).trim();
    if (key === 't' && /^\d{1,12}$/.test(value)) timestamp = Number(value);
    else if (key === 'v1' && /^[0-9a-fA-F]{64}$/.test(value)) signatures.push(value.toLowerCase());
  }
  return { timestamp, signatures };
}

/**
 * Verification of the notifications sent to your `callback_url`.
 *
 * `X-Labyrinthe-Signature: t=<timestamp>,v1=<hex>[,v1=<hex>]`: each v1 is the
 * HMAC-SHA256 of "<timestamp>.<raw body>" keyed with your signing secret
