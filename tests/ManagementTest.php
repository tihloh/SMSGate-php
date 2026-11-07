<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Tests;

use PHPUnit\Framework\TestCase;
use Tihloh\SmsGate\{Gateway,Support};
use Tihloh\SmsGate\Exception\GatewayException;

final class ManagementTest extends TestCase
{
    public function testDeviceAndInstallationCrudWithoutSql(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone');
        self::assertArrayNotHasKey('token_hash',$sms->getDevice($device->id));
        self::assertSame('Honor',$sms->updateDevice($device->id,['name'=>'Honor'])['name']);
        $installation=Support::uuid(); $sms->registerInstallation($device->id,$installation);
        self::assertSame('approved',$sms->updateInstallation($device->id,$installation,'approved')['status']);
        self::assertTrue($sms->deleteInstallation($device->id,$installation));
        self::assertSame('pending',$sms->process('test',['deviceId'=>$installation],$device->token())->data['status']);
        $sms->createIncoming($device->id,'TEST','Synthetic');
        self::assertTrue($sms->deleteDevice($device->id)); self::assertNull($sms->getDevice($device->id));
        self::assertSame([],$sms->incoming($device->id)); self::assertSame([],$sms->pendingEvents());
        self::assertSame(401,$sms->process('test',['deviceId'=>$installation],$device->token())->status);
    }
    public function testJobUpdateAndDeletePreserveIdempotency(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone'); $key=Support::uuid();
        $job=$sms->queue($device->id,['+639171234567'],'Original',idempotencyKey:$key)->jobIds[0];
        self::assertSame('Edited',$sms->updateJob($job,['body'=>'Edited','metadata'=>['reference'=>'ABC']])['body']);
        self::assertTrue($sms->deleteJob($job)); self::assertNull($sms->getJob($job)); self::assertSame([],$sms->jobs($device->id));
        self::assertSame([$job],$sms->queue($device->id,['+639171234567'],'Original',idempotencyKey:$key)->jobIds);
        self::assertSame([],$sms->jobs($device->id));
    }
    public function testInFlightJobCannotBeEditedOrDeleted(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone'); $installation=Support::uuid();
        $sms->registerInstallation($device->id,$installation); $sms->approveDevice($device->id,$installation);
        $job=$sms->queue($device->id,['+639171234567'],'Synthetic')->jobIds[0];
        $sms->process('pull',['deviceId'=>$installation],$device->token(),Support::uuid());
        try { $sms->updateJob($job,['body'=>'Changed']); self::fail(); } catch (GatewayException $e) { self::assertSame('job_already_claimed',$e->reason); }
        try { $sms->deleteJob($job); self::fail(); } catch (GatewayException $e) { self::assertSame('job_in_flight',$e->reason); }
        self::assertSame(['note'=>'Review'],$sms->updateJob($job,['metadata'=>['note'=>'Review']])['metadata']);
    }
    public function testIncomingMetadataAndDeleteDoNotRestoreDataOnRetry(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone'); $id=Support::uuid();
        $sms->createIncoming($device->id,'TEST','Private',1,1,$id);
        self::assertTrue($sms->updateIncoming($device->id,$id,['read'=>true])['metadata']['read']);
        self::assertTrue($sms->deleteIncoming($device->id,$id)); self::assertNull($sms->getIncoming($device->id,$id));
        self::assertSame([],$sms->incoming($device->id)); self::assertSame([],$sms->pendingEvents());
        self::assertTrue($sms->createIncoming($device->id,'TEST','Private',1,1,$id)['deleted']);
        self::assertSame([],$sms->incoming($device->id));
    }
    public function testFriendlyInboxApiSupportsReadUpdateAndFilteredDelete(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone');
        $first=$sms->createIncoming($device->id,'+639171111111','Private',100);
        $sms->createIncoming($device->id,'+639182222222','Keep',200);
        self::assertSame([$first['id']],array_column($sms->getInbox($device->id,['sender'=>'+639171111111']),'id'));
        self::assertSame(1,$sms->updateInbox($device->id,['id'=>$first['id']],['read'=>true]));
        self::assertTrue($sms->getInboxMessage($device->id,$first['id'])['metadata']['read']);
        self::assertSame(1,$sms->deleteInbox($device->id,['to'=>150]));
        self::assertCount(1,$sms->getInbox($device->id));
    }
    public function testStatusCrudAndLateReportsAfterJobDeletion(): void
    {
        $sms=Gateway::sqlite(':memory:'); $device=$sms->registerDevice('Phone'); $installation=Support::uuid();
        $sms->registerInstallation($device->id,$installation); $sms->approveDevice($device->id,$installation);
        $job=$sms->queue($device->id,['+639171234567'],'Synthetic')->jobIds[0]; $key=Support::uuid();
        $sms->process('pull',['deviceId'=>$installation],$device->token(),$key);
        $report=$sms->createStatusEvent($device->id,$job,'SENT',1);
        self::assertSame(['reviewed'=>true],$sms->updateStatusEvent($device->id,$report['id'],['reviewed'=>true])['metadata']);
        self::assertTrue($sms->deleteStatusEvent($device->id,$report['id'])); self::assertNull($sms->getStatusEvent($device->id,$report['id']));
        self::assertTrue($sms->deleteJob($job));
        self::assertSame([],$sms->process('pull',['deviceId'=>$installation],$device->token(),$key)->data['jobs']);
        $sms->createStatusEvent($device->id,$job,'DELIVERED',2);
        self::assertNull($sms->getJob($job)); // A late carrier report does not resurrect it.
    }
    public function testDeviceScopeAndMetadataValidation(): void
    {
        $sms=Gateway::sqlite(':memory:'); $a=$sms->registerDevice('A'); $b=$sms->registerDevice('B');
        $row=$sms->createIncoming($a->id,'TEST','Private',1);
        self::assertNull($sms->getIncoming($b->id,$row['id'])); self::assertFalse($sms->deleteIncoming($b->id,$row['id']));
        $this->expectException(GatewayException::class);
        $sms->updateIncoming($a->id,$row['id'],['large'=>str_repeat('x',16385)]);
    }
    public function testSharedQueueAndConsolidatedViewsRetainGatewayTracking(): void
    {
        $sms=Gateway::sqlite(':memory:'); $a=$sms->registerDevice('Office A'); $b=$sms->registerDevice('Office B');
        $batches=$sms->queueToDevices([$a->id,$b->id],['+639171234567'],'Hello {{name}}',['name'=>'Team'],idempotencyKey:Support::uuid());
        self::assertCount(2,$batches); self::assertSame(2,count($sms->sharedJobs()));
        $jobs=$sms->sharedJobs(); $aJob=array_values(array_filter($jobs,fn(array $job): bool => $job['gateway']['id']===$a->id))[0]; self::assertSame('Office A',$aJob['gateway']['name']);
        $sms->createIncoming($a->id,'+639171111111','A',1); $sms->createIncoming($b->id,'+639182222222','B',2);
        self::assertCount(2,$sms->sharedInbox()); self::assertSame('Office B',$sms->sharedInbox(['deviceId'=>$b->id])[0]['gateway']['name']);
        $ia=Support::uuid(); $ib=Support::uuid(); $sms->registerInstallation($a->id,$ia); $sms->registerInstallation($b->id,$ib);
        $sms->approveDevice($a->id,$ia); $sms->approveDevice($b->id,$ib);
        self::assertCount(2,$sms->queueToAllApproved(['+639191234567'],'Broadcast',idempotencyKey:Support::uuid()));
    }
}
