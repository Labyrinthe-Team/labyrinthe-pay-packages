# LabPay — collection Postman

*[English version](README.md)*

Une collection [Postman](https://www.postman.com/) (v2.1) prête à l'emploi de l'**API V2 LabPay** (Mobile Money en
Afrique : M-Pesa, Airtel Money, Orange Money, MTN MoMo… ; chaque pays ouvert dans sa devise), générée depuis [`../openapi/openapi.yaml`](../openapi/openapi.yaml),
la source de vérité de l'API. Collection **0.1.0 — aperçu**, générée depuis la spec 2.0.0 (voir le
[CHANGELOG](CHANGELOG.md)).

| Fichier | Contenu |
|---|---|
| `labpay.postman_collection.json` | la collection : un dossier par tag, auth Bearer au niveau de la collection, exemples de réponses, scripts de test légers |
| `local.postman_environment.json` | `baseUrl` = `http://localhost:8000/api`, `token` vide |
| `production.postman_environment.json` | `baseUrl` = `https://api.labyrinthe-rdc.com/api`, `token` vide |

Chaque requête est `{{baseUrl}}/V2/…`. Les 17 opérations marchandes de la spec :

| Dossier | Requêtes |
|---|---|
| Toolkit | `GET /countries`, `GET /providers`, `GET /availability`, `POST /predict-provider` |
| Account | `GET /account`, `GET /balances` |
| Payments | `POST /deposits`, `GET /payments/:id`, `POST /payments/:id/sync`, `GET /payments`, `POST /refunds`, `POST /payouts`, `POST /payouts/:id/cancel` |
| Notifications | `GET /notifications`, `POST /notifications/:id/resend` |
| Account — key rotation (live token only) | `POST /account/webhook-secret/rotate`, `POST /account/test-key/rotate` |

Chaque requête documente ses réponses (succès, paiement refusé, `401`, `403`, `404`, `409`, `422`, `429`, `503`) avec
des exemples construits depuis l'exemple `Payment` de la spec et ses codes d'erreur documentés.

## 1. Importer

1. Postman > **Import** > `labpay.postman_collection.json`.
2. Importez un environnement (`local.postman_environment.json` ou `production.postman_environment.json`) et
   sélectionnez-le.

## 2. Renseigner la clé

Dans l'environnement sélectionné, mettez dans `token` (type *secret*) une **clé de test** `test_…` (espace marchand >
Développeurs) : le bac à sable ne déplace aucun argent réel et garde des soldes et des paiements séparés. Gardez-la
dans la *valeur courante* seulement : ne versionnez jamais une clé et ne partagez pas un environnement exporté qui en
contient une. La clé part en `Authorization: Bearer {{token}}` grâce à l'auth de la collection, héritée par chaque
requête.

## 3. Jouer le parcours nominal

Lancez la collection dans l'ordre (**Run collection**). Elle joue le toolkit, le compte, puis :

1. `POST /deposits` avec une `reference` neuve (`POSTMAN-DEP-<horodatage>`, la clé d'idempotence) → enregistre `paymentId` ;
2. `GET /payments/:id` et `POST /payments/:id/sync` sur ce dépôt, puis `GET /payments` ;
3. `POST /refunds` (accepté seulement quand le dépôt est `completed` : `409 DEPOSIT_NOT_COMPLETED` sinon),
   `POST /payouts` (`422 INSUFFICIENT_BALANCE` avec un solde de test vide) → enregistre `payoutId`,
   `POST /payouts/:id/cancel` ;
4. `GET /notifications` → enregistre le premier `notificationId`, `POST /notifications/:id/resend`.

Chaque test vérifie que le statut fait partie de ceux documentés par la spec ; le toolkit, le compte, le dépôt, les
lectures et les listes doivent réussir. Une réponse réussie doit porter `data` et `X-Labyrinthe-Mode` ; une erreur, un
`message`.

Le dossier **key rotation** fait tourner de vrais secrets (l'ancien secret de signature reste valable 24 h, l'ancienne
clé de test cesse aussitôt). Avec une clé de test il ne renvoie que `403 LIVE_TOKEN_REQUIRED` ; lancez-le
volontairement, avec le token live.

### Variables

| Variable | Définie par | Utilisée par |
|---|---|---|
| `baseUrl` | l'environnement (défaut de la collection : local) | toutes les requêtes |
| `token` | vous (secret) | l'auth Bearer de la collection |
| `reference` | le script de pré-requête du dépôt, du retrait et du remboursement | leur corps |
| `paymentId` | le script de test de `POST /deposits` | lecture, synchronisation, remboursement |
| `payoutId` | le script de test de `POST /payouts` | `POST /payouts/:id/cancel` |
| `notificationId` | le script de test de `GET /notifications` | `POST /notifications/:id/resend` |

Limite de requêtes comme en production : 120 par minute et par token.

## 4. En ligne de commande

```bash
npm ci
npm run generate                 # régénère la collection depuis ../openapi/openapi.yaml
npm run check                    # échoue si la collection versionnée n'est pas celle que produit la spec
npm test                         # vérifications hors ligne (ci-dessous)
LABPAY_TOKEN=test_… npm run newman   # Newman contre un backend local (LABPAY_BASE_URL pour changer)
```

`npm test` (`test/collection.test.mjs`, `node:test`) n'appelle jamais le réseau : il vérifie que chaque opération de la
spec a exactement une requête (même méthode, même chemin), les variables, l'auth de la collection, les URL
`{{baseUrl}}/V2/`, les environnements, que la collection versionnée est à jour, et exécute toute la collection avec
Newman contre une API simulée en mémoire (réponses d'exemple, port aléatoire, arrêtée à la fin).

`npm run newman` (`scripts/newman.mjs`) refuse un token live, lit la clé uniquement dans l'environnement, l'injecte en
mémoire (jamais affichée) et saute le dossier des rotations. `LABPAY_BASE_URL` accepte un hôte nu
(`http://localhost:8000` est complété avec `/api`) ou une URL qui finit par `/V2`.

## Régénérer

La collection est générée, jamais modifiée à la main : changez `../openapi/openapi.yaml`, puis `npm run generate`.
`scripts/generate.mjs` convertit la spec avec `openapi-to-postmanv2` (déterministe : graine et horloge fixes,
identifiants stables), puis pose les variables, l'auth, les chemins `/V2`, les corps, les exemples de réponses et les
scripts de test.

## Licence

MIT — voir [LICENSE](LICENSE).
