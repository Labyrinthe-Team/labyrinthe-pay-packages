import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { parse } from 'yaml';
import { ApiError } from '@labyrinthe/labpay';
import { errorFromResponse } from '../src/errors.js';

/**
 * Le SDK suit la spécification ../openapi/openapi.yaml ; quand le moteur de
 * paiement est présent à côté (labyrinthe-pay-back), ses routes V2 marchandes
 * doivent toutes y figurer.
 */
interface Spec {
  paths: Record<string, Record<string, unknown>>;
  components: { schemas: Record<string, { enum?: string[] }> };
}

const spec = parse(readFileSync(fileURLToPath(new URL('../../openapi/openapi.yaml', import.meta.url)), 'utf8')) as Spec;

/** Opérations appelées par le SDK (méthode + chemin relatif à /V2). */
const SDK_OPERATIONS = [
  'post /deposits',
  'post /payouts',
  'post /payouts/{id}/cancel',
  'post /refunds',
  'get /payments',
  'get /payments/{id}',
  'post /payments/{id}/sync',
  'get /countries',
  'get /providers',
  'get /availability',
  'post /predict-provider',
  'get /balances',
  'get /account',
  'post /account/webhook-secret/rotate',
  'post /account/test-key/rotate',
  'get /notifications',
  'post /notifications/{id}/resend',
];

function specOperations(): string[] {
  return Object.entries(spec.paths).flatMap(([path, item]) => Object.keys(item).map((method) => `${method} ${path}`));
}

describe('OpenAPI contract', () => {
  it('covers exactly the operations of the SDK', () => {
    expect(specOperations().sort()).toEqual([...SDK_OPERATIONS].sort());
  });

  it('resolves every $ref', () => {
    const refs: string[] = [];
    const walk = (node: unknown): void => {
      if (Array.isArray(node)) node.forEach(walk);
      else if (typeof node === 'object' && node !== null) {
        for (const [key, value] of Object.entries(node)) {
          if (key === '$ref' && typeof value === 'string') refs.push(value);
          else walk(value);
        }
      }
    };
    walk(spec);
    expect(refs.length).toBeGreaterThan(50);
    for (const ref of refs) {
      let target: unknown = spec;
      for (const part of ref.replace(/^#\//, '').split('/')) {
        target = (target as Record<string, unknown> | undefined)?.[part];
      }
      expect(target, ref).toBeDefined();
    }
  });

  it('maps every documented error code to a typed error', () => {
    const codes = spec.components.schemas['ErrorCode']?.enum ?? [];
    expect(codes.length).toBeGreaterThan(20);
    for (const code of codes) {
      // Statut neutre : seule la table des codes peut donner une sous-classe.
      const error = errorFromResponse(418, { message: 'x', code }, {});
      expect(error.constructor, code).not.toBe(ApiError);
    }
  });

  it('keeps the enums of the SDK types', () => {
    expect(spec.components.schemas['PaymentStatus']?.enum).toEqual(['pending', 'completed', 'failed']);
    expect(spec.components.schemas['Operation']?.enum).toEqual(['deposit', 'payout', 'refund']);
    expect(spec.components.schemas['NotificationStatus']?.enum).toEqual(['delivered', 'pending', 'abandoned']);
    expect(spec.components.schemas['WebhookEventType']?.enum).toEqual(['payment.completed', 'payment.failed']);
  });

  const routesDir = process.env['LABPAY_ENGINE_ROUTES'] ?? fileURLToPath(new URL('../../../labyrinthe-pay-back/routes/api/v2/', import.meta.url));
  it.skipIf(!existsSync(routesDir))('lists every merchant route of the engine', () => {
    const routes: string[] = [];
    for (const file of ['payments.php', 'toolkit.php']) {
      const source = readFileSync(`${routesDir}/${file}`, 'utf8');
      for (const match of source.matchAll(/Route::(get|post|patch|put|delete)\('([^']+)'/g)) {
        routes.push(`${match[1]} /${match[2]!.replace(/\{[a-z_]+\}/g, '{id}')}`);
      }
    }
    expect(routes.sort()).toEqual(specOperations().sort());
  });
});
