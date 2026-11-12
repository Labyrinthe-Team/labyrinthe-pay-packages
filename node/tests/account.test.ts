import { describe, expect, it } from 'vitest';
import { InvalidArgumentError, LabPay } from '@labyrinthe/labpay';
import { client, json, mockFetch } from './helpers.js';

describe('account, providers, notifications', () => {
  it('reads the account and balances, and rotates keys', async () => {
    const http = mockFetch(
      json(200, {
        data: { mode: 'live', merchant: 'Boutique', live_enabled: true, kyc_verified: true, fees: { deposit_rate: '0.0350', payout_rate: '0.0350' }, test_key: 'test_abc', webhook_secret: 'whsec_secret' },
      }),
      json(200, { data: [{ currency: 'CDF', balance: '125000.00', updated_at: '2026-11-11T10:00:42+00:00' }, { currency: 'USD', balance: '0.00', updated_at: null }] }),
      json(200, { data: { webhook_secret: 'whsec_new', webhook_secret_previous_expires_at: '2026-11-12T10:00:00+00:00' } }),
      json(200, { data: { test_key: 'test_new' } }),
    );
    const { labpay } = client(http.fetch, { apiKey: 'live_token_abcdefgh' });

    const account = await labpay.account.get();
    expect(account).toMatchObject({ mode: 'live', liveEnabled: true, fees: { depositRate: '0.0350' }, webhookSecret: 'whsec_secret', webhookSecretPreviousExpiresAt: null });

    expect((await labpay.account.balance('cdf'))?.balance).toBe('125000.00');
    expect(http.last().url).toBe('https://api.example.test/api/V2/balances');

    expect((await labpay.account.rotateWebhookSecret()).webhookSecret).toBe('whsec_new');
    expect(http.last().url).toBe('https://api.example.test/api/V2/account/webhook-secret/rotate');
    expect(await labpay.account.rotateTestKey()).toBe('test_new');
    expect(http.last().url).toBe('https://api.example.test/api/V2/account/test-key/rotate');
  });

  it('reads countries, providers, availability and predictions', async () => {
    const http = mockFetch(
      json(200, {
        data: [{ country: 'COD', name: 'RD Congo', dial_code: '243', currencies: ['CDF', 'USD'], limits: { CDF: { min_amount: '100', max_amount: '20000000' }, USD: { min_amount: null, max_amount: '10000' } }, default: true }],
      }),
      json(200, {
        data: [{ provider: 'VODACOM_MPESA_COD', country: 'COD', name: 'Vodacom M-Pesa', name_displayed_to_customer: 'M-PESA', logo: null, currencies: { CDF: { min_amount: '100', max_amount: '2500000', decimals: 'NONE', status: 'OPERATIONAL', pin_prompt: 'AUTOMATIC', pin_prompt_revivable: false, pin_prompt_instructions: null } } }],
      }),
      json(200, { data: { VODACOM_MPESA_COD: { country: 'COD', DEPOSIT: 'OPERATIONAL', PAYOUT: 'DELAYED' } } }),
      json(200, { data: {} }),
      json(200, { data: { phone: '243813456789', provider: 'VODACOM_MPESA_COD', country: 'COD' } }),
    );
    const { labpay } = client(http.fetch);

    const [country] = await labpay.providers.countries();
    expect(country).toMatchObject({ country: 'COD', dialCode: '243', isDefault: true, limits: { USD: { minAmount: null, maxAmount: '10000' } } });

    const [provider] = await labpay.providers.list({ operation: 'payout', country: 'cod' });
    expect(new URL(http.last().url).search).toBe('?operation=payout&country=COD');
    expect(provider?.currencies['CDF']?.decimals).toBe('NONE');

    const availability = await labpay.providers.availability({ country: 'COD' });
    expect(availability['VODACOM_MPESA_COD']).toEqual({ provider: 'VODACOM_MPESA_COD', country: 'COD', statuses: { DEPOSIT: 'OPERATIONAL', PAYOUT: 'DELAYED' } });
    expect(await labpay.providers.availability()).toEqual({});

    const prediction = await labpay.providers.predict({ phone: '0813456789', country: 'COD' });
    expect(http.last().body).toEqual({ phone: '0813456789', country: 'COD' });
    expect(prediction.phone).toBe('243813456789');
  });

  it('lists and resends notifications', async () => {
    const notification = {
      id: 12, event: 'payment.completed', payment_id: 'DEP-1', url: 'https://boutique.example/webhooks/labpay', status: 'abandoned',
      attempts: 6, last_status_code: 500, last_error: 'HTTP 500', next_attempt_at: null, delivered_at: null, created_at: '2026-11-11T10:00:43+00:00',
    };
    const http = mockFetch(
      json(200, { data: [notification], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
      json(202, { data: { ...notification, id: 13, status: 'pending', attempts: 0 } }),
    );
    const { labpay } = client(http.fetch);

    const page = await labpay.notifications.list({ paymentId: 'DEP-1', status: 'abandoned' });
    expect(new URL(http.last().url).search).toBe('?payment_id=DEP-1&status=abandoned');
    expect(page).toMatchObject({ hasMore: false, total: 1, data: [{ id: 12, lastStatusCode: 500, event: 'payment.completed' }] });

    const resent = await labpay.notifications.resend(12);
    expect(http.last().url).toBe('https://api.example.test/api/V2/notifications/12/resend');
    expect(resent).toMatchObject({ id: 13, status: 'pending' });
  });
});

describe('client', () => {
  it('normalizes the base URL', () => {
    expect(new LabPay({ apiKey: 'test_x' }).baseUrl).toBe('https://api.labyrinthe-rdc.com/api/V2');
    expect(new LabPay({ apiKey: 'test_x', baseUrl: 'http://localhost:8000' }).baseUrl).toBe('http://localhost:8000/api/V2');
    expect(new LabPay({ apiKey: 'test_x', baseUrl: 'https://pay.example/api/V2/' }).baseUrl).toBe('https://pay.example/api/V2');
  });

  it('knows the mode of the key and never serializes it', () => {
    const live = new LabPay({ apiKey: 'live_secret_token_value' });
    expect(live.isTestMode).toBe(false);
    expect(new LabPay({ apiKey: 'test_x' }).isTestMode).toBe(true);
    expect(JSON.stringify(live)).not.toContain('live_secret_token_value');
  });

  it('requires a key', () => {
    const previous = process.env['LABPAY_API_KEY'];
    delete process.env['LABPAY_API_KEY'];
    try {
      expect(() => new LabPay({ apiKey: ' ' })).toThrow(InvalidArgumentError);
    } finally {
      if (previous !== undefined) process.env['LABPAY_API_KEY'] = previous;
    }
  });
});
