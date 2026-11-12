# Changelog

All notable changes to the LabPay Postman collection are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.1.0] - 2026-11-12

Generated from `openapi/openapi.yaml` 2.0.0 (API V2). Not published.

### Added
- Postman v2.1 collection of the 17 merchant operations of the API V2 (Toolkit, Account, Payments, Notifications,
  key rotations in a separate folder), `{{baseUrl}}/V2/…` URLs, Bearer auth `{{token}}` at collection level.
- Response examples derived from the spec (`Payment` example, documented error codes) and test scripts: documented
  status, success for the read and deposit requests, `data` and `X-Labyrinthe-Mode` on success, `message` on error.
- Nominal flow: fresh `reference` per payment request, `paymentId`, `payoutId` and `notificationId` chained.
- Environments `local` (`http://localhost:8000/api`) and `production` (`https://api.labyrinthe-rdc.com/api`).
- `npm run generate` / `npm run check` (deterministic generation with `openapi-to-postmanv2`), `npm test` (spec
  coverage, variables, auth, environments, up-to-date check, Newman run against an in-memory simulated API) and
  `npm run newman` (Newman against a backend with a test key).
