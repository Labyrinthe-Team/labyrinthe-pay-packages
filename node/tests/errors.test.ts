import { describe, expect, it } from 'vitest';
import {
  AmountOutOfRangeError,
  ApiError,
  AuthenticationError,
  ConflictError,
  DailyLimitReachedError,
  InsufficientBalanceError,
  InvalidPhoneNumberError,
  InvalidRequestError,
  LabPay,
  LabPayError,
  NotFoundError,
  PermissionError,
  RateLimitError,
  ServiceUnavailableError,
  UnexpectedResponseError,
  ValidationError,
} from '@labyrinthe/labpay';
import { client, json, mockFetch, payment } from './helpers.js';

describe('typed errors', () => {
  it.each([
    [401, 'UNAUTHENTICATED', AuthenticationError],
    [403, 'KYC_REQUIRED', PermissionError],
    [403, 'LIVE_TOKEN_REQUIRED', PermissionError],
    [404, 'PAYMENT_NOT_FOUND', NotFoundError],
    [409, 'REFUND_IN_PROGRESS', ConflictError],
    [422, 'INVALID_PHONE_NUMBER', InvalidPhoneNumberError],
    [422, 'INVALID_CURRENCY', InvalidRequestError],
    [422, 'AMOUNT_TOO_SMALL', AmountOutOfRangeError],
    [422, 'INSUFFICIENT_BALANCE', InsufficientBalanceError],
    [429, 'DAILY_LIMIT_REACHED', DailyLimitReachedError],
    [503, 'MAINTENANCE', ServiceUnavailableError],
    [418, 'SOMETHING_NEW', ApiError],
  ])('%i %s', async (status, code, ErrorClass) => {
    const http = mockFetch(json(status, { message: 'Message en français', code }));
    const { labpay } = client(http.fetch, { maxRetries: 0 });

    const error = await labpay.payments.get('DEP-1').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ErrorClass);
    expect(error).toBeInstanceOf(LabPayError);
    expect((error as ApiError).constructor).toBe(ErrorClass);
    expect(error).toMatchObject({ code, status, message: 'Message en français', mode: 'test' });
  });

  it('exposes validation errors', async () => {
    const http = mockFetch(json(422, { message: 'Montant invalide', code: 'VALIDATION_ERROR', errors: { amount: ['Montant invalide'] } }));
    const { labpay } = client(http.fetch);

    const error = await labpay.deposits.create({ amount: '10', currency: 'CDF', phone: '0813456789' }).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ValidationError);
    expect((error as ValidationError).errors).toEqual({ amount: ['Montant invalide'] });
  });

  it('maps a 422 without code but with errors to a validation error', async () => {
    const http = mockFetch(json(422, { message: 'The selected status is invalid.', errors: { status: ['The selected status is invalid.'] } }));
    const { labpay } = client(http.fetch);

    await expect(labpay.notifications.list()).rejects.toMatchObject({ name: 'ValidationError', code: 'VALIDATION_ERROR' });
  });

  it('rejects a body that is not JSON', async () => {
    const http = mockFetch(new Response('not json', { status: 200 }));
    const { labpay } = client(http.fetch);

    await expect(labpay.account.get()).rejects.toBeInstanceOf(UnexpectedResponseError);
  });
});

describe('retries', () => {
  it('retries the rate limiter after Retry-After', async () => {
    const http = mockFetch(json(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '3' }), json(200, { data: payment() }));
    const { labpay, sleeps } = client(http.fetch);

    await labpay.payments.get('DEP-1');

    expect(http.requests).toHaveLength(2);
    expect(sleeps).toEqual([3000]);
  });

  it('throws when Retry-After is longer than maxRetryWaitMs', async () => {
    const http = mockFetch(json(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '60' }));
    const { labpay } = client(http.fetch);

    const error = await labpay.payments.get('DEP-1').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(RateLimitError);
    expect(error).toMatchObject({ code: 'TOO_MANY_REQUESTS', retryAfter: 60 });
    expect(http.requests).toHaveLength(1);
  });

  it('never retries the daily limit', async () => {
    const http = mockFetch(json(429, { message: 'Limite', code: 'DAILY_LIMIT_REACHED' }, { 'Retry-After': '1' }));
    const { labpay } = client(http.fetch);

    await expect(labpay.deposits.create({ amount: '10', currency: 'CDF', phone: '0813456789' })).rejects.toBeInstanceOf(DailyLimitReachedError);
    expect(http.requests).toHaveLength(1);
  });

  it('retries server errors on reads with backoff', async () => {
    const http = mockFetch(json(503, { message: 'Indisponible', code: 'GATEWAY_UNAVAILABLE' }), new Response('<html>', { status: 502 }), json(200, { data: [] }));
    const { labpay, sleeps } = client(http.fetch);

    expect(await labpay.providers.list()).toEqual([]);
    expect(http.requests).toHaveLength(3);
    expect(sleeps).toHaveLength(2);
  });

  it('only retries maintenance with a short Retry-After', async () => {
    const http = mockFetch(
      json(503, { message: 'Maintenance', code: 'MAINTENANCE' }, { 'Retry-After': '2' }),
      json(503, { message: 'Maintenance', code: 'MAINTENANCE' }),
    );
    const { labpay, sleeps } = client(http.fetch);

    await expect(labpay.payments.get('DEP-1')).rejects.toBeInstanceOf(ServiceUnavailableError);
    expect(http.requests).toHaveLength(2);
    expect(sleeps).toEqual([2000]);
  });

  it('never retries rotations after a server error', async () => {
    const http = mockFetch(new Response('', { status: 500 }));
    const { labpay } = client(http.fetch, { apiKey: 'live_token_abcdefgh' });

    await expect(labpay.account.rotateWebhookSecret()).rejects.toBeInstanceOf(ServiceUnavailableError);
    expect(http.requests).toHaveLength(1);
  });

  it('reports a timeout without leaking the query string', async () => {
    const fetch = (_url: string, init: RequestInit) =>
      new Promise<Response>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => {
          reject(new Error('aborted ?phone=0813456789'));
        });
      });
    const labpay = new LabPay({ apiKey: 'test_x', fetch, timeoutMs: 10, maxRetries: 0 });

    const error = await labpay.payments.list({ reference: 'ORDER-1' }).catch((e: unknown) => e);

    expect(error).toMatchObject({ name: 'ConnectionError', code: 'TIMEOUT' });
    expect(String((error as Error).message)).not.toContain('ORDER-1');
  });

  it('honours an AbortSignal', async () => {
    const http = mockFetch();
    const { labpay } = client(http.fetch);
    const controller = new AbortController();
    controller.abort();

    await expect(labpay.payments.get('DEP-1', { signal: controller.signal })).rejects.toMatchObject({ code: 'REQUEST_ABORTED' });
    expect(http.requests).toHaveLength(0);
  });
});
