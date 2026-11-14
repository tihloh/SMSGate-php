<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

use Tihloh\SmsGate\Exception\GatewayException;

/** @internal Trusted CRUD methods on Gateway, never exposed by process(). */
trait Management
{
    public function getDevice(string $id): ?array
    {
        $row = $this->q($this->deviceOverviewSql() . " WHERE d.id=?", [$id])->fetch();
        return $row ? $this->describeDevice($row) : null;
    }
    public function updateDevice(string $id, array $changes): array
    {
        $this->fields($changes,['name','enabled']);
        return $this->store->transaction(function () use ($id,$changes): array {
            $device=$this->device($id,false);
            $name=array_key_exists('name',$changes) ? trim(Support::text($changes['name'],'name',120)) : $device['name'];
            if ($name==='') throw new GatewayException('invalid_name');
            if (array_key_exists('enabled',$changes) && !is_bool($changes['enabled'])) throw new GatewayException('invalid_enabled');
            $active=array_key_exists('enabled',$changes) ? (int)$changes['enabled'] : (int)$device['active'];
            $this->q("UPDATE {$this->t('devices')} SET name=?,active=? WHERE id=?",[$name,$active,$id]);
            $this->emit($id,'device.updated',['name'=>$name,'enabled'=>(bool)$active]);
            return $this->getDevice($id);
        });
    }
    public function deleteDevice(string $id): bool
    {
        return $this->store->transaction(function () use ($id): bool {
            if (!$this->getDevice($id)) return false;
            foreach (['installations','jobs','records'] as $table) $this->q("DELETE FROM {$this->t($table)} WHERE device_id=?",[$id]);
            $this->q("DELETE FROM {$this->t('devices')} WHERE id=?",[$id]); return true;
        });
    }
    public function registerInstallation(string $deviceId,string $installationId): array
    {
        $installationId=Support::installation($installationId);
        return $this->store->transaction(function () use ($deviceId,$installationId): array {
            $this->device($deviceId,false);
            if (!$this->getInstallation($deviceId,$installationId)) {
                $this->q("INSERT INTO {$this->t('installations')} (device_id,installation_id,status,first_seen) VALUES (?,?,'pending',?)",[$deviceId,$installationId,$this->now()]);
                $this->emit($deviceId,'device.pending',['installationId'=>$installationId]);
            }
            return $this->getInstallation($deviceId,$installationId);
        });
    }
    public function getInstallation(string $deviceId,string $installationId): ?array
    {
        $row = $this->q(
            "SELECT i.installation_id,i.status,i.first_seen,i.reviewed_at,p.created_at AS last_seen_at,p.metadata AS presence_metadata" .
            " FROM {$this->t('installations')} i LEFT JOIN {$this->t('records')} p" .
            " ON p.scope='presence' AND p.device_id=i.device_id AND p.kind='' AND p.id=i.installation_id" .
            " WHERE i.device_id=? AND i.installation_id=?",
            [$deviceId, Support::installation($installationId)]
        )->fetch();
        if (!$row) return null;
        $enabled = (int)$this->device($deviceId, false)['active'] === 1;
        return $this->describeInstallation($row, $enabled);
    }
    public function updateInstallation(string $deviceId,string $installationId,string $status): array
    {
        if (!in_array($status,['pending','approved','blocked'],true)) throw new GatewayException('invalid_installation_status');
        $this->review($deviceId,$installationId,$status); return $this->getInstallation($deviceId,$installationId);
    }
    public function deleteInstallation(string $deviceId,string $installationId): bool
    {
        return $this->store->transaction(fn(): bool => $this->q("DELETE FROM {$this->t('installations')} WHERE device_id=? AND installation_id=?",[$deviceId,Support::installation($installationId)])->rowCount()>0);
    }
    public function getJob(string $id): ?array { return $this->job($id); }
    private function rawJob(string $id): ?array { return $this->q("SELECT * FROM {$this->t('jobs')} WHERE id=?",[$id])->fetch() ?: null; }

