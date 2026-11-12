# Changelog

All notable changes to `com.labyrinthe-rdc:labpay` and `com.labyrinthe-rdc:labpay-spring-boot-starter` are documented
here. This project follows [Semantic Versioning](https://semver.org/) and the format of
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.1.0] - 2026-11-12

First preview, built against the LabPay API V2 (`openapi/openapi.yaml` 2.0.0). Not published yet.

### Added
- `LabPayClient` (builder, thread-safe) for the V2 API (`https://api.labyrinthe-rdc.com/api/V2` by default,
  overridable, `LABPAY_API_KEY` / `LABPAY_BASE_URL`), live token or test key (`test_…`). Java 17+, `java.net.http`,
  no runtime dependency.
- Deposits, payouts (and cancellation of a queued payout), refunds, payment tracking (get, list with filters and
  pagination, lazy iteration and stream over every page, lookup by reference, status sync).
- Countries, providers, availability and provider prediction; account, balances, signing secret and test key rotation;
  notification history and resend.
- Immutable models (records), enums with an `UNKNOWN` value, amounts as exact strings / `BigDecimal`.
- Typed exceptions mapped from the API `code` (then the HTTP status), with per-field validation errors.
- Idempotency through `reference`: generated (`sdk_<uuid>`) when missing, so that payments are retried safely; payments
  without reference, rotations, resends and cancellations are never retried after a network error or a 5xx.
- A payment refused at initiation (422 with `data`) is returned with the `FAILED` status instead of being thrown.
- `Webhooks`: verification of `X-Labyrinthe-Signature` (HMAC-SHA256 of `"<t>.<raw body>"`, every `v1` checked in
  constant time, 300 s tolerance, several secrets), and `sign()` for tests.
- Spring Boot starter: `LabPayClient` bean from `labpay.*`, `LabPayClientBuilderCustomizer`, signed notification
  endpoint (`POST /webhooks/labpay`) publishing `WebhookEvent` application events.
- Tests (JUnit 5, JDK HTTP server simulating the API), OpenAPI contract test, SpotBugs, google-java-format.
