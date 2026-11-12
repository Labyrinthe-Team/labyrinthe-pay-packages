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
  readonly payments: Payments;
  readonly providers: Providers;
  readonly account: AccountResource;
  readonly notifications: Notifications;
  /** Base URL of the V2 API, e.g. "https://api.labyrinthe-rdc.com/api/V2". */
  readonly baseUrl: string;

  readonly #apiKey: string;
  readonly #transport: Transport;

  constructor(options: LabPayOptions = {}) {
    const apiKey = (options.apiKey ?? env('LABPAY_API_KEY') ?? '').trim();
    if (apiKey === '') {
      throw new InvalidArgumentError('A LabPay API token or test key (test_…) is required (apiKey option or LABPAY_API_KEY).');
    }
    if (isBrowser() && options.dangerouslyAllowBrowser !== true) {
      throw new InvalidArgumentError('The LabPay SDK must run server-side: the API key would be exposed in a browser.');
    }
    const timeoutMs = options.timeoutMs ?? 30_000;
    const maxRetries = options.maxRetries ?? 2;
    const maxRetryWaitMs = options.maxRetryWaitMs ?? 10_000;
    if (!(timeoutMs > 0)) throw new InvalidArgumentError('timeoutMs must be greater than 0.');
    if (!Number.isInteger(maxRetries) || maxRetries < 0 || maxRetries > 10) throw new InvalidArgumentError('maxRetries must be an integer between 0 and 10.');
    if (!(maxRetryWaitMs >= 0)) throw new InvalidArgumentError('maxRetryWaitMs cannot be negative.');

    const fetchImpl = options.fetch ?? (typeof globalThis.fetch === 'function' ? globalThis.fetch.bind(globalThis) : undefined);
    if (fetchImpl === undefined) {
      throw new InvalidArgumentError('No fetch implementation available: use Node 18+ or pass the fetch option.');
    }

    this.#apiKey = apiKey;
    this.baseUrl = normalizeBaseUrl(options.baseUrl ?? env('LABPAY_BASE_URL') ?? DEFAULT_BASE_URL);
    this.#transport = new Transport({
      apiKey,
      baseUrl: this.baseUrl,
      timeoutMs,
      maxRetries,
      maxRetryWaitMs,
      fetch: fetchImpl,
      sleep: options.sleep ?? defaultSleep,
      userAgentSuffix: options.userAgentSuffix,
    });

    const autoReference = options.autoReference ?? true;
    this.deposits = new Deposits(this.#transport, autoReference);
    this.payouts = new Payouts(this.#transport, autoReference);
    this.refunds = new Refunds(this.#transport, autoReference);
    this.payments = new Payments(this.#transport, autoReference);
    this.providers = new Providers(this.#transport, autoReference);
    this.account = new AccountResource(this.#transport, autoReference);
    this.notifications = new Notifications(this.#transport, autoReference);
  }

  /** True with a test key (`test_…`): sandbox, no real money, separate balances. */
  get isTestMode(): boolean {
    return this.#apiKey.startsWith(TEST_KEY_PREFIX);
  }

  /** Status and headers of the last response received (e.g. its X-Labyrinthe-Mode). */
  get lastResponse(): ResponseInfo | null {
    return this.#transport.lastResponse;
  }

  toJSON(): Record<string, unknown> {
    return { baseUrl: this.baseUrl, testMode: this.isTestMode };
  }
}
