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
