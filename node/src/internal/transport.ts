/**
 * Envoi des requêtes, politique de relance et conversion des erreurs.
 * Mêmes règles que le SDK PHP (php/src/Internal/Transport.php).
 *
 * @internal
 */
import {
  AbortedError,
  ApiError,
  ConnectionError,
  errorFromResponse,
  LabPayError,
  UnexpectedResponseError,
} from '../errors.js';
import { userAgent } from './runtime.js';

/**
 * Quand une requête peut être renvoyée automatiquement.
 *
 * - `safe` : le rejeu ne produit pas deux fois un effet (GET, predict-provider,
 *   sync, et dépôts / envois / remboursements avec `reference`). Relancée sur
 *   erreur réseau, 5xx et 429 du limiteur.
 * - `throttled-only` : rotations, renvoi de notification, annulation, paiement
 *   sans référence. Relancée seulement sur 429 du limiteur.
 *
 * Dans les deux modes, un 503 qui refuse la requête avant tout traitement
 * (MAINTENANCE, DEPOSITS_DISABLED…) n'est relancé qu'après un Retry-After ≤ maxRetryWait.
 */
export type RetryMode = 'safe' | 'throttled-only';

export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;
export type Sleep = (ms: number, signal?: AbortSignal) => Promise<void>;

/** Statut et en-têtes de la dernière réponse reçue. */
export interface ResponseInfo {
  status: number;
  /** Lower-case names. */
  headers: Record<string, string>;
  /** X-Labyrinthe-Mode, when sent. */
  mode: 'live' | 'test' | null;
}

export interface TransportOptions {
  apiKey: string;
  baseUrl: string;
  timeoutMs: number;
  maxRetries: number;
  maxRetryWaitMs: number;
  fetch: FetchLike;
  sleep: Sleep;
  userAgentSuffix?: string | undefined;
}

export type QueryValue = string | number | boolean | null | undefined;

export interface RequestParams {
  method: 'GET' | 'POST';
  path: string;
  mode: RetryMode;
  query?: Record<string, QueryValue>;
  body?: Record<string, unknown>;
  /** A 422 carrying a payment in `data` (refused at initiation) is returned instead of thrown. */
  paymentOnRefusal?: boolean;
  signal?: AbortSignal | undefined;
}

export interface TransportResponse {
  status: number;
  body: unknown;
  headers: Record<string, string>;
}

const BASE_DELAY_MS = 500;
const MAX_BACKOFF_MS = 8_000;

/** 503 qui refusent la requête avant tout traitement. */
export const REFUSED_BEFORE_PROCESSING: readonly string[] = [
  'MAINTENANCE',
  'API_DISABLED',
  'DEPOSITS_DISABLED',
  'WITHDRAWALS_DISABLED',
  'PROVIDER_TEMPORARILY_UNAVAILABLE',
  'V2_DISABLED',
  'TEST_MODE_DISABLED',
  'API_VERSION_DISABLED',
  'FEATURE_DISABLED',
];

export const defaultSleep: Sleep = (ms, signal) =>
  new Promise((resolve, reject) => {
    if (signal?.aborted === true) {
      reject(new AbortedError('The request was aborted.', 'REQUEST_ABORTED'));
      return;
    }
    const onAbort = (): void => {
      clearTimeout(timer);
      reject(new AbortedError('The request was aborted.', 'REQUEST_ABORTED'));
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort, { once: true });
  });

export class Transport {
  readonly #options: TransportOptions;
  #lastResponse: ResponseInfo | null = null;

  constructor(options: TransportOptions) {
    this.#options = options;
  }

  get lastResponse(): ResponseInfo | null {
    return this.#lastResponse;
  }

