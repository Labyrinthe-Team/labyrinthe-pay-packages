// Vérifie la collection Postman contre ../openapi/openapi.yaml (sans réseau) :
// - chaque opération marchande de la spec a exactement une requête (même méthode, même chemin) ;
// - variables, auth Bearer de collection, URL {{baseUrl}}/V2/…, environnements local et production ;
// - le fichier versionné est celui que produit scripts/generate.mjs ;
// - Newman exécute toute la collection contre un serveur simulé en mémoire (réponses d'exemple de
//   la collection, port aléatoire, arrêté à la fin) : les scripts de test s'exécutent sans erreur.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import newman from 'newman';
import { parse } from 'yaml';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const spec = parse(read('../../openapi/openapi.yaml'));
const collection = JSON.parse(read('../labpay.postman_collection.json'));
const environments = {
  local: JSON.parse(read('../local.postman_environment.json')),
  production: JSON.parse(read('../production.postman_environment.json')),
};

const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

function specOperations() {
  const routes = new Map();
  for (const [path, item] of Object.entries(spec.paths)) {
    for (const method of METHODS) {
      if (item[method]) routes.set(`${method.toUpperCase()} /V2${path}`, item[method].operationId);
    }
  }
  return routes;
}

function requests(items = collection.item, out = []) {
  for (const item of items) {
    if (item.item) requests(item.item, out);
    else out.push(item);
  }
  return out;
}

function route(item) {
  const path = item.request.url.path.map((p) => (p.startsWith(':') ? `{${p.slice(1)}}` : p)).join('/');
  return `${item.request.method} /${path}`;
}

test('every operation of the spec has exactly one request', () => {
  const expected = specOperations();
  const actual = requests().map(route);

  assert.ok(expected.size >= 17, `the spec lists ${expected.size} operations`);
  assert.deepEqual([...actual].sort(), [...expected.keys()].sort());
  assert.equal(new Set(actual).size, actual.length, 'no duplicate request');
});

test('collection variables, auth and URLs', () => {
  assert.equal(collection.info.schema, 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json');
  const variables = Object.fromEntries(collection.variable.map((v) => [v.key, v]));
  assert.ok(variables.baseUrl);
  assert.equal(variables.token.type, 'secret');
  assert.equal(variables.token.value, '', 'no key in the collection');
  assert.deepEqual(collection.auth, { bearer: [{ key: 'token', type: 'string', value: '{{token}}' }], type: 'bearer' });

  for (const item of requests()) {
    assert.equal(item.request.auth, undefined, `${item.name} inherits the collection auth`);
    assert.deepEqual(item.request.url.host, ['{{baseUrl}}'], item.name);
    assert.equal(item.request.url.path[0], 'V2', item.name);
    assert.ok(item.request.url.raw.startsWith('{{baseUrl}}/V2/'), item.name);
    const scripts = (item.event ?? []).filter((e) => e.listen === 'test');
    assert.equal(scripts.length, 1, `${item.name} has a test script`);
    assert.ok(item.response.length > 0, `${item.name} documents its responses`);
    for (const response of item.response) {
      assert.doesNotThrow(() => JSON.parse(response.body), `${item.name} ${response.code}: JSON example`);
    }
