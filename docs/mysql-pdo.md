# MySQL with PHP PDO

SMSGate supports **PDO MySQL** as well as PDO SQLite. It requires PHP 8.2+, the
`pdo` and `pdo_mysql` extensions, and a MySQL database using InnoDB tables.
No `mysqli` or framework adapter is required.

## Connect to an existing MySQL database

Install the Composer package, then use a **dedicated** connection:

```bash
composer require tihloh/smsgate
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Tihloh\SmsGate\Gateway;

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=your_app;charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$smsgate = Gateway::pdo($pdo, 'smsgate_');
```

The package automatically initializes its own prefixed schema on first use.
You can also call `$smsgate->installSchema()` explicitly from a **trusted
deployment command**. This operation is repeatable and does not remove existing
rows. By default, SMSGate creates ten tables:

`smsgate_meta`, `smsgate_devices`, `smsgate_installations`,
`smsgate_enrollments`, `smsgate_jobs`, `smsgate_claims`,
`smsgate_batches`, `smsgate_receipts`, `smsgate_outbox`, and
`smsgate_annotations`.

You may use the same MySQL database as your application, but use a **new PDO
connection**: SMSGate needs to begin/commit its own transactions and must not be
constructed inside a host transaction. The configurable table prefix keeps the
tables separate from host application tables.

## Permissions and deployment

Use a database account restricted to the appropriate schema. Initial setup
requires `CREATE` and `INDEX` privileges, while routine use requires
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
