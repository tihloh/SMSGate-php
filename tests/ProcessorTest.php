<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Tests;

use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\{Gateway,Support,SetupProfile};

final class ProcessorTest extends TestCase
{
    public function testPlainProcessorAndConnectionTestEnrollWithoutSms(): void
    {
        $sms=Gateway::sqlite(':memory:'); // No explicit schema installation.
        $device=$sms->registerDevice('Phone'); $installation=Support::uuid();
        $body=['deviceId'=>$installation];
        $test=$sms->process('test',$body,$device->token());
        self::assertSame(200,$test->status); self::assertFalse($test->data['approved']);
        self::assertSame('pending',$test->data['status']); self::assertSame($installation,$test->data['installationId']);
        self::assertSame('pending',$sms->process(['action'=>'test','deviceId'=>$installation],token:$device->token())->data['status']);
        self::assertSame([],$sms->incoming($device->id)); self::assertSame([],$sms->jobs($device->id));
        self::assertSame(403,$sms->process('pull',$body,$device->token(),Support::uuid())->status);
        $sms->approveDevice($device->id,$installation);
        self::assertTrue($sms->process('test',$body,$device->token())->data['approved']);
        $response=$sms->process('pull',$body,$device->token(),Support::uuid());
        self::assertSame(['jobs'=>[]],$response->data); self::assertSame('{"jobs":[]}',$response->json());
        self::assertSame('no-store',$response->headers()['Cache-Control']);
        $sms->blockDevice($device->id,$installation);
        self::assertSame('blocked',$sms->process('test',$body,$device->token())->data['status']);
    }
    public function testUnknownActionsAndInvalidInput(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone');
        self::assertSame(404,$sms->process('approve',[],$device->token())->status);
        self::assertSame(401,$sms->process('test',['deviceId'=>Support::uuid()],'bad')->status);
        self::assertSame(422,$sms->process('test',[],$device->token())->status);
        self::assertSame(413,$sms->process('test',['large'=>str_repeat('x',1048577)],$device->token())->status);
    }
    public function testOneTimeEnrollmentCreatesNamedPendingGatewayOnlyAfterPhoneTests(): void
    {
        $sms=Gateway::sqlite(':memory:'); $enrollment=$sms->createEnrollment();
        self::assertSame([],$sms->devices());
        $installation=Support::uuid();
        $response=$sms->process('test',['deviceId'=>$installation,'gatewayName'=>'HONOR BRP-NX1'],$enrollment->token());
        self::assertSame(200,$response->status); self::assertSame('pending',$response->data['status']);
        self::assertSame('HONOR BRP-NX1',$sms->getDevice($response->data['deviceId'])['name']);
        self::assertSame(200,$sms->process('test',['deviceId'=>Support::uuid(),'gatewayName'=>'Other'],$enrollment->token())->status);
        self::assertCount(1,$sms->devices());
    }
    public function testCallbackCommitsBeforeRunningAndRetriesOnNextAndroidRequest(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone'); $installation=Support::uuid();
        $sms->registerInstallation($device->id,$installation); $sms->approveDevice($device->id,$installation);
        $calls=0;
        $sms->on('sms.received',function (array $event) use ($sms,$device,&$calls): void {
            $calls++; self::assertCount(1,$sms->incoming($device->id));
            if ($calls===1) throw new \RuntimeException('Business system unavailable');
            $sms->updateIncoming($device->id,$event['data']['id'],['processed'=>true]);
        });
        $id=Support::uuid(); $body=['deviceId'=>$installation,'id'=>$id,'sender'=>'TEST','body'=>'Synthetic','timestamp'=>1,'subscriptionId'=>1];
        self::assertSame(200,$sms->process('push',$body,$device->token(),$id)->status);
        self::assertSame(1,$calls); self::assertCount(1,$sms->callbackErrors());
        self::assertSame(200,$sms->process('test',['deviceId'=>$installation],$device->token())->status);
        self::assertSame(2,$calls); self::assertSame([],$sms->callbackErrors());
        self::assertTrue($sms->getIncoming($device->id,$id)['metadata']['processed']);
        $sms->process('push',$body,$device->token(),$id); self::assertSame(2,$calls);
    }
    public function testUnmatchedEventsDoNotStarveCallbacksAndBadTokenCannotTriggerThem(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone'); $installation=Support::uuid();
        $sms->registerInstallation($device->id,$installation);
        $sms->createIncoming($device->id,'TEST','Synthetic'); $calls=0;
        $sms->on('sms.received',function () use (&$calls): void { $calls++; });
        $sms->process('test',['deviceId'=>$installation],str_repeat('a',64)); self::assertSame(0,$calls);
        $sms->process('test',['deviceId'=>$installation],$device->token()); self::assertSame(1,$calls);
    }
    public function testSchemaAddsMissingTableAndPreservesExistingRows(): void
    {
        $pdo=new \PDO('sqlite::memory:'); $sms=Gateway::pdo($pdo); $device=$sms->registerDevice('Phone');
        $pdo->exec('DROP TABLE smsgate_records');
        $reopened=Gateway::pdo($pdo); self::assertSame('Phone',$reopened->getDevice($device->id)['name']);
        self::assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='smsgate_records'")->fetchColumn());
    }
    public function testStorageErrorDoesNotLeakDetails(): void
    {
        $pdo=new \PDO('sqlite::memory:'); $sms=Gateway::pdo($pdo); $device=$sms->registerDevice('Phone');
        $pdo->exec('DROP TABLE smsgate_devices');
        $response=$sms->process('test',['deviceId'=>Support::uuid()],$device->token());
        self::assertSame(500,$response->status); self::assertSame('{"error":"server_error"}',$response->json());
    }
    public function testSetupStillSupportsJsonAndEncodedCodes(): void
    {
        $profile=new SetupProfile('http://10.1.0.70/api','Office ₱','test-token');
        self::assertSame($profile->toArray(),json_decode($profile->toJson(),true));
        self::assertSame($profile->toArray(),json_decode($profile->toJson(),true));
        ob_start(); var_dump($profile); $dump=ob_get_clean(); self::assertStringNotContainsString('test-token',$dump);
    }

    public function testPairingQrImageIsGeneratedByThePackage(): void
    {
        $profile = new SetupProfile(
            'https://example.org/smsgate/api',
            'Office integration',
            'private-pairing-token'
        );
        $svg = $profile->qrSvg();
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('</svg>', $svg);
        self::assertStringNotContainsString('private-pairing-token', $svg);

        $uri = $profile->qrDataUri();
        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        self::assertSame($svg, base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true));

        $gateway = Gateway::sqlite(':memory:');
        $enrollment = $gateway->createEnrollment();
        $fromEnrollment = $enrollment->qrDataUri('https://example.org/smsgate', 'Office');
        self::assertStringStartsWith('data:image/svg+xml;base64,', $fromEnrollment);
        self::assertStringContainsString('<svg', $enrollment->qrSvg('https://example.org/smsgate', 'Office'));

        if (extension_loaded('gd')) {
            self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $profile->qrPng());
            self::assertStringStartsWith('data:image/png;base64,', $profile->qrDataUri('png'));
        }
    }

    public function testPairingQrRejectsUnsupportedSizeAndFormats(): void
    {
        $profile = new SetupProfile('https://example.org/api', 'Office', 'secret-token');
        foreach ([0, 100, 2000] as $size) {
            try {
                $profile->qrSvg($size);
                self::fail('Expected invalid QR dimensions');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $profile->qrDataUri('pdf');
    }
}
