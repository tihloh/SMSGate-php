# Storage, retries, and operating boundaries

## Transaction guarantees

The package owns ten prefixed tables: meta, devices, installations, enrollments, jobs, claims, batches, receipts, outbox, and annotations. Use a dedicated PDO connection. SQLite uses `BEGIN IMMEDIATE` with a five-second busy timeout. MySQL uses an InnoDB transaction and locks the schema coordinator row. Writes are serialized across instances sharing a prefix; locks are short and contain no network calls or application callbacks. This favors consistent SMS processing over maximum parallel write throughput. Measure your workload before deploying at high volume; use separate prefixes/databases for independent gateways if appropriate.

Every claim caches its response in the same transaction that marks jobs CLAIMED. The phone reuses its claim key after network failure. Enqueueing with a stable key is similarly atomic, including all template validation before writes. Incoming SMS/status acknowledgements are returned only after committing their records and integration events. Duplicate IDs with the same normalized payload acknowledge again; changed payloads return 409.

Approval is checked under the same transaction coordination as device operations. Token rotation, disabling, or blocking that commits first prevents the next operation, including replayed claims. An operation already executing can finish before the administrative change commits. The pending-registration transaction commits before a 403 is returned, so enrollment is reviewable and never self-approved.

## Deliberate limits

- SMS delivery cannot be made exactly-once end to end. Do not automatically reassign CLAIMED/UNKNOWN/SENDING jobs; the phone/modem may already have sent them. An operator may create a new job after investigating, understanding the duplication risk.
- Server blocking/cancellation cannot recall downloaded jobs or carrier submissions. Use the app's Stop control for local work.
- Approval identifies an installation UUID paired with a bearer token, not a cryptographic hardware identity. Keep credentials private; the library does not include attestation or signed requests.
- The package does not publish an endpoint, dashboard, webhooks, background workers, or framework-specific service providers. The Android request selects its action and your application uses trusted CRUD methods.
- Start with a fresh prefix and explicitly enroll/approve phones. Migrating queued/in-flight SMS from another implementation needs an operator-reviewed plan to avoid duplicate sending.
- No retention cleanup runs automatically. Jobs, claims, receipts, batches, and review records persist. Acknowledging outbox events clears their extra payload copy, not the source message. Establish your own retention/backups and storage monitoring; deleting deduplication/claim records can permit replay. Do not simply delete the database to clear a backlog.
- Missing version-one tables are added automatically on first use. `installSchema()` is an optional explicit deployment step. The library rejects unsupported schema versions and does not perform future version upgrades implicitly. Back up before future migrations.

## Deployment

Keep SQLite files outside the document root and use restrictive filesystem permissions. SQLite needs write access to its directory for journal files. For MySQL, use a dedicated user; schema installation needs CREATE/INDEX, runtime needs SELECT/INSERT/UPDATE/DELETE on the package tables. DROP is needed by disposable integration-test cleanup, not runtime use. Configure utf8mb4. Keep the connection separate from any host-application transaction.

TLS, endpoint rate limiting, tenant/admin authorization, log policy, retention, and backup access belong to the host application. HTTP remains compatible with the phone but is unencrypted. Do not enable token-bearing cross-origin browser APIs unnecessarily; these routes are device-server communication, not a browser dashboard.

CI verifies PHP 8.2–8.4 with SQLite and separately exercises PDO MySQL against MySQL 8.0. The MySQL-specific test verifies schema installation, a fresh-connection claim replay, JSON inbox filters, and rollback. Nothing in the package asserts Google Play approval for the Android app.
