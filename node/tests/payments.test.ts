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
    expect(result.failure).toEqual({ code: 'PAYER_LIMIT_REACHED', message: 'Limite' });
    expect(labpay.lastResponse?.status).toBe(422);
  });

  it('creates a refund', async () => {
    const http = mockFetch(json(201, { data: payment({ id: 'RFD-1', operation: 'refund', refunded_payment_id: 'DEP-1' }) }));
    const { labpay } = client(http.fetch);

    const refund = await labpay.refunds.create({ paymentId: 'DEP-1', amount: '300', reference: 'REFUND-42-1' });

    expect(http.last().body).toEqual({ payment_id: 'DEP-1', amount: '300', reference: 'REFUND-42-1' });
    expect(refund.refundedPaymentId).toBe('DEP-1');
  });

  it('gets, syncs and cancels', async () => {
    const http = mockFetch(
      json(200, { data: payment({ id: 'PAY-1', operation: 'payout', gateway_status: 'ENQUEUED' }) }),
      json(200, { data: payment({ id: 'PAY-1', operation: 'payout' }) }),
      json(200, { data: payment({ status: 'completed', completed_at: '2026-11-11T10:00:42+00:00' }) }),
    );
    const { labpay } = client(http.fetch);

    expect(isQueued(await labpay.payments.get('PAY-1'))).toBe(true);
    expect(http.last().url).toBe('https://api.example.test/api/V2/payments/PAY-1');
    await labpay.payouts.cancel('PAY-1');
    expect(http.last().url).toBe('https://api.example.test/api/V2/payouts/PAY-1/cancel');
    const synced = await labpay.payments.sync('DEP-1');
    expect(http.last()).toMatchObject({ method: 'POST', url: 'https://api.example.test/api/V2/payments/DEP-1/sync' });
    expect(synced.completedAt).toBe('2026-11-11T10:00:42+00:00');
  });

  it('lists and iterates over pages', async () => {
    const meta = { current_page: 1, last_page: 2, per_page: 1, total: 2 };
    const http = mockFetch(
      json(200, { data: [payment()], links: {}, meta }),
      json(200, { data: [payment({ id: 'DEP-2' })], links: {}, meta: { ...meta, current_page: 2 } }),
    );
    const { labpay } = client(http.fetch);

    const ids: string[] = [];
    for await (const p of labpay.payments.iterate({ status: 'completed', operation: 'deposit', from: new Date('2026-11-01T00:00:00Z'), perPage: 1 })) {
      ids.push(p.id);
    }

    expect(ids).toEqual(['DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', 'DEP-2']);
    expect(new URL(http.last().url).search).toBe('?status=completed&operation=deposit&from=2026-11-01&per_page=1&page=2');
  });

  it('finds by reference', async () => {
    const http = mockFetch(json(200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 1, total: 0 } }));
    const { labpay } = client(http.fetch);

    expect(await labpay.payments.findByReference('ORDER-404')).toBeNull();
    expect(new URL(http.last().url).search).toBe('?reference=ORDER-404&per_page=1');
  });

  it.each([
    ['zero amount', (l: LabPay) => l.deposits.create({ amount: '0', currency: 'CDF', phone: '0813456789' })],
    ['three decimals', (l: LabPay) => l.deposits.create({ amount: 1.234, currency: 'CDF', phone: '0813456789' })],
    ['bad currency', (l: LabPay) => l.deposits.create({ amount: '10', currency: 'FC', phone: '0813456789' })],
    ['bad reference', (l: LabPay) => l.deposits.create({ amount: '10', currency: 'CDF', phone: '0813456789', reference: 'order 42' })],
    ['http callback', (l: LabPay) => l.deposits.create({ amount: '10', currency: 'CDF', phone: '0813456789', callbackUrl: 'http://example.test' })],
    ['short message', (l: LabPay) => l.deposits.create({ amount: '10', currency: 'CDF', phone: '0813456789', customerMessage: 'Hi' })],
    ['per page', (l: LabPay) => l.payments.list({ perPage: 101 })],
    ['date', (l: LabPay) => l.payments.list({ from: '01/11/2026' })],
  ])('rejects %s before any request', async (_name, call) => {
    const http = mockFetch();
    const { labpay } = client(http.fetch);

    await expect(call(labpay)).rejects.toBeInstanceOf(InvalidArgumentError);
    expect(http.requests).toHaveLength(0);
  });
});
