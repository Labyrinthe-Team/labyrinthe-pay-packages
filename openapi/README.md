# LabPay API V2 — OpenAPI specification

[`openapi.yaml`](openapi.yaml) (OpenAPI 3.1) describes the public merchant API V2 of LabPay
(`https://api.labyrinthe-rdc.com/api/V2`): deposits, payouts, refunds, tracking, countries and providers, account and
balances, notifications, and the notification sent to the merchant's `callback_url` (`webhooks.payment.finalized`).

*Version française : [README.fr.md](README.fr.md).*

It is the common base of every SDK in this repository. It was **derived from the payment engine code**
(`labyrinthe-pay-back`: `routes/api/v2/payments.php`, `routes/api/v2/toolkit.php`, V2 controllers and form requests,
`PaymentPresenter`, `MerchantNotifier`, `AuthenticateMerchantToken`, `PaymentRejectedException` codes) and checked
against the documentation pages (`labyrinthe-pay-docs`). The V1 API and the administration routes (`/V2/admin/*`,
`/V2/webhooks/*` aggregator callbacks) are out of scope.

## Checks

The Node SDK contract test (`node/tests/contract.test.ts`, run by `npm test`) parses this file and fails when:

- the operations of the spec and those called by the SDK differ;
- a `$ref` does not resolve;
- an error code of `components.schemas.ErrorCode` has no typed error in the SDK;
- the merchant routes of the engine (`../labyrinthe-pay-back/routes/api/v2/{payments,toolkit}.php`, or
  `LABPAY_ENGINE_ROUTES`) are not exactly the operations of the spec (skipped when the engine is not checked out next
  to `packages/`).

Lint with any OpenAPI tool, e.g. `npx @redocly/cli lint openapi.yaml` (not installed here).

## Versioning

`info.version` follows SemVer: bump it with every change, then update each SDK (types, enums, error mapping) and
their CHANGELOG.
