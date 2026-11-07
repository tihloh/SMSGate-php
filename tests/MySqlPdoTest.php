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
                'annotations', 'outbox', 'receipts', 'batches', 'claims',
                'jobs', 'installations', 'enrollments', 'devices', 'meta',
            ] as $table) {
                $store->query('DROP TABLE IF EXISTS ' . $store->table($table));
            }
        }
    }
}
