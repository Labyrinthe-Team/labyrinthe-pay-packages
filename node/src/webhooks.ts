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
 * (whsec_…). For 24 h after a rotation the header carries two v1; the
 * notification is accepted when either matches.
 */
export const Webhooks = {
  /**
   * Verifies the signature and the timestamp, then returns the typed event.
   * Pass the RAW body (string or bytes), never a re-encoded object.
   *
   * @throws SignatureVerificationError wrong/missing signature or old timestamp: answer 400
   * @throws InvalidWebhookPayloadError signed body that is not a notification: answer 400
   */
  async constructEvent(rawBody: RawBody, signatureHeader: string | null | undefined, secret: Secrets, options: VerifyOptions = {}): Promise<WebhookEvent> {
    const timestamp = await Webhooks.verify(rawBody, signatureHeader, secret, options);
    const text = typeof rawBody === 'string' ? rawBody : new TextDecoder().decode(bytes(rawBody));
    let body: unknown;
    try {
      body = JSON.parse(text);
    } catch {
      throw new InvalidWebhookPayloadError('The notification body is not valid JSON.');
    }
    if (typeof body !== 'object' || body === null || Array.isArray(body)) {
      throw new InvalidWebhookPayloadError('The notification body is not a JSON object.');
    }
    const object = body as Record<string, unknown>;
    if (typeof object['event'] !== 'string') {
      throw new InvalidWebhookPayloadError('Invalid notification payload: "event" should be a string.');
    }
    let payment: Payment;
    try {
      payment = toPayment(object['payment']);
    } catch (error) {
      const message = error instanceof LabPayError ? error.message.replace('Unexpected API payload', 'Invalid notification payload') : 'Invalid notification payload.';
      throw new InvalidWebhookPayloadError(message);
    }
    return {
      event: object['event'],
      payment,
      sentAt: typeof object['sent_at'] === 'string' ? object['sent_at'] : null,
      timestamp,
      raw: object,
    };
  },

  /**
   * Checks the signature (constant time) and the timestamp; resolves with
   * the signed timestamp.
   *
   * @throws SignatureVerificationError
   */
  async verify(rawBody: RawBody, signatureHeader: string | null | undefined, secret: Secrets, options: VerifyOptions = {}): Promise<number> {
    const secrets = secretList(secret);
    const { timestamp, signatures } = parseHeader(signatureHeader ?? '');
    if (timestamp === null || signatures.length === 0) {
      throw new SignatureVerificationError('Missing or malformed X-Labyrinthe-Signature header (expected "t=<timestamp>,v1=<signature>").');
    }
    const body = bytes(rawBody);
    let valid = false;
    for (const candidate of secrets) {
      const expected = await hmacHex(candidate, timestamp, body);
      for (const signature of signatures) {
        valid = safeEqual(expected, signature) || valid;
      }
    }
    if (!valid) {
      throw new SignatureVerificationError('Invalid notification signature: the body was not signed with this secret.');
    }
    const tolerance = options.toleranceSeconds ?? DEFAULT_TOLERANCE_SECONDS;
    const now = options.now ?? Math.floor(Date.now() / 1000);
    if (tolerance > 0 && Math.abs(now - timestamp) > tolerance) {
      throw new SignatureVerificationError(`Notification timestamp is outside the ${tolerance} s tolerance (possible replay).`);
    }
    return timestamp;
  },

  /** True when the signature and the timestamp are valid. */
  async isValid(rawBody: RawBody, signatureHeader: string | null | undefined, secret: Secrets, options: VerifyOptions = {}): Promise<boolean> {
    try {
      await Webhooks.verify(rawBody, signatureHeader, secret, options);
      return true;
    } catch (error) {
      if (error instanceof SignatureVerificationError) return false;
      throw error;
    }
  },

  /** The X-Labyrinthe-Signature value LabPay would send (one v1 per secret): to test your endpoint. */
  async sign(rawBody: RawBody, secret: Secrets, timestamp: number = Math.floor(Date.now() / 1000)): Promise<string> {
    const body = bytes(rawBody);
    const parts = [`t=${timestamp}`];
    for (const candidate of secretList(secret)) {
      parts.push(`v1=${await hmacHex(candidate, timestamp, body)}`);
    }
    return parts.join(',');
  },
};
