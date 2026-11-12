# LabPay — Postman collection

*[Version française](README.fr.md)*

A ready-to-run [Postman](https://www.postman.com/) collection (v2.1) of the **LabPay API V2** (Mobile Money in the DR
Congo: M-Pesa, Airtel Money, Orange Money; CDF and USD), generated from [`../openapi/openapi.yaml`](../openapi/openapi.yaml),
the source of truth of the API. Collection **0.1.0 — preview**, generated from the spec 2.0.0 (see the
[CHANGELOG](CHANGELOG.md)).

| File | Content |
|---|---|
| `labpay.postman_collection.json` | the collection: one folder per tag, Bearer auth at collection level, response examples, light test scripts |
| `local.postman_environment.json` | `baseUrl` = `http://localhost:8000/api`, empty `token` |
| `production.postman_environment.json` | `baseUrl` = `https://api.labyrinthe-rdc.com/api`, empty `token` |

Every request is `{{baseUrl}}/V2/…`. The 17 merchant operations of the spec:

| Folder | Requests |
|---|---|
| Toolkit | `GET /countries`, `GET /providers`, `GET /availability`, `POST /predict-provider` |
| Account | `GET /account`, `GET /balances` |
| Payments | `POST /deposits`, `GET /payments/:id`, `POST /payments/:id/sync`, `GET /payments`, `POST /refunds`, `POST /payouts`, `POST /payouts/:id/cancel` |
| Notifications | `GET /notifications`, `POST /notifications/:id/resend` |
| Account — key rotation (live token only) | `POST /account/webhook-secret/rotate`, `POST /account/test-key/rotate` |

Each request documents its responses (success, refused payment, `401`, `403`, `404`, `409`, `422`, `429`, `503`) with
examples built from the spec's `Payment` example and its documented error codes.

## 1. Import

1. Postman > **Import** > `labpay.postman_collection.json`.
2. Import an environment (`local.postman_environment.json` or `production.postman_environment.json`) and select it.

## 2. Set your key

In the selected environment, set `token` (type *secret*) to a **test key** `test_…` (merchant area > Developers): the
sandbox moves no real money and keeps separate balances and payments. Keep it in the *current value* only: never commit
a key or share an exported environment that contains one. The key is sent as `Authorization: Bearer {{token}}` by the
collection-level auth, inherited by every request.

## 3. Run the nominal flow

Run the collection in order (**Run collection**). It plays the toolkit, the account, then:

1. `POST /deposits` with a fresh `reference` (`POSTMAN-DEP-<timestamp>`, the idempotency key) → saves `paymentId`;
2. `GET /payments/:id` and `POST /payments/:id/sync` on that deposit, then `GET /payments`;
3. `POST /refunds` (accepted only once the deposit is `completed`: `409 DEPOSIT_NOT_COMPLETED` otherwise),
   `POST /payouts` (`422 INSUFFICIENT_BALANCE` with an empty test balance) → saves `payoutId`, `POST /payouts/:id/cancel`;
4. `GET /notifications` → saves the first `notificationId`, `POST /notifications/:id/resend`.

Every test checks that the status is one documented by the spec; the toolkit, account, deposit, payment reads and
lists must succeed. Successful answers must carry `data` and `X-Labyrinthe-Mode`; errors a `message`.

The **key rotation** folder rotates real secrets (the previous signing secret stays valid 24 h, the previous test key
stops at once). With a test key it only returns `403 LIVE_TOKEN_REQUIRED`; run it on purpose with the live token.

### Variables

| Variable | Set by | Used by |
|---|---|---|
| `baseUrl` | environment (collection default: local) | every request |
| `token` | you (secret) | collection-level Bearer auth |
| `reference` | pre-request script of the deposit, payout and refund requests | their body |
| `paymentId` | test script of `POST /deposits` | payment, sync and refund requests |
| `payoutId` | test script of `POST /payouts` | `POST /payouts/:id/cancel` |
| `notificationId` | test script of `GET /notifications` | `POST /notifications/:id/resend` |

Rate limit as in production: 120 requests per minute per token.

## 4. Command line

```bash
npm ci
npm run generate                 # regenerate the collection from ../openapi/openapi.yaml
npm run check                    # fail if the committed collection is not what the spec produces
npm test                         # offline checks (below)
LABPAY_TOKEN=test_… npm run newman   # Newman against a local backend (LABPAY_BASE_URL to override)
```

`npm test` (`test/collection.test.mjs`, `node:test`) never touches the network: it checks that every operation of the
spec has exactly one request (same method and path), the variables, the collection-level auth, the `{{baseUrl}}/V2/`
URLs, the environments, that the committed collection is up to date, and runs the whole collection with Newman against
an in-memory simulated API (example answers, random port, stopped at the end).

`npm run newman` (`scripts/newman.mjs`) refuses a live token, reads the key from the environment only, injects it in
memory (never printed) and skips the key rotation folder. `LABPAY_BASE_URL` accepts a bare host
(`http://localhost:8000` is completed with `/api`) or a URL ending with `/V2`.

## Regenerating

The collection is generated, never edited by hand: change `../openapi/openapi.yaml`, then `npm run generate`.
`scripts/generate.mjs` converts the spec with `openapi-to-postmanv2` (deterministic: fixed seed and clock, stable
IDs), then sets the variables, the auth, the `/V2` paths, the bodies, the response examples and the test scripts.

## License

MIT — see [LICENSE](LICENSE).
