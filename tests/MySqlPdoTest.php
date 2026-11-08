<?php
declare(strict_types=1);

namespace Tihloh\SmsGate\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\Gateway;
use Tihloh\SmsGate\Storage\PdoStore;
use Tihloh\SmsGate\Support;

/**
 * Real MySQL integration test. CI supplies SMSGATE_TEST_MYSQL_*; local SQLite
 * runs skip this test. The random prefix isolates each invocation.
 */
final class MySqlPdoTest extends TestCase
{
    public function testPdoMysqlAutoInstallTransactionsIdempotencyAndJsonFilters(): void
    {
        $dsn = getenv('SMSGATE_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('Provide SMSGATE_TEST_MYSQL_DSN to run the MySQL PDO integration test.');
        }

        $user = getenv('SMSGATE_TEST_MYSQL_USER') ?: '';
        $password = getenv('SMSGATE_TEST_MYSQL_PASSWORD') ?: '';
        $pdo = new PDO($dsn, $user, $password);
        $prefix = 'test_mysql_' . bin2hex(random_bytes(6)) . '_';
        $store = new PdoStore($pdo, $prefix);
        self::assertSame('mysql', $store->driver());

        try {
            // Auto-create InnoDB schema without migrations; repeatable install.
            $gateway = Gateway::pdo($pdo, $prefix);
            $gateway->installSchema();
            $device = $gateway->registerDevice('MySQL PDO gateway');
            $installation = Support::uuid();

            $test = $gateway->process('test', ['deviceId' => $installation], $device->token());
            self::assertSame(200, $test->status);
            self::assertSame('pending', $test->data['status']);
            $gateway->approveDevice($device->id, $installation);

            $batch = $gateway->queue($device->id, ['+639171234567'], 'PDO integration test');
            self::assertCount(1, $batch->jobIds);

            // A fresh connection reads the same state, and repeat claims are safe.
            $fresh = Gateway::pdo(new PDO($dsn, $user, $password), $prefix);
            $key = Support::uuid();
            $claimed = $fresh->handleDeviceRequest('/v1/jobs/claim', $device->token(), [
                'deviceId' => $installation,
            ], $key);
            self::assertCount(1, $claimed['jobs']);
            self::assertSame($batch->jobIds[0], $claimed['jobs'][0]['id']);
            self::assertSame($claimed, $fresh->handleDeviceRequest(
                '/v1/jobs/claim', $device->token(), ['deviceId' => $installation], $key
            ));

            // MySQL JSON_EXTRACT and timestamp filters must match real inbox data.
            $record = $gateway->createIncoming(
                $device->id, '+639171234567', 'Synthetic incoming SMS', 1700000000000
            );
            $matches = $fresh->getInbox($device->id, [
                'sender' => '+639171234567', 'from' => 1699999999999, 'to' => 1700000000001,
            ]);
            self::assertCount(1, $matches);
            self::assertSame($record['id'], $matches[0]['id']);

            // InnoDB must roll back changes if the operation fails.
            try {
                $store->transaction(function () use ($store, $device): void {
                    $store->query(
                        'UPDATE ' . $store->table('devices') . ' SET name=? WHERE id=?',
                        ['Must be rolled back', $device->id]
                    );
                    throw new \RuntimeException('Expected rollback');
                });
                self::fail('Expected transaction failure');
            } catch (\RuntimeException $exception) {
                self::assertSame('Expected rollback', $exception->getMessage());
            }
            self::assertSame('MySQL PDO gateway', $fresh->getDevice($device->id)['name']);
        } finally {
            // Tear down only temporary tables created under this test's random prefix.
            unset($fresh, $gateway);
            foreach ([
                'records', 'jobs', 'installations', 'devices', 'meta',
            ] as $table) {
                $store->query('DROP TABLE IF EXISTS ' . $store->table($table));
            }
        }
    }

