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
