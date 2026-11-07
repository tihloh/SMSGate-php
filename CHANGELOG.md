# Changelog

## 0.1.1 - 2026-11-07

- Verified native PDO MySQL support against MySQL 8.0, including schema installation, persistence across separate connections, durable claim replay, JSON filters, and transaction rollback.
- Added a dedicated PDO MySQL integration test, a connection example, and a deployment guide with required database permissions.
- Expanded CI checks for MySQL and PHP 8.2–8.4 compatibility.

## 0.1.0 - 2026-11-07

- Standalone `tihloh/smsgate` Composer package with PSR-4 autoloading, PHP 8.2+, and no runtime package dependencies.
- One Android endpoint with plain `process()` integration while the host owns its POST check.
- Automatic SQLite/MySQL table initialization, configurable prefixes, and transactional storage.
- Token issuance/rotation, device enablement, pending installations and explicit single-installation approval.
- Atomic personalized bulk queues with arbitrary/global values, expiry, cancellation and idempotency.
- Android claim, incoming SMS and status endpoints with durable acknowledgements and state progression.
- Durable integration events with explicit acknowledgement and no callbacks inside transactions.
- Android-request-driven business callbacks and CRUD APIs for all developer-managed records.
- QR/file-compatible setup JSON with one exact endpoint, redacted debug output, examples and integration documentation.
- PHPUnit protocol, lifecycle, template, persistence and contention tests.