    public function testMySqlFactoryReusesExistingDatabaseWithoutTouchingHostTables(): void
    {
        $dsn = getenv('SMSGATE_TEST_MYSQL_DSN');
        if (!$dsn) self::markTestSkipped('MySQL integration environment required.');

        $user = getenv('SMSGATE_TEST_MYSQL_USER') ?: '';
        $password = getenv('SMSGATE_TEST_MYSQL_PASSWORD') ?: '';
        $prefix = 'test_factory_' . bin2hex(random_bytes(5)) . '_';
        $hostTable = 'host_records_' . bin2hex(random_bytes(5));
        $pdo = new PDO($dsn, $user, $password);
        $pdo->exec('CREATE TABLE ' . chr(96) . $hostTable . chr(96) .
            ' (id INTEGER PRIMARY KEY, note VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO ' . chr(96) . $hostTable . chr(96) . " (id,note) VALUES (1,'untouched')");

        try {
            $gateway = Gateway::mysql($dsn, $user, $password, $prefix);
            $device = $gateway->registerDevice('Persistent MySQL device');
            $reused = Gateway::mysql($dsn, $user, $password, $prefix);
            self::assertSame($device->id, $reused->devices()[0]['id']);

            $tables = array_map(
                static fn(string $table): string => $prefix . $table,
                ['meta', 'devices', 'installations', 'jobs', 'records']
            );
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() ' .
                'AND table_name IN (?,?,?,?,?)'
            );
            $stmt->execute($tables);
            self::assertSame(5, (int)$stmt->fetchColumn());
            self::assertSame(
                'untouched',
                $pdo->query('SELECT note FROM ' . chr(96) . $hostTable . chr(96) . ' WHERE id=1')->fetchColumn()
            );
        } finally {
            unset($reused, $gateway);
            foreach (['records', 'jobs', 'installations', 'devices', 'meta'] as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $prefix . $table);
            }
            $pdo->exec('DROP TABLE IF EXISTS ' . chr(96) . $hostTable . chr(96));
        }
    }

    public function testMySqlFactoryCreatesAbsentDatabaseAndFiveTables(): void
    {
        $dsn = getenv('SMSGATE_TEST_MYSQL_DSN');
        $adminUser = getenv('SMSGATE_TEST_MYSQL_ADMIN_USER');
        $adminPassword = getenv('SMSGATE_TEST_MYSQL_ADMIN_PASSWORD');
        if (!$dsn || !$adminUser || $adminPassword === false) {
            self::markTestSkipped('MySQL database-creation integration credentials required.');
        }
        $database = 'smsgate_ci_' . bin2hex(random_bytes(6));
        $newDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsn, 1, $count);
        self::assertSame(1, $count);
        $serverDsn = preg_replace('/(?:^|;)dbname=[^;]+/', '', $dsn, 1);
        $admin = new PDO($serverDsn, $adminUser, $adminPassword);
        $prefix = 'smsgate_';

        self::assertSame(
            0,
            (int)$admin->query(
                "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=" .
                $admin->quote($database)
            )->fetchColumn()
        );

        try {
            $gateway = Gateway::mysql($newDsn, $adminUser, $adminPassword, $prefix);
            $device = $gateway->registerDevice('New database gateway');
            $again = Gateway::mysql($newDsn, $adminUser, $adminPassword, $prefix);
            self::assertSame($device->id, $again->devices()[0]['id']);
            $databasePdo = new PDO($newDsn, $adminUser, $adminPassword);
            $stmt = $databasePdo->query(
                "SELECT COUNT(*) FROM information_schema.tables " .
                "WHERE table_schema=DATABASE() AND table_name LIKE 'smsgate_%'"
            );
            self::assertSame(5, (int)$stmt->fetchColumn());
        } finally {
            unset($again, $gateway, $databasePdo);
            $admin->exec('DROP DATABASE IF EXISTS ' . chr(96) . $database . chr(96));
        }
    }

    public function testMySqlFactoryRejectsUnsafeDatabaseNames(): void
    {
        foreach ([
            'mysql:host=127.0.0.1;dbname=app;DROP DATABASE app',
            'mysql:host=127.0.0.1;dbname=mysql',
            'mysql:host=127.0.0.1;dbname=',
            'sqlite::memory:',
            'mysql:host=127.0.0.1;dbname=app;dbname=other',
        ] as $dsn) {
            try {
                Gateway::mysql($dsn, 'unused', 'unused');
                self::fail('Expected invalid DSN to be rejected: ' . $dsn);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
