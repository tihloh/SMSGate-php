<?php
declare(strict_types=1);

namespace Tihloh\SmsGate\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\Gateway;

final class CompactSchemaTest extends TestCase
{
    public function testLegacySchemaIsRefusedBeforeCreatingNewTables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE smsgate_meta (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO smsgate_meta (id,version) VALUES (1,1)');
        try {
            Gateway::pdo($pdo);
            self::fail('Version 1 should require explicit data migration.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('version 1 first', $error->getMessage());
        }
        self::assertSame(0, (int)$pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE name='smsgate_records'"
        )->fetchColumn());
    }

    public function testFivePhysicalTablesAndIsolatedRecordScopes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $gate = Gateway::pdo($pdo, 'compact_');
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'compact_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'compact_devices',
            'compact_installations',
            'compact_jobs',
            'compact_meta',
            'compact_records',
        ], $names);
        self::assertSame(2, (int)$pdo->query('SELECT version FROM compact_meta WHERE id=1')->fetchColumn());
        $enrollment = $gate->createEnrollment();
        $device = $gate->registerDevice('Compact phone');
        $pending = $gate->registerInstallation($device->id, 'e1234567-89ab-4cde-8123-000000000001');
        self::assertSame('pending', $pending['status']);
        $gate->approveDevice($device->id, $pending['installation_id']);
        $batch = $gate->queue($device->id, ['+639171234567'], 'Hello', idempotencyKey: 'same-key-1234567');
        self::assertSame($batch->jobIds, $gate->queue($device->id, ['+639171234567'], 'Hello', idempotencyKey: 'same-key-1234567')->jobIds);
        $inbox = $gate->createIncoming($device->id, '+639171234567', 'Hello received');
        self::assertSame('Hello received', $inbox['data']['body']);
        $gate->updateInbox($device->id, $inbox['id'], ['read'=>true]);
        self::assertTrue($gate->getInboxMessage($device->id, $inbox['id'])['metadata']['read']);
        self::assertSame(1, $gate->deleteInbox($device->id, $inbox['id']));
        self::assertNull($gate->getInboxMessage($device->id, $inbox['id']));
        self::assertNotEmpty($gate->pendingEvents());

        $rows = $pdo->query("SELECT scope,COUNT(*) AS n FROM compact_records GROUP BY scope")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['enrollment','batch','receipt','annotation','event'] as $scope) {
            self::assertArrayHasKey($scope, $rows);
        }
        self::assertSame(5, count($names));
    }
}
