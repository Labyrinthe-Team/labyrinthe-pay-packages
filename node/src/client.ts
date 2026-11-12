import { InvalidArgumentError } from './errors.js';
import { env, isBrowser } from './internal/runtime.js';
import { defaultSleep, Transport, type FetchLike, type ResponseInfo, type Sleep } from './internal/transport.js';
import { AccountResource, Deposits, Notifications, Payments, Payouts, Providers, Refunds } from './resources.js';

export const DEFAULT_BASE_URL = 'https://api.labyrinthe-rdc.com/api';
export const TEST_KEY_PREFIX = 'test_';

export interface LabPayOptions {
  /** Live token or test key (`test_…`). Defaults to the LABPAY_API_KEY environment variable. Keep it server-side. */
  apiKey?: string;
  /** API root, e.g. "https://api.labyrinthe-rdc.com/api" (default, or LABPAY_BASE_URL); "/V2" is added. */
  baseUrl?: string;
  /** Timeout of each attempt, in milliseconds. Default 30 000. */
  timeoutMs?: number;
  /** Automatic retries when it is safe (0 disables them). Default 2. */
  maxRetries?: number;
  /** Longest wait accepted before a retry (Retry-After), in milliseconds. Default 10 000. */
  maxRetryWaitMs?: number;
  /**
   * Generate a `reference` for payments sent without one, so that they can be
   * retried without risk of a double payment. Default true.
   */
  autoReference?: boolean;
  /** Custom fetch (tests, proxies). Defaults to the global fetch. */
  fetch?: FetchLike;
  /** Waits between retries (inject a no-op one in tests). */
  sleep?: Sleep;
  /** Appended to the User-Agent (integrations). */
  userAgentSuffix?: string;
  /** Allow running in a browser (the key would be exposed). Default false. */
  dangerouslyAllowBrowser?: boolean;
}

function normalizeBaseUrl(value: string): string {
  let url: URL;
  try {
    url = new URL(value.trim());
  } catch {
    throw new InvalidArgumentError('baseUrl must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api.');
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new InvalidArgumentError('baseUrl must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api.');
  }
  let base = `${url.origin}${url.pathname}`.replace(/\/+$/, '');
  // Un hôte nu ("http://localhost:8000") désigne la racine du backend.
  if (url.pathname === '/' || url.pathname === '') base += '/api';
  // "…/api/V2" est accepté comme "…/api".
  if (/\/v2$/i.test(base)) base = base.slice(0, -3);
  return `${base}/V2`;
}

/**
 * LabPay API V2 client.
 *
 * ```ts
 * const labpay = new LabPay({ apiKey: 'test_…' });
 * const payment = await labpay.deposits.create({ amount: '1000', currency: 'CDF', phone: '0813456789', reference: 'ORDER-42' });
 * ```
 *
 * A key starting with `test_` uses the sandbox: no real money moves, test
 * balances and payments are separate. Going live only means switching keys.
 */
export class LabPay {
  readonly deposits: Deposits;
  readonly payouts: Payouts;
  readonly refunds: Refunds;
