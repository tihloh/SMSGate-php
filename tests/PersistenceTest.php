<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Tests;

use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\{Gateway, Support};
use Tihloh\SmsGate\Exception\GatewayException;

final class PersistenceTest extends TestCase
{
    public function testConcurrentProcessesClaimOnceAndReplaysSurviveRestart(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'smsgate-test-');
        self::assertNotFalse($file);
        $gateway = Gateway::sqlite($file); $gateway->installSchema();
        $device = $gateway->registerDevice('Synthetic'); $installation = Support::uuid();
        try {
            try { $gateway->handleDeviceRequest('/v1/jobs/claim', $device->token(), ['deviceId'=>$installation], Support::uuid()); }
            catch (GatewayException $error) { self::assertSame('device_pending_approval', $error->reason); }
            unset($error); // Exception traces can retain the SQLite connection on Windows.
            $gateway->approveDevice($device->id, $installation);
            $gateway->queue($device->id, ['+639171234567'], 'Synthetic only');
            $processes = []; $keys = [Support::uuid(), Support::uuid()];
            foreach ($keys as $key) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/claim-worker.php', $file, $device->token(), $installation, $key], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
                self::assertIsResource($process); fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            }
            self::assertSame(1, count($results[0]['jobs']) + count($results[1]['jobs']));
            unset($gateway); $gateway = Gateway::sqlite($file);
            foreach ($keys as $i => $key) self::assertSame($results[$i], $gateway->handleDeviceRequest('/v1/jobs/claim', $device->token(), ['deviceId'=>$installation], $key));
        } finally { unset($gateway); unlink($file); }
    }
}
