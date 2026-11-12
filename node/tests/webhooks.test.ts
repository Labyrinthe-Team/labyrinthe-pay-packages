import { createHmac } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { InvalidWebhookPayloadError, SignatureVerificationError, Webhooks } from '@labyrinthe/labpay';
import { payment } from './helpers.js';

const SECRET = 'whsec_test_secret';
const NOW = 1_794_391_243;
const body = JSON.stringify({
  event: 'payment.completed',
  payment: payment({ status: 'completed', gateway_status: 'COMPLETED', completed_at: '2026-11-11T10:00:42+00:00' }),
  sent_at: '2026-11-11T10:00:43+00:00',
});

describe('Webhooks', () => {
  it('signs like the engine (HMAC-SHA256 of "<t>.<raw body>")', async () => {
    const expected = `t=${NOW},v1=${createHmac('sha256', SECRET).update(`${NOW}.${body}`).digest('hex')}`;
    expect(await Webhooks.sign(body, SECRET, NOW)).toBe(expected);
  });

  it('constructs a verified event from a string or bytes', async () => {
    const header = await Webhooks.sign(body, SECRET, NOW);

    const event = await Webhooks.constructEvent(body, header, SECRET, { now: NOW + 10 });
    expect(event).toMatchObject({ event: 'payment.completed', timestamp: NOW, sentAt: '2026-11-11T10:00:43+00:00', payment: { status: 'completed' } });

    const fromBytes = await Webhooks.constructEvent(new TextEncoder().encode(body), header, SECRET, { now: NOW });
    expect(fromBytes.payment.id).toBe(event.payment.id);
  });

  it('accepts either signature during a rotation', async () => {
    const header = await Webhooks.sign(body, ['whsec_new', SECRET], NOW);

    expect(header.match(/v1=/g)).toHaveLength(2);
    expect(await Webhooks.isValid(body, header, SECRET, { now: NOW })).toBe(true);
    expect(await Webhooks.isValid(body, header, 'whsec_new', { now: NOW })).toBe(true);
    expect(await Webhooks.isValid(body, header, 'whsec_other', { now: NOW })).toBe(false);
    expect(await Webhooks.isValid(body, await Webhooks.sign(body, SECRET, NOW), ['whsec_new', SECRET], { now: NOW })).toBe(true);
  });

  it('refuses a tampered body, an old timestamp and malformed headers', async () => {
    const header = await Webhooks.sign(body, SECRET, NOW);

    await expect(Webhooks.constructEvent(body.replace('1000.00', '9000.00'), header, SECRET, { now: NOW })).rejects.toBeInstanceOf(SignatureVerificationError);
    await expect(Webhooks.verify(body, header, SECRET, { now: NOW + 301 })).rejects.toThrow(/tolerance/);
    expect(await Webhooks.isValid(body, header, SECRET, { now: NOW + 301, toleranceSeconds: 0 })).toBe(true);
    for (const bad of ['', 't=123', `v1=${'a'.repeat(64)}`, 't=123,v1=abc', null, undefined]) {
      await expect(Webhooks.verify(body, bad, SECRET, { now: NOW })).rejects.toBeInstanceOf(SignatureVerificationError);
    }
  });

  it('refuses a signed body that is not a notification', async () => {
    const invalid = '{"event":"payment.completed"}';
    await expect(Webhooks.constructEvent(invalid, await Webhooks.sign(invalid, SECRET, NOW), SECRET, { now: NOW })).rejects.toBeInstanceOf(InvalidWebhookPayloadError);
  });

  it('refuses an empty secret', async () => {
    await expect(Webhooks.verify(body, `t=1,v1=${'a'.repeat(64)}`, '')).rejects.toMatchObject({ code: 'INVALID_ARGUMENT' });
  });
});
