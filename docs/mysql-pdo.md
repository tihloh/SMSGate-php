# MySQL with PHP PDO

SMSGate supports **PDO MySQL** as well as PDO SQLite. It requires PHP 8.2+, the
`pdo` and `pdo_mysql` extensions, and a MySQL database using InnoDB tables.
No `mysqli` or framework adapter is required.

## Connect to an existing MySQL database — or create it automatically

Install the Composer package, then specify a database name inside the MySQL DSN.
The PHP package takes care of database and table initialization:

```bash
composer require tihloh/smsgate
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Tihloh\SmsGate\Gateway;

$smsgate = Gateway::mysql(
    'mysql:host=127.0.0.1;port=3306;dbname=your_app;charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASSWORD'),
    'smsgate_'
);
```

- When `your_app` exists, SMSGate uses it without modifying unrelated tables.
- When `your_app` is missing, SMSGate creates it with `utf8mb4` and then initializes its five prefixed tables.
- `smsgate_` is the table **prefix**. It isolates SMSGate tables in an existing application database.
- The account must have database `CREATE` permission **only if the database is missing**. Missing privilege produces an explicit error instead of silently using a different database.
- `Gateway::pdo($pdo, 'smsgate_')` remains supported for an existing, already-selected MySQL database when a dedicated PDO connection has been provided. That method cannot create a missing database because PDO would fail before the library receives the connection.

The package automatically initializes its own prefixed schema on first use.
You can also call `$smsgate->installSchema()` explicitly from a **trusted
deployment command**. This operation is repeatable and does not remove existing
rows. The compact SMSGate schema (version 2) creates **five tables**:

`smsgate_meta`, `smsgate_devices`, `smsgate_installations`, `smsgate_jobs`, and `smsgate_records`.

`records` is a typed table which preserves separate idempotency keys, receipt hashes, callback acknowledgements and private annotations by scoped composite keys. It replaces the six former tables for enrollments, claims, batches, receipts, outbox events and annotations. Existing production databases at schema version 1 **must be migrated in a controlled maintenance window with a verified backup**; the SDK does not destroy or silently upgrade historical tables.

You may use the same MySQL database as your application, but use a **new PDO
connection**: SMSGate needs to begin/commit its own transactions and must not be
constructed inside a host transaction. The configurable table prefix keeps the
tables separate from host application tables.

## Permissions and deployment

Use a database account restricted to the appropriate schema. Creating a missing database requires `CREATE` permission for that database. Creating the five missing tables in an existing database also requires `CREATE` and `INDEX` privileges, while routine use requires
`SELECT`, `INSERT`, `UPDATE` and `DELETE` on the SMSGate tables. The library
does **not** require routine `DROP` privileges; those are only used by
temporary-schema integration-test cleanup. If you revoke DDL privileges after
installation, keep all required tables present: missing tables trigger the
automatic initialization check.

Use `utf8mb4`, secure MySQL credentials, and TLS to remote database servers
where appropriate. Database and SMS gateway endpoint security are separate
requirements. Do not expose DSNs, credentials, or enrollment tokens in your web
response or logs.

## Verify against MySQL

Run the included integration tests against a **test database**, not production.
They create and clean up uniquely prefixed tables and do not transmit SMS.

```bash
SMSGATE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=smsgate_test;charset=utf8mb4' \\
SMSGATE_TEST_MYSQL_USER=smsgate_test \\
SMSGATE_TEST_MYSQL_PASSWORD=your_test_password \\
composer test
```

The public GitHub Actions MySQL 8.0 job runs these tests using `pdo_mysql`.
When `SMSGATE_TEST_MYSQL_DSN` is unset, the MySQL-specific test is skipped
while the SQLite tests continue to run.
