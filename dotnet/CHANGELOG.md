# Changelog

All notable changes to `Labyrinthe.LabPay` and `Labyrinthe.LabPay.AspNetCore` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.1.0] - 2026-11-12

First preview, built against the LabPay API V2 (`openapi/openapi.yaml` 2.0.0). **Not compiled nor tested yet** (written
without a .NET SDK available); not published.

### Added
- `LabPayClient` (`netstandard2.0`, `net8.0`) for the V2 API (`https://api.labyrinthe-rdc.com/api/V2` by default,
  overridable, `LABPAY_API_KEY` / `LABPAY_BASE_URL`), live token or test key (`test_…`), async API with
  `CancellationToken`.
- Deposits, payouts (and cancellation of a queued payout), refunds, payment tracking (get, list with filters and
  pagination, `IAsyncEnumerable` iteration over every page, lookup by reference, status sync).
- Countries, providers, availability and provider prediction; account, balances, signing secret and test key rotation;
  notification history and resend.
- Immutable models (records), enums with an `Unknown` value, exact amounts (`decimal` in requests, strings in responses).
- Typed exceptions mapped from the API `code` (then the HTTP status), with per-field validation errors.
- Idempotency through `reference`: generated (`sdk_<uuid>`) when missing, so that payments are retried safely; payments
  without reference, rotations, resends and cancellations are never retried after a network error or a 5xx.
- A payment refused at initiation (422 with `data`) is returned with the `Failed` status instead of being thrown.
- `Webhook`: verification of `X-Labyrinthe-Signature` (HMAC-SHA256 of `"<t>.<raw body>"`, every `v1` checked in
  constant time, 300 s tolerance, several secrets), and `Sign()` for tests.
- `Labyrinthe.LabPay.AspNetCore`: `AddLabPay(configuration)` / `AddLabPay(options => …)` (typed client of
  `IHttpClientFactory`, `ILogger<LabPayClient>`), `AddLabPayWebhookHandler<T>()`, `MapLabPayWebhooks()`.
- xUnit tests (simulated `HttpMessageHandler`, ASP.NET Core `TestServer`, OpenAPI contract) and examples.
