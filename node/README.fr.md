# SDK Node.js / TypeScript LabPay

SDK officiel de [LabPay](https://pay.labyrinthe-rdc.com) : encaisser et envoyer du Mobile Money (M-Pesa, Airtel
Money, Orange Money, MTN MoMo…) en Afrique, dans chaque pays ouvert sur la plateforme et dans sa devise (CDF, USD, XAF, XOF…). Couvre l'**API V2** (la V1 n'est pas couverte).

*English version: [README.md](README.md).*

> **État : aperçu (0.1.0)** — pas encore publié sur npm.

- Node.js 18+, `fetch` natif, aucune dépendance ; builds ESM et CommonJS avec déclarations de types.
- Le cœur n'utilise que Fetch et Web Crypto (Bun, Deno, runtimes edge) — côté serveur uniquement.

## Installation

```bash
npm install @labyrinthe/labpay
```

## Démarrage (mode test)

```ts
import { LabPay } from '@labyrinthe/labpay';

const labpay = new LabPay({ apiKey: 'test_…' }); // clé de test : sandbox (par défaut : LABPAY_API_KEY)

const paiement = await labpay.deposits.create({
  amount: '1000',            // montant net crédité ; le client paie ce montant + les frais
  currency: 'CDF',
  phone: '0813456789',       // numéro de la sandbox : réussite
  reference: 'ORDER-42',     // unique par token : un renvoi rend le même paiement
  callbackUrl: 'https://boutique.example/webhooks/labpay',
});
```

## À retenir

- Ressources : `deposits`, `payouts` (`create`, `cancel`), `refunds`, `payments` (`get`, `sync`, `list`, `iterate`,
  `findByReference`), `providers` (`countries`, `list`, `availability`, `predict`), `account` (`get`, `balances`,
  `balance`, `rotateWebhookSecret`, `rotateTestKey`), `notifications` (`list`, `resend`), `Webhooks`. Tableau détaillé
  dans le [README anglais](README.md#api-coverage).
- **Ne livrez qu'au statut `completed`.** Un paiement refusé dès l'initiation est renvoyé avec `status: 'failed'`, pas
  levé ; un 202 est un paiement `pending`.
- Sans `reference`, le SDK génère `sdk_<uuid>` pour relancer sans risque de double paiement. Rotations, renvois,
  annulations et paiements sans référence ne sont jamais relancés après une erreur réseau ou un 5xx.
- Une `ConnectionError` sur un paiement ne veut pas dire « échec » : renvoyez la même référence.
- Erreurs typées (`LabPayError` → `ApiError` → `PermissionError`, `InsufficientBalanceError`…) : basez-vous sur `code`.

## Notifications

```ts
const evenement = await Webhooks.constructEvent(corpsBrut, request.headers.get('x-labyrinthe-signature'), process.env.LABPAY_WEBHOOK_SECRET!);
```

Corps **brut** (Express : `express.raw()`), réponse 400 sur `SignatureVerificationError`, 2xx en moins de 10 s,
dédoublonnage sur `X-Labyrinthe-Delivery` ou `payment.id`.

## Développement

```bash
npm install && npm test && npm run typecheck && npm run build && npm run check:dist
```
