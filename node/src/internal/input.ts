/**
 * Contrôles locaux reprenant les règles de validation de l'API
 * (`MobileMoneyPaymentRequest`, `InitiateRefundRequest`, `ListPaymentsRequest`) :
 * une erreur évidente échoue avant tout appel réseau.
 *
 * @internal
 */
import { InvalidArgumentError } from '../errors.js';

export const MAX_PER_PAGE = 100;
const AMOUNT = /^\d{1,16}(\.\d{1,2})?$/;
const REFERENCE = /^[A-Za-z0-9._:-]{1,100}$/;
const CUSTOMER_MESSAGE = /^[A-Za-z0-9 ]{4,22}$/;

export function amount(value: string | number, field = 'amount'): string {
  let text: string;
  if (typeof value === 'number') {
    if (!Number.isFinite(value) || Math.round(value * 100) / 100 !== value) {
      throw new InvalidArgumentError(`${field} must have at most 2 decimals; pass a string such as "12.50".`);
    }
    text = Number.isInteger(value) ? String(value) : value.toFixed(2);
  } else {
    text = value.trim();
  }
  if (!AMOUNT.test(text) || /^0+(\.0+)?$/.test(text)) {
    throw new InvalidArgumentError(`${field} must be a positive number with at most 2 decimals (e.g. "1500" or "12.50").`);
  }
  return text;
}

export function currency(value: string): string {
  const code = value.trim().toUpperCase();
  if (!/^[A-Z]{3}$/.test(code)) throw new InvalidArgumentError('currency must be a 3-letter code, e.g. "CDF" or "USD".');
  return code;
}

export function country(value: string | undefined | null): string | undefined {
  if (value === undefined || value === null || value.trim() === '') return undefined;
  const code = value.trim().toUpperCase();
  if (!/^[A-Z]{3}$/.test(code)) throw new InvalidArgumentError('country must be an ISO 3166-1 alpha-3 code, e.g. "COD".');
  return code;
}

export function phone(value: string): string {
  const text = value.trim();
  if (text === '' || text.length > 20) throw new InvalidArgumentError('phone is required and must be at most 20 characters.');
  return text;
}

export function provider(value: string | undefined | null): string | undefined {
  if (value === undefined || value === null || value.trim() === '') return undefined;
  if (!/^[A-Za-z0-9_]{1,64}$/.test(value.trim())) throw new InvalidArgumentError('provider must be a provider code, e.g. "VODACOM_MPESA_COD".');
  return value.trim();
}

export function reference(value: string | undefined | null): string | undefined {
  if (value === undefined || value === null) return undefined;
  if (!REFERENCE.test(value)) throw new InvalidArgumentError('reference must be 1 to 100 characters among A-Z a-z 0-9 . _ : -');
  return value;
}

export function customerMessage(value: string | undefined | null): string | undefined {
  if (value === undefined || value === null || value === '') return undefined;
  if (!CUSTOMER_MESSAGE.test(value)) throw new InvalidArgumentError('customerMessage must be 4 to 22 characters (letters, digits, spaces).');
  return value;
}

export function callbackUrl(value: string | undefined | null): string | undefined {
  if (value === undefined || value === null || value.trim() === '') return undefined;
  const url = value.trim();
  let valid = false;
  try {
    valid = new URL(url).protocol === 'https:' && url.length <= 2048;
  } catch {
    valid = false;
  }
  if (!valid) throw new InvalidArgumentError('callbackUrl must be a public HTTPS URL (at most 2048 characters).');
  return url;
}

export function metadata(value: Record<string, unknown> | undefined | null): Record<string, string | null> | undefined {
  if (value === undefined || value === null) return undefined;
  const entries = Object.entries(value);
  if (entries.length > 20) throw new InvalidArgumentError('metadata accepts at most 20 entries.');
  const result: Record<string, string | null> = {};
  for (const [key, item] of entries) {
    if (item === null) {
      result[key] = null;
      continue;
    }
    if (typeof item !== 'string' && typeof item !== 'number' && typeof item !== 'boolean') {
      throw new InvalidArgumentError(`metadata["${key}"] must be a string.`);
    }
    const text = String(item);
    if (text.length > 255) throw new InvalidArgumentError(`metadata["${key}"] must be at most 255 characters.`);
    result[key] = text;
  }
  return result;
}

export function id(value: string, field = 'id'): string {
  const text = value.trim();
  if (text === '' || text.length > 64) throw new InvalidArgumentError(`${field} must be a LabPay identifier, e.g. "DEP-01JCX…".`);
  return encodeURIComponent(text);
}

export function perPage(value: number | undefined): number | undefined {
  if (value !== undefined && (!Number.isInteger(value) || value < 1 || value > MAX_PER_PAGE)) {
    throw new InvalidArgumentError(`perPage must be an integer between 1 and ${MAX_PER_PAGE}.`);
  }
  return value;
}

export function page(value: number | undefined): number | undefined {
  if (value !== undefined && (!Number.isInteger(value) || value < 1)) throw new InvalidArgumentError('page must be an integer, 1 or more.');
  return value;
}

export function date(value: string | Date | undefined, field: string): string | undefined {
  if (value === undefined) return undefined;
  if (value instanceof Date) return value.toISOString().slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) throw new InvalidArgumentError(`${field} must be a date in YYYY-MM-DD format.`);
  return value;
}

export function oneOf<T extends string>(value: string | undefined, allowed: readonly T[], field: string): T | undefined {
  if (value === undefined) return undefined;
  if (!(allowed as readonly string[]).includes(value)) throw new InvalidArgumentError(`${field} must be one of: ${allowed.join(', ')}.`);
  return value as T;
}

/** Retire les clés `undefined` (exactOptionalPropertyTypes, corps JSON propre). */
export function compact(object: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(object).filter(([, v]) => v !== undefined));
}