    /** Content changes are allowed only before claiming; metadata can be updated later. */
    public function updateJob(string $id,array $changes): array
    {
        $this->fields($changes,['recipient','body','expiresAt','metadata']);
        return $this->store->transaction(function () use ($id,$changes): array {
            $job=$this->job($id);
            if (!$job) throw new GatewayException('job_not_found',404);
            if (array_intersect(array_keys($changes),['recipient','body','expiresAt']) && $job['state']!=='QUEUED') throw new GatewayException('job_already_claimed',409);
            $number=array_key_exists('recipient',$changes) ? $changes['recipient'] : $job['recipient'];
            $number=Template::recipient($number);
            $body=Support::text(array_key_exists('body',$changes) ? $changes['body'] : $job['body'],'body',4800);
            $expiry=array_key_exists('expiresAt',$changes) ? Support::integer($changes['expiresAt'],'expiresAt') : (int)$job['expires_at'];
            if (array_key_exists('expiresAt',$changes) && $expiry<=$this->now()) throw new GatewayException('invalid_expiry');
            $this->q("UPDATE {$this->t('jobs')} SET recipient=?,body=?,expires_at=?,updated_at=? WHERE id=?",[$number,$body,$expiry,$this->now(),$id]);
            if (array_key_exists('metadata',$changes)) $this->saveAnnotation($job['device_id'],'job',$id,$this->metadata($changes['metadata']),false);
            return $this->job($id);
        });
    }
    /** Hide and erase content; retain IDs needed to prevent replay. */
    public function deleteJob(string $id): bool
    {
        return $this->store->transaction(function () use ($id): bool {
            $job=$this->job($id); if (!$job) return false;
            if (in_array($job['state'],['CLAIMED','STORED','SENDING','UNKNOWN'],true)) throw new GatewayException('job_in_flight',409);
            $state=$job['state']==='QUEUED' ? 'CANCELLED' : $job['state'];
            $this->q("UPDATE {$this->t('jobs')} SET recipient='',body='',state=?,updated_at=? WHERE id=?",[$state,$this->now(),$id]);
            $this->saveAnnotation($job['device_id'],'job',$id,[],true);
            foreach ($this->q("SELECT id AS request_key,response FROM {$this->t('records')} WHERE scope='claim' AND device_id=?",[$job['device_id']])->fetchAll() as $claim) {
                $response=json_decode($claim['response'],true,32,JSON_THROW_ON_ERROR);
                $response['jobs']=array_values(array_filter($response['jobs'],static fn(array $row): bool => $row['id']!==$id));
                $this->q("UPDATE {$this->t('records')} SET response=? WHERE scope='claim' AND device_id=? AND id=?",[Support::json($response),$job['device_id'],$claim['request_key']]);
            }
            return true;
        });
    }
    public function createIncoming(string $deviceId,string $sender,string $body,?int $timestamp=null,int $subscriptionId=-1,?string $id=null): array
    {
        $id ??= Support::uuid(); Support::key($id);
        $this->store->transaction(function () use ($deviceId,$sender,$body,$timestamp,$subscriptionId,$id): void {
            $this->device($deviceId,false);
            $this->receive($deviceId,'incoming',['id'=>$id,'sender'=>$sender,'body'=>$body,'timestamp'=>$timestamp ?? $this->now(),'subscriptionId'=>$subscriptionId],$id);
        });
        return $this->getIncoming($deviceId,$id) ?? ['id'=>$id,'deleted'=>true];
    }
    public function createStatusEvent(string $deviceId,string $jobId,string $state,?int $timestamp=null,?string $id=null): array
    {
        $id ??= Support::uuid(); Support::key($id);
        $this->store->transaction(function () use ($deviceId,$jobId,$state,$timestamp,$id): void {
            $this->device($deviceId,false);
            $this->receive($deviceId,'status',['id'=>$id,'jobId'=>$jobId,'state'=>$state,'timestamp'=>$timestamp ?? $this->now()],$id);
        });
        return $this->getStatusEvent($deviceId,$id) ?? ['id'=>$id,'deleted'=>true];
    }
    public function getIncoming(string $deviceId,string $id): ?array { return $this->getReceipt($deviceId,'incoming',$id); }
    public function getInboxMessage(string $deviceId,string $id): ?array { return $this->getIncoming($deviceId,$id); }
    public function getStatusEvent(string $deviceId,string $id): ?array { return $this->getReceipt($deviceId,'status',$id); }
    public function updateIncoming(string $deviceId,string $id,array $metadata): array { return $this->updateReceipt($deviceId,'incoming',$id,$metadata); }
    public function updateStatusEvent(string $deviceId,string $id,array $metadata): array { return $this->updateReceipt($deviceId,'status',$id,$metadata); }
    public function deleteIncoming(string $deviceId,string $id): bool { return $this->deleteReceipt($deviceId,'incoming',$id); }
    public function updateInbox(string $deviceId,string|array $target,array $metadata): int
    {
        $ids=$this->inboxTargetIds($deviceId,$target); $updated=0;
        foreach ($ids as $id) { $this->updateIncoming($deviceId,$id,$metadata); $updated++; }
        return $updated;
    }
    public function deleteInbox(string $deviceId,string|array $target): int
    {
        $ids=$this->inboxTargetIds($deviceId,$target); $deleted=0;
        foreach ($ids as $id) if ($this->deleteIncoming($deviceId,$id)) $deleted++;
        return $deleted;
    }
    public function deleteStatusEvent(string $deviceId,string $id): bool { return $this->deleteReceipt($deviceId,'status',$id); }

