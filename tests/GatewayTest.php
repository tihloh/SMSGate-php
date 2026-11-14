<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Tests;

use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\{Gateway, DeviceCredentials, Support, Template};
use Tihloh\SmsGate\Exception\GatewayException;
use Tihloh\SmsGate\Storage\PdoStore;

final class GatewayTest extends TestCase
{
    private Gateway $gateway;
    private DeviceCredentials $device;
    private string $installation;
    private int $time = 1000000000000;
    private ?PdoStore $mysqlStore = null;

    protected function setUp(): void
    {
        $dsn = getenv('SMSGATE_TEST_MYSQL_DSN');
        $store = $dsn
            ? $this->mysqlStore = new PdoStore(new \PDO($dsn, getenv('SMSGATE_TEST_MYSQL_USER'), getenv('SMSGATE_TEST_MYSQL_PASSWORD')), 'test_' . bin2hex(random_bytes(8)) . '_')
            : new PdoStore(new \PDO('sqlite::memory:'));
        $this->gateway = new Gateway($store, fn() => $this->time);
        $this->gateway->installSchema();
        $this->device = $this->gateway->registerDevice('Honor X9c');
        $this->installation = Support::uuid();
    }

    protected function tearDown(): void
    {
        if ($this->mysqlStore !== null) {
            // Only tables in this test's freshly generated random prefix are removed.
            foreach (['records','jobs','installations','devices','meta'] as $table) {
                $this->mysqlStore->query('DROP TABLE IF EXISTS ' . $this->mysqlStore->table($table));
            }
        }
    }

    private function call(string $path = '/v1/jobs/claim', array $body = [], ?string $key = null): array
    {
        return $this->gateway->handleDeviceRequest($path, $this->device->token(), ['deviceId' => $this->installation] + $body, $key ?? Support::uuid());
    }

    private function denied(callable $call, string $reason): void
    {
        try { $call(); self::fail('Expected rejection: ' . $reason); }
        catch (GatewayException $error) { self::assertSame($reason, $error->reason); }
    }

    private function approve(): void
    {
        $this->denied(fn() => $this->call(), 'device_pending_approval');
        $this->gateway->approveDevice($this->device->id, $this->installation);
    }

    public function testDevicePresenceUsesAuthenticatedHeartbeatsAndExpires(): void
    {
        $deviceId = $this->device->id;
        $this->approve();
        $initial = $this->gateway->api()->getDeviceStatus($deviceId);
        self::assertSame('offline', $initial['presenceStatus']);
        self::assertFalse($initial['online']);
        self::assertNull($initial['lastSeenAt']);

        // An ordinary authenticated request proves recent reachability, but
        // cannot claim that the Android gateway is running.
        $this->call('/v1/test');
        $seen = $this->gateway->api()->listDeviceStatuses()[0];
        self::assertTrue($seen['enabled']);
        self::assertTrue($seen['online']);
        self::assertFalse($seen['connected']);
        self::assertSame('online', $seen['presenceStatus']);
        self::assertSame($this->time, $seen['lastSeenAt']);

        $heartbeat = $this->gateway->process('heartbeat', [
            'deviceId' => $this->installation, 'running' => true, 'pollSeconds' => 30,
        ], $this->device->token(), Support::uuid());
        self::assertSame(200, $heartbeat->status);
        $online = $this->gateway->api()->getDeviceStatus($deviceId);
        self::assertTrue($online['connected']);
        self::assertSame('connected', $online['presenceStatus']);
        self::assertSame($this->time, $online['lastHeartbeatAt']);
        self::assertSame(30, $online['pollSeconds']);
        self::assertTrue($online['reportedRunning']);
        $install = $this->gateway->installations($deviceId)[0];
        self::assertTrue($install['connected']);
        self::assertSame($this->time, $install['lastSeenAt']);

        $this->time += 120001;
        $stale = $this->gateway->api()->getDeviceStatus($deviceId);
        self::assertFalse($stale['online']);
        self::assertFalse($stale['connected']);
        self::assertSame('offline', $stale['presenceStatus']);
        self::assertSame($this->time - 120001, $stale['lastSeenAt']);

        $this->gateway->process('heartbeat', [
            'deviceId' => $this->installation, 'running' => false, 'pollSeconds' => 30,
        ], $this->device->token(), Support::uuid());
        $stopped = $this->gateway->api()->getDeviceStatus($deviceId);
        self::assertTrue($stopped['online']);
        self::assertFalse($stopped['connected']);
        self::assertFalse($stopped['reportedRunning']);

        $this->gateway->setDeviceEnabled($deviceId, false);
        $disabled = $this->gateway->api()->getDeviceStatus($deviceId);
        self::assertSame('disabled', $disabled['presenceStatus']);
        self::assertFalse($disabled['online']);
        $this->gateway->setDeviceEnabled($deviceId, true);

        $this->gateway->blockDevice($deviceId, $this->installation);
        $unpaired = $this->gateway->api()->getDeviceStatus($deviceId);
        self::assertSame('unpaired', $unpaired['presenceStatus']);
        self::assertFalse($unpaired['connected']);
    }

