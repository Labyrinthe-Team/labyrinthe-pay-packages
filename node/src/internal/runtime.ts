/**
 * Accès à l'environnement d'exécution sans dépendre de Node : le cœur du SDK
 * tourne aussi sur les runtimes edge, Deno et Bun. Aucun import `node:*`
 * statique ici.
 *
 * @internal
 */
import { VERSION } from '../version.js';

interface ProcessLike {
  env?: Record<string, string | undefined>;
  versions?: Record<string, string | undefined>;
}

interface GlobalLike {
  process?: ProcessLike;
  crypto?: Crypto;
  window?: { document?: unknown };
  Deno?: { version?: { deno?: string } };
  Bun?: { version?: string };
}

const g = globalThis as unknown as GlobalLike;

/** Lit une variable d'environnement quand `process.env` existe. */
export function env(name: string): string | undefined {
  try {
    const value = g.process?.env?.[name];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : undefined;
  } catch {
    return undefined;
  }
}

/** Vrai dans un vrai navigateur : la clé API ne doit jamais y être. */
export function isBrowser(): boolean {
  return g.window?.document !== undefined;
}

function runtimeName(): string {
  if (typeof g.Bun?.version === 'string') return `bun/${g.Bun.version}`;
  if (typeof g.Deno?.version?.deno === 'string') return `deno/${g.Deno.version.deno}`;
  const node = g.process?.versions?.['node'];
  if (typeof node === 'string') return `node/${node}`;
  return 'unknown';
}

/** "labpay-node/0.1.0 node/22.11.0" */
export function userAgent(): string {
  return `labpay-node/${VERSION} ${runtimeName()}`;
}

let cryptoPromise: Promise<Crypto> | undefined;

/**
 * Web Crypto. Global partout (Node ≥ 20, Deno, Bun, edge) sauf sur Node 18,
 * où il faut le lire dans le module crypto : import dynamique, jamais exécuté
 * ailleurs, et ignoré par les bundlers (spécificateur non littéral).
 */
export function getCrypto(): Promise<Crypto> {
  if (g.crypto?.subtle !== undefined && typeof g.crypto.getRandomValues === 'function') {
    return Promise.resolve(g.crypto);
  }
  cryptoPromise ??= loadNodeCrypto();
  return cryptoPromise;
}

async function loadNodeCrypto(): Promise<Crypto> {
  if (typeof g.process?.versions?.['node'] === 'string') {
    const specifier = ['node', 'crypto'].join(':');
    try {
      const mod = (await import(/* webpackIgnore: true */ /* @vite-ignore */ specifier)) as { webcrypto?: Crypto };
      if (mod.webcrypto?.subtle !== undefined) {
        return mod.webcrypto;
      }
    } catch {
      // On tombe sur l'erreur ci-dessous.
    }
  }
  cryptoPromise = undefined;
  throw new Error('Web Crypto (globalThis.crypto) is not available in this runtime.');
}

/** UUID v4 aléatoire (référence générée d'un paiement). */
export async function randomUuid(): Promise<string> {
  const crypto = await getCrypto();
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
