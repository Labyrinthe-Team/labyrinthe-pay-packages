// Génère la collection Postman v2.1 depuis ../openapi/openapi.yaml (openapi-to-postmanv2),
// puis l'enrichit : variables, auth Bearer, scripts de test, enchaînement dépôt → lecture / sync.
//
//   npm run generate   → écrit labpay.postman_collection.json
//   npm run check      → régénère en mémoire, échoue si le fichier versionné n'est pas à jour
//
// La sortie est déterministe : Math.random est remplacé par un générateur à graine fixe pendant
// la conversion (valeurs d'exemple factices), et tous les identifiants sont dérivés d'un hash stable.
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse } from 'yaml';

const root = new URL('..', import.meta.url);
const specPath = fileURLToPath(new URL('../openapi/openapi.yaml', root));
const outputPath = fileURLToPath(new URL('labpay.postman_collection.json', root));
const check = process.argv.includes('--check');

const specText = readFileSync(specPath, 'utf8');
const spec = parse(specText);

// Dossiers dans l'ordre d'exécution ; les rotations de clés sont à part, en dernier.
const ROTATION_FOLDER = 'Account — key rotation (live token only)';
const FOLDERS = ['Toolkit', 'Account', 'Payments', 'Notifications', ROTATION_FOLDER];
const ROTATIONS = ['rotateWebhookSecret', 'rotateTestKey'];

// Ordre du parcours nominal, par operationId (le dépôt avant sa lecture, sa synchronisation et son remboursement).
const ORDER = [
  'listCountries',
  'listProviders',
  'getAvailability',
  'predictProvider',
  'getAccount',
  'listBalances',
  'createDeposit',
  'getPayment',
  'syncPayment',
  'listPayments',
  'createRefund',
  'createPayout',
  'cancelPayout',
  'listNotifications',
  'resendNotification',
  'rotateWebhookSecret',
  'rotateTestKey',
];

// Opérations qui doivent réussir (2xx) avec une clé de test ; les autres acceptent aussi les refus
// documentés qui dépendent du compte (dépôt pas encore confirmé, solde de test vide, clé de test…).
const MUST_SUCCEED = new Set([
  'listCountries',
  'listProviders',
  'getAvailability',
  'predictProvider',
  'getAccount',
  'listBalances',
  'createDeposit',
  'getPayment',
  'syncPayment',
  'listPayments',
  'listNotifications',
]);

// Statut final d'un paiement réussi (exemples de réponses).
const COMPLETED = { status: 'completed', provider_transaction_id: 'MP2611110001', completed_at: '2026-11-11T10:00:42+00:00' };

const collection = await convert(specText);
enrich(collection);
const output = `${JSON.stringify(sortKeys(collection), null, 2)}\n`;

if (check) {
  let current = '';
  try {
    current = readFileSync(outputPath, 'utf8').replace(/\r\n/g, '\n');
  } catch {
    // fichier absent : comparé à une chaîne vide
  }
  if (current !== output) {
    console.error('labpay.postman_collection.json is out of date: run `npm run generate` and commit the result.');
    process.exit(1);
  }
  console.log('Postman collection is up to date.');
} else {
  writeFileSync(outputPath, output);
  console.log(`Wrote ${outputPath}`);
}

// ---------------------------------------------------------------------------

async function convert(data) {
  const random = Math.random;
  const warn = console.warn;
  // Graine fixée AVANT le chargement du convertisseur : son générateur de valeurs factices capture Math.random.
  Math.random = seededRandom(0x1ab9a7);
  const { default: Converter } = await import('openapi-to-postmanv2');
  // Avertissements de fusion de schémas (oneOf des réponses) : sans effet sur les requêtes.
  console.warn = () => {};
  // Les dates factices sont calculées depuis « maintenant » : horloge figée pendant la conversion.
  const RealDate = Date;
  const FIXED_NOW = RealDate.UTC(2026, 10, 11);
  globalThis.Date = class extends RealDate {
    constructor(...args) {
      super(...(args.length === 0 ? [FIXED_NOW] : args));
    }

    static now() {
      return FIXED_NOW;
    }
  };
  try {
    const result = await new Promise((resolve, reject) => {
      Converter.convert(
        { type: 'string', data },
        {
          folderStrategy: 'Tags',
          requestParametersResolution: 'Example',
          exampleParametersResolution: 'Example',
          parametersResolution: 'Example',
          includeAuthInfoInExample: false,
          optimizeConversion: false,
          stackLimit: 10,
        },
        (error, res) => (error ? reject(error) : resolve(res)),
      );
    });
    if (!result.result) {
      throw new Error(`Conversion failed: ${result.reason}`);
    }
    return result.output[0].data;
  } finally {
    Math.random = random;
    console.warn = warn;
    globalThis.Date = RealDate;
  }
}

