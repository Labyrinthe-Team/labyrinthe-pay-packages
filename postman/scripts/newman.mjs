// Lance la collection avec Newman contre un backend (local par défaut), avec une clé de TEST.
//
//   LABPAY_TOKEN=test_… npm run newman
//   facultatif : LABPAY_BASE_URL (défaut : baseUrl de local.postman_environment.json)
//
// La clé n'est lue que dans l'environnement du processus et injectée en mémoire (envVar) :
// jamais sur la ligne de commande, jamais dans un fichier, jamais affichée. Le dossier des
// rotations de clés est exclu : il ne sert qu'avec le token live, et volontairement.
import { readFileSync } from 'node:fs';
import newman from 'newman';

const token = (process.env.LABPAY_TOKEN ?? '').trim();
if (!token.startsWith('test_')) {
  console.error('Set LABPAY_TOKEN to a TEST key (test_…): the collection creates payments.');
  process.exit(1);
}

const collection = JSON.parse(readFileSync(new URL('../labpay.postman_collection.json', import.meta.url), 'utf8'));
const environment = JSON.parse(readFileSync(new URL('../local.postman_environment.json', import.meta.url), 'utf8'));
const envVar = [{ key: 'token', value: token }];
const baseUrl = process.env.LABPAY_BASE_URL?.trim();
if (baseUrl) {
  // Un hôte nu (http://localhost:8000) est complété avec /api, « …/api/V2 » ramené à « …/api », comme dans les SDK.
  let url = baseUrl.replace(/\/+$/, '').replace(/\/V2$/i, '');
  if (!/\/api$/.test(url) && new URL(url).pathname === '/') url += '/api';
  envVar.push({ key: 'baseUrl', value: url });
}

const folders = collection.item.map((f) => f.name).filter((name) => !name.includes('key rotation'));

newman.run(
  {
    collection,
    environment,
    envVar,
    folder: folders,
    reporters: ['cli'],
    // Les en-têtes de requête (dont Authorization) ne sont pas affichés par le reporter cli.
    reporter: { cli: { noSummary: false, showTimestamps: false } },
    timeoutRequest: 30_000,
  },
  (error, summary) => {
    if (error) {
      console.error(error.message);
      process.exit(1);
    }
    const { requests, assertions } = summary.run.stats;
    const failed = summary.run.failures.length;
    console.log(`Requests: ${requests.total - requests.failed}/${requests.total}, assertions: ${assertions.total - assertions.failed}/${assertions.total}`);
    process.exit(failed > 0 ? 1 : 0);
  },
);