    public function testPresenceRequiresApprovalAndRejectsInvalidClaims(): void
    {
        $this->denied(fn() => $this->call('/v1/presence', ['running'=>true,'pollSeconds'=>30]), 'device_pending_approval');
        $this->approve();
        foreach ([
            ['running' => 'true', 'pollSeconds' => 30],
            ['running' => true, 'pollSeconds' => 0],
            ['running' => true, 'pollSeconds' => '30'],
            ['running' => true, 'pollSeconds' => 3601],
        ] as $body) {
            $response = $this->gateway->process('heartbeat', ['deviceId' => $this->installation] + $body,
                $this->device->token(), Support::uuid());
            self::assertSame(422, $response->status);
        }
        self::assertSame(401, $this->gateway->process('heartbeat', [
            'deviceId' => $this->installation, 'running' => true, 'pollSeconds' => 30,
        ], str_repeat('a', 64), Support::uuid())->status);
        self::assertNull($this->gateway->api()->getDeviceStatus($this->device->id)['lastHeartbeatAt']);
    }

    public function testPendingPersistsAndCannotSelfApprove(): void
    {
        $this->denied(fn() => $this->call(), 'device_pending_approval');
        $this->denied(fn() => $this->call(), 'device_pending_approval');
        self::assertCount(1, $this->gateway->installations($this->device->id));
        self::assertCount(1, $this->gateway->pendingEvents());
        $this->denied(fn() => $this->call('/admin/approve'), 'not_found');
        $this->gateway->approveDevice($this->device->id, $this->installation);
        self::assertSame(['jobs' => []], $this->call());
    }

    public function testInvalidTokenDoesNotEnrollAnInstallation(): void
    {
        $this->denied(fn() => $this->gateway->handleDeviceRequest('/v1/jobs/claim', str_repeat('a', 64), ['deviceId' => $this->installation], Support::uuid()), 'unauthorized');
        self::assertSame([], $this->gateway->installations($this->device->id));
    }

    public function testBulkTemplatesAreAtomicAndIdempotent(): void
    {
        $key = Support::uuid();
        $recipients = [['number' => '+639171234567', 'values' => ['name' => 'Juan']], ['number' => '+639181234567', 'values' => ['name' => 'Maria']]];
        $batch = $this->gateway->queue($this->device->id, $recipients, 'Hi {{name}} — {{office}} ₱{{amount}}', ['office' => 'PBO', 'amount' => '12500.00'], $key);
        self::assertCount(2, $batch->jobIds);
        self::assertSame($batch->jobIds, $this->gateway->queue($this->device->id, $recipients, 'Hi {{name}} — {{office}} ₱{{amount}}', ['amount' => '12500.00', 'office' => 'PBO'], $key)->jobIds);
        self::assertSame('Hi Juan — PBO ₱12500.00', $this->gateway->job($batch->jobIds[0])['body']);
        $this->denied(fn() => $this->gateway->queue($this->device->id, $recipients, 'Different', [], $key), 'idempotency_conflict');
        $this->denied(fn() => $this->gateway->queue($this->device->id, [$recipients[0], '+639181234567'], '{{name}}'), 'missing_placeholder');
        self::assertCount(2, $this->gateway->jobs($this->device->id));
    }

    public function testClaimsReplayWithoutDoubleAssigningJobs(): void
    {
        $this->approve();
        $batch = $this->gateway->queue($this->device->id, ['+639171234567'], 'Hello');
        $key = Support::uuid(); $first = $this->call(key: $key);
        self::assertSame($batch->jobIds[0], $first['jobs'][0]['id']);
        self::assertSame($first, $this->call(key: $key));
        self::assertSame(['jobs' => []], $this->call());
        $this->denied(fn() => $this->call(body: ['limit' => 5], key: $key), 'idempotency_conflict');
        self::assertFalse($this->gateway->cancelJob($batch->jobIds[0]));
    }

    public function testBlockingAlsoBlocksCachedClaimsAndUploads(): void
    {
        $this->approve(); $key = Support::uuid(); $this->call(key: $key);
        $this->gateway->blockDevice($this->device->id, $this->installation);
        foreach (['/v1/jobs/claim', '/v1/incoming', '/v1/events'] as $route) $this->denied(fn() => $this->call($route, key: $key), 'device_blocked');
        self::assertSame('blocked', $this->gateway->installations($this->device->id)[0]['status']);
    }

