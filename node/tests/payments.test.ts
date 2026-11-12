import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, isFinal, isQueued, LabPay } from '@labyrinthe/labpay';
import { client, json, mockFetch, payment } from './helpers.js';

describe('deposits, payouts, refunds', () => {
  it('sends the documented body and headers', async () => {
    const http = mockFetch(json(201, { data: payment() }));
    const { labpay } = client(http.fetch);

    const result = await labpay.deposits.create({
      amount: 1000,
      currency: 'cdf',
      phone: '0813456789',
      reference: 'ORDER-42',
      callbackUrl: 'https://boutique.example/webhooks/labpay',
      customerMessage: 'Commande 42',
      metadata: { orderId: 42 },
    });

    const request = http.last();
    expect(request.method).toBe('POST');
    expect(request.url).toBe('https://api.example.test/api/V2/deposits');
    expect(request.headers['authorization']).toBe('Bearer test_abcdefghijklmnop');
    expect(request.headers['content-type']).toBe('application/json');
    expect(request.headers['user-agent']).toMatch(/^labpay-node\//);
    expect(request.body).toEqual({
      amount: '1000',
      currency: 'CDF',
      phone: '0813456789',
      reference: 'ORDER-42',
      callback_url: 'https://boutique.example/webhooks/labpay',
      customer_message: 'Commande 42',
      metadata: { orderId: '42' },
    });
    expect(result.id).toBe('DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6');
    expect(result.totalAmount).toBe('1035.00');
    expect(result.livemode).toBe(false);
    expect(isFinal(result)).toBe(false);
    expect(labpay.lastResponse?.mode).toBe('test');
  });

  it('generates a reference when missing and keeps it across retries', async () => {
    const http = mockFetch(new Response('', { status: 500 }), json(201, { data: payment() }));
    const { labpay } = client(http.fetch);

    await labpay.deposits.create({ amount: '12.50', currency: 'USD', phone: '+243813456789' });

    expect(http.requests).toHaveLength(2);
    const first = http.requests[0]!.body as Record<string, unknown>;
    expect(first['reference']).toMatch(/^sdk_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(http.requests[1]!.body).toEqual(first);
  });

  it('does not retry a payment without reference after a network error', async () => {
    const http = mockFetch(new TypeError('fetch failed'));
    const { labpay } = client(http.fetch, { autoReference: false });

    await expect(labpay.payouts.create({ amount: '5000', currency: 'CDF', phone: '0813456789' })).rejects.toMatchObject({
      name: 'ConnectionError',
      code: 'CONNECTION_ERROR',
    });
    expect(http.requests).toHaveLength(1);
    expect(http.requests[0]!.url).toBe('https://api.example.test/api/V2/payouts');
    expect(http.requests[0]!.body).not.toHaveProperty('reference');
  });

  it('returns a payment refused at initiation instead of throwing', async () => {
    const http = mockFetch(json(422, { data: payment({ status: 'failed', failure: { code: 'PAYER_LIMIT_REACHED', message: 'Limite' } }) }));
    const { labpay } = client(http.fetch);

    const result = await labpay.deposits.create({ amount: '1000', currency: 'CDF', phone: '243813456019' });

    expect(result.status).toBe('failed');
