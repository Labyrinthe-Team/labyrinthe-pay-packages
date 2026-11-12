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