function enrich(c) {
  c.info._postman_id = stableId('collection');
  c.info.name = 'LabPay API V2';
  c.info.description = {
    type: 'text/markdown',
    content: [
      `Generated from \`openapi/openapi.yaml\` (spec ${spec.info.version}). Do not edit by hand: run \`npm run generate\` in \`postman/\`.`,
      '',
      'Select an environment (`local` or `production`) and set its `token` variable (secret) to a **test key**',
      '(`test_…`): sandbox, no real money moves, separate balances and payments. Every request uses',
      '`{{baseUrl}}/V2/…` and inherits the Bearer auth of the collection.',
      '',
      'Run the collection in order (Collection Runner or Newman) to play the nominal flow: toolkit, account,',
      'a deposit (its `id` is saved in `paymentId`), its reading, its sync, the list, then notifications.',
      `The "${ROTATION_FOLDER}" folder rotates real secrets: with a test key it only returns \`403 LIVE_TOKEN_REQUIRED\`.`,
      '',
      '---',
      '',
      spec.info.description.trim(),
    ].join('\n'),
  };

  c.variable = [
    { key: 'baseUrl', value: 'http://localhost:8000/api', type: 'string', description: 'API root without /V2 (see the environments).' },
    { key: 'token', value: '', type: 'secret', description: 'Test key test_… (sandbox) or live token. Never commit it.' },
    { key: 'reference', value: '', type: 'string', description: 'Unique reference generated before each payment request (idempotency key).' },
    { key: 'paymentId', value: '', type: 'string', description: 'Set by "POST /deposits", used by the payment, sync and refund requests.' },
    { key: 'payoutId', value: '', type: 'string', description: 'Set by "POST /payouts", used by "POST /payouts/:id/cancel".' },
    { key: 'notificationId', value: '', type: 'string', description: 'Set by "GET /notifications" (first notification), used by the resend request.' },
  ];
  c.auth = { type: 'bearer', bearer: [{ key: 'token', value: '{{token}}', type: 'string' }] };
  c.event = [];

  const operations = operationsByRoute();
  const rotationItems = [];

  for (const folder of c.item) {
    folder.id = stableId(`folder:${folder.name}`);
    const tag = spec.tags.find((t) => t.name === folder.name);
    if (tag) folder.description = { type: 'text/markdown', content: tag.description.trim() };

    for (const item of folder.item) {
      const path = item.request.url.path.map((p) => (p.startsWith(':') ? `{${p.slice(1)}}` : p)).join('/');
      const route = `${item.request.method} /${path}`;
      const op = operations.get(route);
      if (!op) throw new Error(`Unknown operation for ${route}`);
      item.id = stableId(`item:${op.operationId}`);
      item.name = `${route.replace('{id}', ':id')} — ${op.summary}`;
      if (op.operation.description) {
        item.request.description = { type: 'text/markdown', content: op.operation.description.trim() };
      }
      // URL : {{baseUrl}}/V2/… (les environnements donnent la racine /api, comme les SDK).
      item.request.url.host = ['{{baseUrl}}'];
      item.request.url.path = ['V2', ...item.request.url.path];
      item.request.url.raw = rawUrl(item.request.url);
      // Auth héritée de la collection.
      delete item.request.auth;
      for (const [index, response] of (item.response ?? []).entries()) {
        response.id = stableId(`response:${op.operationId}:${index}`);
        // Exemples cohérents (dérivés de l'exemple Payment de la spec) au lieu des valeurs factices.
        const example = exampleBody(op.operationId, response.code);
        if (example !== undefined) response.body = JSON.stringify(example, null, 2);
        if (response.originalRequest?.url) {
          response.originalRequest.url.host = ['{{baseUrl}}'];
          const rpath = response.originalRequest.url.path ?? [];
          response.originalRequest.url.path = rpath[0] === 'V2' ? rpath : ['V2', ...rpath];
          response.originalRequest.url.raw = rawUrl(response.originalRequest.url);
          delete response.originalRequest.auth;
        }
      }
      item.event = buildEvents(op.operationId, op.operation);
      customize(op.operationId, item.request);
      item._order = ORDER.indexOf(op.operationId);
      item._operationId = op.operationId;
    }
    rotationItems.push(...folder.item.filter((i) => ROTATIONS.includes(i._operationId)));
    folder.item = folder.item.filter((i) => !ROTATIONS.includes(i._operationId));
  }

  c.item.push({
    id: stableId(`folder:${ROTATION_FOLDER}`),
    name: ROTATION_FOLDER,
    description: {
      type: 'text/markdown',
      content:
        'Rotate the signing secret (the previous one stays valid 24 h) or the test key (the previous one stops working immediately). Live token only: with a test key, `403 LIVE_TOKEN_REQUIRED`. Run these on purpose, never in a routine run with a live token.',
    },
    item: rotationItems,
  });

  c.item = c.item.filter((f) => f.item.length > 0);
  c.item.sort((a, b) => FOLDERS.indexOf(a.name) - FOLDERS.indexOf(b.name));
  for (const folder of c.item) {
    if (FOLDERS.indexOf(folder.name) === -1) throw new Error(`Unexpected folder ${folder.name}`);
    folder.item.sort((a, b) => a._order - b._order);
    for (const item of folder.item) {
      delete item._order;
      delete item._operationId;
    }
  }
}

