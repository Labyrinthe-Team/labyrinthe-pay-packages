# Changelog

All notable changes to `labyrinthe/labpay-laravel` are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.1.0] - 2026-11-12

First preview, on top of `labyrinthe/labpay-php` 0.1.0 (LabPay API V2). Not published yet.

### Added
- `LabPayServiceProvider` (auto-discovered): `Labyrinthe\LabPay\Client` singleton built from `config/labpay.php`
  (`LABPAY_API_KEY`, `LABPAY_BASE_URL`, timeout, retries, automatic references), Laravel logger wired in.
- Publishable configuration (`php artisan vendor:publish --tag=labpay-config`).
- `LabPay` facade (`LabPay::client()->deposits->create(…)`).
- `labpay.signature` middleware (`VerifyLabPaySignature`): verifies `X-Labyrinthe-Signature` on the raw body with one or
  several secrets (`LABPAY_WEBHOOK_SECRET`, comma-separated during a rotation), 400 when invalid, 500 without secret.
- Notification route `POST /labpay/webhook` (`LABPAY_WEBHOOK_PATH`, `null` to disable) dispatching `WebhookReceived`,
  then `PaymentCompleted` or `PaymentFailed`, with the delivery id and the mode.
