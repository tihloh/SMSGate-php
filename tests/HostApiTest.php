<?php
declare(strict_types=1);

namespace Tihloh\SmsGate\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\Gateway;
use Tihloh\SmsGate\HostApi;
use Tihloh\SmsGate\Support;

final class HostApiTest extends TestCase
{
    public function testHostApiOwnsListsQueueAndMutationsWithoutHostSql(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE host_orders (id INTEGER PRIMARY KEY, label VARCHAR(20))');
        $pdo->exec("INSERT INTO host_orders VALUES (1, 'unchanged')");

        $gateway = Gateway::database($pdo, 'smsgate_');
        $api = $gateway->api();
        self::assertInstanceOf(HostApi::class, $api);

        // Fixture: gateway registration is normally initiated from pairing QR.
        $device = $gateway->registerDevice('Test phone');
        self::assertSame($device->id, $api->listDevices()[0]['id']);
        self::assertSame('Test phone', $api->getDevice($device->id)['name']);

        $installation = Support::uuid();
        $gateway->registerInstallation($device->id, $installation);
        self::assertSame('pending', $api->listInstallations($device->id)[0]['status']);
        $api->approveInstallation($device->id, $installation);
        self::assertSame('approved', $api->listInstallations($device->id)[0]['status']);

        $key = Support::uuid();
        $first = $api->queueSms($device->id, ['+639171234567'], 'Host API test', requestKey: $key);
        $retry = $api->queueSms($device->id, ['+639171234567'], 'Host API test', requestKey: $key);
        self::assertSame($first, $retry);
        self::assertCount(1, $first['jobIds']);
        self::assertSame($key, $first['idempotencyKey']);
        self::assertSame($first['jobIds'][0], $api->listJobs(['state'=>'QUEUED'])[0]['id']);
        self::assertSame($device->id, $api->listJobs(['deviceId'=>$device->id])[0]['gateway']['id']);
        self::assertCount(1, $api->listDeviceJobs($device->id));
        self::assertSame($first['jobIds'][0], $api->getJob($first['jobIds'][0])['id']);

        $incoming = $gateway->createIncoming($device->id, '+639171234567', 'Synthetic', 123);
        self::assertCount(1, $api->listInbox(['deviceId' => $device->id, 'sender' => '+639171234567']));
        self::assertSame($incoming['id'], $api->getInboxMessage($device->id, $incoming['id'])['id']);
        self::assertSame(1, $api->updateInbox($device->id, $incoming['id'], ['read'=>true]));
        self::assertTrue($api->getInboxMessage($device->id, $incoming['id'])['metadata']['read']);

        self::assertTrue($api->cancelJob($first['jobIds'][0]));
        self::assertSame('CANCELLED', $api->getJob($first['jobIds'][0])['state']);
        self::assertSame(
            'unchanged',
            $pdo->query('SELECT label FROM host_orders WHERE id=1')->fetchColumn()
        );
        self::assertSame(
            5,
            (int)$pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'smsgate_%'"
            )->fetchColumn()
        );
    }

    public function testHostApiCreatesImageForUiWithoutExposingTokenInResult(): void
    {
        $api = Gateway::sqlite(':memory:')->api();
        $pairing = $api->createPairing('https://gateway.example.org/smsgate', 'Office');
        self::assertSame(['enrollmentId', 'image'], array_keys($pairing));
        self::assertNotEmpty($pairing['enrollmentId']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $pairing['image']);
        $svg = base64_decode(substr($pairing['image'], strlen('data:image/svg+xml;base64,')), true);
        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);

        foreach ([
            ['https://gateway.example.org/smsgate', 'Office', 'pdf', 400],
            ['https://gateway.example.org/smsgate', 'Office', 'svg', 20],
            ['https://bad.example.org/?secret=1', 'Office', 'svg', 400],
        ] as [$url, $operator, $format, $size]) {
            try {
                $api->createPairing($url, $operator, $format, $size);
                self::fail('Invalid pairing request must be rejected');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