function customize(operationId, request) {
  switch (operationId) {
    case 'createDeposit':
      setBody(request, {
        amount: '1000',
        currency: 'CDF',
        phone: '0813456789',
        reference: '{{reference}}',
        customer_message: 'Commande Postman',
        metadata: { source: 'postman' },
      });
      break;
    case 'createPayout':
      setBody(request, { amount: '500', currency: 'CDF', phone: '0813456789', reference: '{{reference}}', metadata: { source: 'postman' } });
      break;
    case 'createRefund':
      setBody(request, { payment_id: '{{paymentId}}', amount: '100', reference: '{{reference}}' });
      break;
    case 'predictProvider':
      setBody(request, { phone: '0813456789', country: 'COD' });
      break;
    case 'getPayment':
    case 'syncPayment':
      setPathVariable(request, 'id', '{{paymentId}}');
      break;
    case 'cancelPayout':
      setPathVariable(request, 'id', '{{payoutId}}');
      break;
    case 'resendNotification':
      setPathVariable(request, 'id', '{{notificationId}}');
      break;
    case 'listPayments':
    case 'listNotifications':
      // Filtres facultatifs : désactivés par défaut (sauf la pagination).
      for (const q of request.url.query ?? []) {
        q.disabled = !['page', 'per_page'].includes(q.key);
        if (q.key === 'page') q.value = '1';
        if (q.key === 'per_page') q.value = '20';
        if (q.key === 'status') q.value = 'pending';
        if (q.key === 'operation') q.value = 'deposit';
        if (q.key === 'currency') q.value = 'CDF';
        if (q.key === 'country') q.value = 'COD';
        if (q.key === 'reference') q.value = 'ORDER-42';
        if (q.key === 'from') q.value = '2026-11-01';
        if (q.key === 'to') q.value = '2026-11-30';
        if (q.key === 'payment_id') q.value = '{{paymentId}}';
      }
      break;
    case 'listProviders':
      for (const q of request.url.query ?? []) {
        q.disabled = false;
        if (q.key === 'operation') q.value = 'deposit';
        if (q.key === 'country') q.value = 'COD';
      }
      break;
    case 'getAvailability':
      for (const q of request.url.query ?? []) {
        q.disabled = false;
        if (q.key === 'country') q.value = 'COD';
      }
      break;
    default:
  }
  request.url.raw = rawUrl(request.url);
}

function buildEvents(operationId, operation) {
  const documented = Object.keys(operation.responses).map(Number);
  const success = documented.filter((s) => s >= 200 && s < 300);
  const tests = [
    `pm.test('HTTP status documented by the spec (${documented.join(', ')})', function () {`,
    `  pm.expect(pm.response.code).to.be.oneOf(${JSON.stringify(documented)});`,