    public function testApprovingReplacementBlocksPreviousPhone(): void
    {
        $this->approve(); $old = $this->installation; $this->installation = Support::uuid();
        $this->approve(); self::assertSame(['jobs' => []], $this->call());
        $this->installation = $old;
        $this->denied(fn() => $this->call(), 'device_blocked');
    }

    public function testTokensAreHashedRotatableAndRevocable(): void
    {
        $this->approve(); $old = $this->device->token();
        self::assertArrayNotHasKey('token_hash', $this->gateway->devices()[0]);
        ob_start(); var_dump($this->device); $dump = ob_get_clean();
        self::assertStringNotContainsString($old, $dump);
        $this->device = $this->gateway->rotateToken($this->device->id);
        $this->denied(fn() => $this->gateway->handleDeviceRequest('/v1/jobs/claim', $old, ['deviceId' => $this->installation], Support::uuid()), 'unauthorized');
        $this->denied(fn() => $this->call(), 'device_blocked');
        $this->gateway->approveDevice($this->device->id, $this->installation);
        $this->gateway->setDeviceEnabled($this->device->id, false);
        $this->denied(fn() => $this->call(), 'unauthorized');
        $this->gateway->setDeviceEnabled($this->device->id, true);
        self::assertSame(['jobs' => []], $this->call());
    }

    public function testIncomingDeduplicationAndDurableOutbox(): void
    {
        $this->approve();
        foreach ($this->gateway->pendingEvents() as $event) $this->gateway->acknowledgeEvent($event['id']);
        $id = Support::uuid(); $body = ['id' => $id, 'sender' => 'BANK', 'body' => 'Synthetic', 'timestamp' => 1, 'subscriptionId' => 1];
        self::assertSame(['ackId' => $id], $this->call('/v1/incoming', $body, $id));
        self::assertSame(['ackId' => $id], $this->call('/v1/incoming', $body, $id));
        self::assertCount(1, $this->gateway->incoming($this->device->id));
        $events = $this->gateway->pendingEvents();
        self::assertCount(1, $events); self::assertSame('sms.received', $events[0]['type']);
        self::assertSame($events, $this->gateway->pendingEvents());
        $body['body'] = 'changed';
        $this->denied(fn() => $this->call('/v1/incoming', $body, $id), 'idempotency_conflict');
        $this->gateway->acknowledgeEvent($events[0]['id']);
        self::assertSame([], $this->gateway->pendingEvents());
    }

    public function testLateReportsDoNotRegressDeliveryState(): void
    {
        $this->approve(); $batch = $this->gateway->queue($this->device->id, ['+639171234567'], 'Hello'); $this->call();
        foreach (['UNKNOWN','SENT','DELIVERED','SENDING','FAILED'] as $state) {
            $id = Support::uuid();
            $this->call('/v1/events', ['id' => $id, 'jobId' => $batch->jobIds[0], 'state' => $state, 'timestamp' => $this->time], $id);
        }
        self::assertSame('DELIVERED', $this->gateway->job($batch->jobIds[0])['state']);
        self::assertCount(5, $this->gateway->statusEvents($this->device->id));
    }

    public function testExpiryAndCancellationPreventClaims(): void
    {
        $this->approve();
        $expired = $this->gateway->queue($this->device->id, ['+639171234567'], 'Expire', expiresAt: $this->time + 1);
        $cancel = $this->gateway->queue($this->device->id, ['+639171234567'], 'Cancel');
        self::assertTrue($this->gateway->cancelJob($cancel->jobIds[0]));
        $this->time += 2;
        self::assertSame(['jobs' => []], $this->call());
        self::assertSame('EXPIRED', $this->gateway->job($expired->jobIds[0])['state']);
        self::assertSame('CANCELLED', $this->gateway->job($cancel->jobIds[0])['state']);
    }

    public function testDeviceCannotReportAnotherDevicesJob(): void
    {
        $this->approve(); $other = $this->gateway->registerDevice('Other');
        $batch = $this->gateway->queue($other->id, ['+639171234567'], 'Private');
        self::assertSame(['jobs' => []], $this->call());
        $id = Support::uuid();
        $this->denied(fn() => $this->call('/v1/events', ['id' => $id, 'jobId' => $batch->jobIds[0], 'state' => 'SENT', 'timestamp' => 1], $id), 'job_not_found');
    }