  async request(params: RequestParams): Promise<TransportResponse> {
    const { method, path, mode, signal } = params;
    const url = this.#url(path, params.query);
    const payload = params.body === undefined ? undefined : JSON.stringify(params.body);
    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.#options.apiKey}`,
      Accept: 'application/json',
      'User-Agent': this.#options.userAgentSuffix === undefined ? userAgent() : `${userAgent()} ${this.#options.userAgentSuffix}`,
    };
    if (payload !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    for (let attempt = 0; ; attempt++) {
      if (signal?.aborted === true) {
        throw new AbortedError('The request was aborted.', 'REQUEST_ABORTED');
      }

      let response: Response;
      try {
        response = await this.#fetchWithTimeout(url, { method, headers, ...(payload === undefined ? {} : { body: payload }) }, signal);
      } catch (error) {
        if (error instanceof AbortedError) throw error;
        const timeout = error instanceof ConnectionError && error.code === 'TIMEOUT';
        if (mode === 'safe' && attempt < this.#options.maxRetries) {
          await this.#options.sleep(this.#backoff(attempt), signal);
          continue;
        }
        const reason = error instanceof Error ? error.message.replace(/\?[^\s"']*/g, '?…') : String(error);
        let message = `Could not reach the LabPay API (${method} ${path}): ${reason}`;
        if (mode === 'throttled-only') {
          message += ' The request may have been processed; it was not retried automatically.';
        }
        throw new ConnectionError(message, timeout ? 'TIMEOUT' : 'CONNECTION_ERROR');
      }

      const responseHeaders: Record<string, string> = {};
      response.headers.forEach((value, name) => {
        responseHeaders[name.toLowerCase()] = value;
      });
      const text = await response.text();
      const mode_ = responseHeaders['x-labyrinthe-mode'];
      this.#lastResponse = {
        status: response.status,
        headers: responseHeaders,
        mode: mode_ === 'live' || mode_ === 'test' ? mode_ : null,
      };

      if (response.status >= 200 && response.status < 300) {
        return { status: response.status, body: decode(text), headers: responseHeaders };
      }

      const parsed = tryDecode(text);
      if (params.paymentOnRefusal === true && response.status === 422 && hasPayment(parsed)) {
        return { status: response.status, body: parsed, headers: responseHeaders };
      }

      const error = errorFromResponse(response.status, parsed, responseHeaders);
      if (attempt < this.#options.maxRetries) {
        const delay = this.#retryDelay(mode, error, attempt);
        if (delay !== null) {
          await this.#options.sleep(delay, signal);
          continue;
        }
      }
      throw error;
    }
  }

  #url(path: string, query: Record<string, QueryValue> | undefined): string {
    const search = new URLSearchParams();
    for (const [name, value] of Object.entries(query ?? {})) {
      if (value === undefined || value === null || value === '') continue;
      search.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
    const qs = search.toString();
    return `${this.#options.baseUrl}${path}${qs === '' ? '' : `?${qs}`}`;
  }

  async #fetchWithTimeout(url: string, init: RequestInit, signal: AbortSignal | undefined): Promise<Response> {
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, this.#options.timeoutMs);
    const onAbort = (): void => {
      controller.abort();
    };
    signal?.addEventListener('abort', onAbort, { once: true });
    try {
      return await this.#options.fetch(url, { ...init, signal: controller.signal });
    } catch (error) {
      if (signal?.aborted === true) {
        throw new AbortedError('The request was aborted.', 'REQUEST_ABORTED');
      }
      if (timedOut) {
        throw new ConnectionError(`timed out after ${this.#options.timeoutMs} ms`, 'TIMEOUT');
      }
      throw error instanceof LabPayError ? error : new ConnectionError(error instanceof Error ? error.message : String(error), 'CONNECTION_ERROR');
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
    }
  }

  #retryDelay(mode: RetryMode, error: ApiError, attempt: number): number | null {
    const retryAfter = retryAfterMs(error.headers['retry-after']);
    if (REFUSED_BEFORE_PROCESSING.includes(error.code)) {
      return retryAfter !== null && retryAfter <= this.#options.maxRetryWaitMs ? retryAfter : null;
    }
    if (error.status === 429) {
      if (error.code !== 'TOO_MANY_REQUESTS') return null;
      if (retryAfter !== null) return retryAfter <= this.#options.maxRetryWaitMs ? retryAfter : null;
      return this.#backoff(attempt);
    }
    return mode === 'safe' && error.status >= 500 ? this.#backoff(attempt) : null;
  }

  /** Backoff exponentiel avec gigue : 0,5 s, 1 s, 2 s… (50 à 100 %), plafonné. */
  #backoff(attempt: number): number {
    const delay = Math.min(MAX_BACKOFF_MS, BASE_DELAY_MS * 2 ** attempt);
    return Math.min(this.#options.maxRetryWaitMs, delay * (0.5 + Math.random() * 0.5));
  }

  toJSON(): Record<string, unknown> {
    return { baseUrl: this.#options.baseUrl, maxRetries: this.#options.maxRetries };
  }
}

function retryAfterMs(header: string | undefined): number | null {
  if (header === undefined) return null;
  const value = header.trim();
  if (/^\d+(\.\d+)?$/.test(value)) return Number(value) * 1000;
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, date - Date.now());
}

function decode(text: string): unknown {
  if (text.trim() === '') return {};
  try {
    return JSON.parse(text) as unknown;
  } catch {
    throw new UnexpectedResponseError('Unexpected API response: the body is not valid JSON.', 'UNEXPECTED_RESPONSE');
  }
}

function tryDecode(text: string): unknown {
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return null;
  }
}

function hasPayment(body: unknown): boolean {
  if (typeof body !== 'object' || body === null) return false;
  const data = (body as Record<string, unknown>)['data'];
  return typeof data === 'object' && data !== null && 'id' in data;
}
