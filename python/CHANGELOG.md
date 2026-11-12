# Changelog

All notable changes to `labpay` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.1.0] - 2026-11-12

First preview, built against the LabPay API V2 (`openapi/openapi.yaml` 2.0.0). Not published yet.

### Added
- Client for the V2 API (`https://api.labyrinthe-rdc.com/api/V2` by default, overridable), live token or test key (`test_…`).
- Deposits, payouts (and cancellation of a queued payout), refunds, payment tracking (get, list with filters and
  pagination, iteration over every page, lookup by reference, status sync).
- Countries, providers, availability and provider prediction; account, balances, signing secret and test key rotation;
  notification history and resend.
- Typed errors mapped from the API `code` (then the HTTP status), with per-field validation errors.
- Idempotency through `reference`: generated (`sdk_<uuid>`) when missing, so that payments are retried safely; payments
  without reference, rotations, resends and cancellations are never retried after a network error or a 5xx.
- A payment refused at initiation (422 with `data`) is returned with the `failed` status instead of being thrown.
- Verification of the `X-Labyrinthe-Signature` header of notifications (HMAC-SHA256 of `"<t>.<raw body>"`, every `v1`
  checked in constant time, 300 s tolerance), and a signing helper for tests.