    public function testRollbackAndRepeatedSchemaInstall(): void
    {
        $pdo = new \PDO('sqlite::memory:'); $store = new PdoStore($pdo, 'test_');
        $store->install(); $store->install();
        try { $store->transaction(function () use ($store): void { $store->query("INSERT INTO test_devices VALUES ('id','name','hash',1,1)"); throw new \RuntimeException('Abort'); }); }
        catch (\RuntimeException) {}
        self::assertSame(0, (int)$store->query('SELECT COUNT(*) FROM test_devices')->fetchColumn());
        $store->transaction(fn() => $store->query("INSERT INTO test_devices VALUES ('id','name','hash',1,1)"));
        self::assertSame(1, (int)$store->query('SELECT COUNT(*) FROM test_devices')->fetchColumn());
    }

    public function testTemplatesRenderOnceWithArbitraryKeysAndLocalOverrides(): void
    {
        $jobs = Template::prepare([['number' => '+639171234567', 'values' => ['name' => '{{untouched}}', 'office.name' => 'PBO']], '+639181234567'], '{{ name }} {{office.name}} {{amount}}', ['name' => 'Maria', 'office.name' => 'PTO', 'amount' => '12500.00']);
        self::assertSame('{{untouched}} PBO 12500.00', $jobs[0]['body']);
        self::assertSame('Maria PTO 12500.00', $jobs[1]['body']);
        self::assertCount(1, Template::prepare(['+639171234567','+639171234567'], 'Same'));
        $this->denied(fn() => Template::prepare(['+639171234567'], '{{bad'), 'invalid_template');
        $this->denied(fn() => Template::prepare(['+639171234567'], '{{name}}', ['name' => []]), 'invalid_placeholder_value');
        $this->denied(fn() => Template::prepare(['+639171234567'], '{{name}}', ['name' => str_repeat('x', 4801)]), 'invalid_rendered_body');
    }

    public function testInvalidBatchesAndPaginationDoNotWriteJobs(): void
    {
        $this->denied(fn() => Template::prepare([], 'hello'), 'invalid_recipients');
        $this->denied(fn() => Template::prepare(array_fill(0, 101, '+639171234567'), 'hello'), 'invalid_recipients');
        $this->denied(fn() => Template::prepare([['number' => '+639171234567', 'values' => null]], 'hello'), 'invalid_values');
        self::assertSame('+639171234567', Template::prepare(['09171234567'], 'hello')[0]['recipient']);
        $this->denied(fn() => $this->gateway->queue($this->device->id, ['08171234567'], 'hello'), 'invalid_recipient');
        $this->denied(fn() => $this->gateway->queue($this->device->id, ['+639171234567'], 'hello', expiresAt: 1), 'invalid_expiry');
        self::assertSame([], $this->gateway->jobs($this->device->id));
        $this->expectException(\InvalidArgumentException::class);
        $this->gateway->jobs($this->device->id, -1);
    }

    public function testReceiptRetryRequiresSamePayloadAndMatchingKey(): void
    {
        $this->approve(); $batch = $this->gateway->queue($this->device->id, ['+639171234567'], 'Hello'); $this->call();
        $id = Support::uuid();
        $body = ['id' => $id, 'jobId' => $batch->jobIds[0], 'state' => 'SENT', 'timestamp' => 1];
        self::assertSame(['ackId' => $id], $this->call('/v1/events', $body, $id));
        self::assertSame(['ackId' => $id], $this->call('/v1/events', $body, $id));
        self::assertCount(1, $this->gateway->statusEvents($this->device->id));
        $body['state'] = 'FAILED';
        $this->denied(fn() => $this->call('/v1/events', $body, $id), 'idempotency_conflict');
        $this->denied(fn() => $this->call('/v1/events', $body), 'idempotency_mismatch');
        self::assertSame('SENT', $this->gateway->job($batch->jobIds[0])['state']);
    }

    public function testDisabledDeviceCanBeBlockedBeforeReenabling(): void
    {
        $this->approve();
        $this->gateway->setDeviceEnabled($this->device->id, false);
        $this->gateway->blockDevice($this->device->id, $this->installation);
        $this->gateway->setDeviceEnabled($this->device->id, true);
        $this->denied(fn() => $this->call(), 'device_blocked');
    }

    public function testStoreRejectsUnsafePrefixesAndNestedTransactions(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        try { new PdoStore($pdo, 'x; DROP TABLE devices'); self::fail('Invalid prefix accepted'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $store = new PdoStore($pdo); $store->install(); $pdo->beginTransaction();
        try { $store->transaction(static fn() => null); self::fail('External transaction accepted'); }
        catch (\LogicException) { self::assertTrue($pdo->inTransaction()); }
        finally { $pdo->rollBack(); }
    }
}
