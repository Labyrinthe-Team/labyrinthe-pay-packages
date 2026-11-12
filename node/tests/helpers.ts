import { LabPay, type LabPayOptions } from '@labyrinthe/labpay';

export interface Recorded {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

type Reply = Response | Error;

/** fetch simulé : répond depuis une file ; une Error simule une erreur réseau. */
export function mockFetch(...replies: Reply[]) {
  const requests: Recorded[] = [];
  const queue = [...replies];
  const fetch = async (url: string, init: RequestInit): Promise<Response> => {
    const headers: Record<string, string> = {};
    new Headers(init.headers).forEach((value, name) => {
      headers[name] = value;
    });
    requests.push({ url, method: init.method ?? 'GET', headers, body: typeof init.body === 'string' ? JSON.parse(init.body) : undefined });
    const reply = queue.shift();
    if (reply === undefined) throw new Error(`No response queued for ${init.method ?? 'GET'} ${url}`);
    if (reply instanceof Error) throw reply;
    return reply;
  };
  return { fetch, requests, last: () => requests[requests.length - 1]! };
}

export function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', 'X-Labyrinthe-Mode': 'test', ...headers } });
}

export function client(fetch: LabPayOptions['fetch'], options: Partial<LabPayOptions> = {}) {
  const sleeps: number[] = [];
  const labpay = new LabPay({
    apiKey: 'test_abcdefghijklmnop',
    baseUrl: 'https://api.example.test/api',
    ...(fetch === undefined ? {} : { fetch }),
    sleep: async (ms) => {
      sleeps.push(ms);
    },
    ...options,
  });
  return { labpay, sleeps };
}

export function payment(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6',
    reference: 'ORDER-42',
    operation: 'deposit',
    livemode: false,
    status: 'pending',
    gateway_status: 'ACCEPTED',
    amount: '1000.00',
    fee: '35.00',
    total_amount: '1035.00',
    currency: 'CDF',
    country: 'COD',
    phone: '0813456789',
    provider: 'VODACOM_MPESA_COD',
    provider_transaction_id: null,
    failure: null,
    metadata: { orderId: '42' },
    created_at: '2026-11-11T10:00:00+00:00',
    completed_at: null,
    refunded_amount: '0.00',
    ...overrides,
  };
}