    private function getReceipt(string $deviceId,string $kind,string $id): ?array
    {
        $row=$this->q("SELECT payload,created_at FROM {$this->t('records')} WHERE scope='receipt' AND device_id=? AND kind=? AND id=?",[$deviceId,$kind,$id])->fetch();
        if (!$row) return null;
        $annotation=$this->annotation($deviceId,$kind,$id);
        return $annotation['deleted'] ? null : ['id'=>$id,'receivedAt'=>(int)$row['created_at'],'data'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'metadata'=>$annotation['metadata']];
    }
    private function updateReceipt(string $deviceId,string $kind,string $id,array $metadata): array
    {
        return $this->store->transaction(function () use ($deviceId,$kind,$id,$metadata): array {
            if (!$this->getReceipt($deviceId,$kind,$id)) throw new GatewayException('record_not_found',404);
            $this->saveAnnotation($deviceId,$kind,$id,$this->metadata($metadata),false);
            return $this->getReceipt($deviceId,$kind,$id);
        });
    }
    private function deleteReceipt(string $deviceId,string $kind,string $id): bool
    {
        return $this->store->transaction(function () use ($deviceId,$kind,$id): bool {
            if (!$this->getReceipt($deviceId,$kind,$id)) return false;
            $this->q("UPDATE {$this->t('records')} SET payload='{}' WHERE scope='receipt' AND device_id=? AND kind=? AND id=?",[$deviceId,$kind,$id]);
            $this->saveAnnotation($deviceId,$kind,$id,[],true);
            $type=$kind==='incoming' ? 'sms.received' : 'sms.status';
            foreach ($this->q("SELECT id,payload FROM {$this->t('records')} WHERE scope='event' AND device_id=? AND kind=? AND acknowledged=0",[$deviceId,$type])->fetchAll() as $event) {
                if ((json_decode($event['payload'],true,32,JSON_THROW_ON_ERROR)['id'] ?? null)===$id) $this->q("UPDATE {$this->t('records')} SET payload='{}',acknowledged=1 WHERE scope='event' AND id=?",[$event['id']]);
            }
            return true;
        });
    }
    private function annotation(string $deviceId,string $kind,string $id): array
    {
        $row=$this->q("SELECT metadata,deleted FROM {$this->t('records')} WHERE scope='annotation' AND device_id=? AND kind=? AND id=?",[$deviceId,$kind,$id])->fetch();
        return $row ? ['metadata'=>json_decode($row['metadata'],true,32,JSON_THROW_ON_ERROR),'deleted'=>(bool)$row['deleted']] : ['metadata'=>[],'deleted'=>false];
    }
    private function saveAnnotation(string $deviceId,string $kind,string $id,array $metadata,bool $deleted): void
    {
        if ($this->q("SELECT id FROM {$this->t('records')} WHERE scope='annotation' AND device_id=? AND kind=? AND id=?",[$deviceId,$kind,$id])->fetchColumn()!==false) {
            $this->q("UPDATE {$this->t('records')} SET metadata=?,deleted=? WHERE scope='annotation' AND device_id=? AND kind=? AND id=?",[Support::json($metadata),(int)$deleted,$deviceId,$kind,$id]);
        } else $this->q("INSERT INTO {$this->t('records')} (scope,device_id,kind,id,metadata,deleted) VALUES ('annotation',?,?,?,?,?)",[$deviceId,$kind,$id,Support::json($metadata),(int)$deleted]);
    }
    private function metadata(mixed $value): array
    {
        if (!is_array($value) || strlen(Support::json($value))>16384) throw new GatewayException('invalid_metadata');
        json_decode(Support::json($value),true,32,JSON_THROW_ON_ERROR); return $value;
    }
    private function inboxTargetIds(string $deviceId,string|array $target): array
    {
        if (is_string($target)) return $this->getIncoming($deviceId,$target) ? [$target] : [];
        if ($target===[]) throw new GatewayException('empty_filter');
        return array_column($this->getInbox($deviceId,$target,1000), 'id');
    }
    private function fields(array $changes,array $allowed): void
    {
        if (array_diff(array_keys($changes),$allowed)) throw new GatewayException('unknown_update_field');
    }
}
