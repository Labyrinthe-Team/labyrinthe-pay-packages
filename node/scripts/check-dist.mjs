// Vérifie le build ESM : import par le nom du package, API attendue, aucun module Node dans le cœur.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const core = await import('@labyrinthe/labpay');

for (const name of ['LabPay', 'Webhooks', 'LabPayError', 'ApiError', 'RateLimitError', 'SignatureVerificationError', 'VERSION', 'isFinal']) {
  assert.ok(name in core, `ESM export ${name} is missing`);
}
const client = new core.LabPay({ apiKey: 'test_check' });
assert.equal(client.isTestMode, true);
assert.equal(client.baseUrl, 'https://api.labyrinthe-rdc.com/api/V2');
assert.equal(JSON.stringify(client).includes('check'), false);

const body = '{"event":"payment.completed","payment":{"id":"DEP-1","amount":"1.00","currency":"CDF"},"sent_at":null}';
const signature = await core.Webhooks.sign(body, 'whsec_x', 1);
const event = await core.Webhooks.constructEvent(body, signature, 'whsec_x', { toleranceSeconds: 0 });
assert.equal(event.payment.id, 'DEP-1');

for (const file of ['index.js', 'index.cjs']) {
  const code = readFileSync(new URL(`../dist/${file}`, import.meta.url), 'utf8');
  assert.doesNotMatch(code, /(from\s*["']node:|require\(\s*["']node:|import\(\s*["']node:)/, `${file} imports a node: module`);
}
console.log(`ESM OK (${core.VERSION}) on ${process.version}`);
