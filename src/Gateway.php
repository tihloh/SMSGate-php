<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

use PDO;
use Tihloh\SmsGate\Exception\GatewayException;
use Tihloh\SmsGate\Storage\PdoStore;

/** Trusted CRUD/business API. Expose only process() to Android device requests. */
final class Gateway
{
    use Management;
    use RequestEvents;
    private readonly \Closure $clock;
    private array $listeners = [];
    private array $callbackErrors = [];
    private bool $dispatching = false;

    public function __construct(private readonly PdoStore $store, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn(): int => (int)floor(microtime(true) * 1000) : \Closure::fromCallable($clock);
        $this->store->ensureInstalled();
    }

    public static function sqlite(string $path): self
    {
        if ($path !== ':memory:' && !str_starts_with($path, 'file:')) {
            $folder = dirname($path);
            if (!is_dir($folder) && !mkdir($folder, 0770, true) && !is_dir($folder)) throw new \RuntimeException('Cannot create SMSGate storage directory');
        }
        return new self(new PdoStore(new PDO('sqlite:' . $path)));
    }
    public static function pdo(PDO $pdo, string $prefix = 'smsgate_'): self { return new self(new PdoStore($pdo, $prefix)); }
    public function process(string|array|null $request = null, ?array $data = null, #[\SensitiveParameter] ?string $token = null, ?string $idempotencyKey = null): Http\Response
    {
        return (new Http\Processor($this))->process($request, $data, $token, $idempotencyKey);
    }
    public function installSchema(): void { $this->store->install(); }
    private function now(): int { return ($this->clock)(); }
    private function t(string $table): string { return $this->store->table($table); }
    private function q(string $sql, array $args = []): \PDOStatement { return $this->store->query($sql, $args); }

    public function registerDevice(string $name): DeviceCredentials
    {
        $name = trim(Support::text($name, 'name', 120));
        if ($name === '') throw new GatewayException('invalid_name');
        return $this->store->transaction(function () use ($name): DeviceCredentials {
            $id = Support::uuid(); $token = bin2hex(random_bytes(32));
            $this->q("INSERT INTO {$this->t('devices')} (id,name,token_hash,active,created_at) VALUES (?,?,?,1,?)", [$id, $name, hash('sha256', $token), $this->now()]);
            return new DeviceCredentials($id, $token);
        });
    }

    /** Creates a one-time QR credential. The phone supplies its own name on first test. */
    public function createEnrollment(?int $expiresAt = null): EnrollmentCredentials
    {
        return $this->store->transaction(function () use ($expiresAt): EnrollmentCredentials {
            $expiry=$expiresAt ?? $this->now()+900000;
            if ($expiry <= $this->now()) throw new GatewayException('invalid_expiry');
            $id=Support::uuid(); $token=bin2hex(random_bytes(32));
            $this->q("INSERT INTO {$this->t('enrollments')} (id,token_hash,expires_at,created_at) VALUES (?,?,?,?)",[$id,hash('sha256',$token),$expiry,$this->now()]);
            return new EnrollmentCredentials($id,$token);
        });
    }

    public function devices(int $limit = 100, int $offset = 0): array
    {
        $this->page($limit, $offset);
        return $this->q("SELECT id,name,active,created_at FROM {$this->t('devices')} ORDER BY created_at,id LIMIT $limit OFFSET $offset")->fetchAll();
    }

    public function installations(string $deviceId, int $limit = 100, int $offset = 0): array
    {
        $this->page($limit, $offset);
        return $this->q("SELECT installation_id,status,first_seen,reviewed_at FROM {$this->t('installations')} WHERE device_id=? ORDER BY first_seen,installation_id LIMIT $limit OFFSET $offset", [$deviceId])->fetchAll();
    }

    public function approveDevice(string $deviceId, string $installationId): void { $this->review($deviceId, $installationId, 'approved'); }
    public function blockDevice(string $deviceId, string $installationId): void { $this->review($deviceId, $installationId, 'blocked'); }

    private function review(string $deviceId, string $installationId, string $status): void
    {
        $installationId = Support::installation($installationId);
        $this->store->transaction(function () use ($deviceId, $installationId, $status): void {
            $this->device($deviceId, $status === 'approved');
            if (!$this->q("SELECT installation_id FROM {$this->t('installations')} WHERE device_id=? AND installation_id=?", [$deviceId, $installationId])->fetchColumn()) throw new GatewayException('installation_not_found', 404);
            if ($status === 'approved') $this->q("UPDATE {$this->t('installations')} SET status='blocked',reviewed_at=? WHERE device_id=? AND status='approved' AND installation_id<>?", [$this->now(), $deviceId, $installationId]);
            $this->q("UPDATE {$this->t('installations')} SET status=?,reviewed_at=? WHERE device_id=? AND installation_id=?", [$status, $this->now(), $deviceId, $installationId]);
            $this->emit($deviceId, 'device.' . $status, ['installationId' => $installationId]);
        });
    }

    public function setDeviceEnabled(string $deviceId, bool $enabled): void
    {
        $this->store->transaction(function () use ($deviceId, $enabled): void {
            $this->device($deviceId, false);
            $this->q("UPDATE {$this->t('devices')} SET active=? WHERE id=?", [(int)$enabled, $deviceId]);
            $this->emit($deviceId, $enabled ? 'device.enabled' : 'device.disabled', []);
        });
    }

    public function rotateToken(string $deviceId): DeviceCredentials
    {
        return $this->store->transaction(function () use ($deviceId): DeviceCredentials {
            $this->device($deviceId, false); $token = bin2hex(random_bytes(32));
            $this->q("UPDATE {$this->t('devices')} SET token_hash=? WHERE id=?", [hash('sha256', $token), $deviceId]);
            $this->q("UPDATE {$this->t('installations')} SET status='blocked',reviewed_at=? WHERE device_id=?", [$this->now(), $deviceId]);
            $this->emit($deviceId, 'device.token_rotated', []);
            return new DeviceCredentials($deviceId, $token);
        });
    }

    /** Each recipient is an E.164 string or ['number'=>..., 'values'=>[...]]. */
    public function queue(string $deviceId, array $recipients, string $body, array $values = [], ?string $idempotencyKey = null, ?int $expiresAt = null): QueueResult
    {
        $jobs = Template::prepare($recipients, $body, $values);
        $key = Support::key($idempotencyKey ?? Support::uuid());
        $hash = hash('sha256', Support::json([$jobs, $expiresAt]));
        return $this->store->transaction(function () use ($deviceId, $jobs, $key, $hash, $expiresAt): QueueResult {
            $this->device($deviceId);
            $old = $this->cached('batches', $deviceId, $key, $hash);
            if ($old !== null) return new QueueResult($old['jobIds'], $key);
            $expiry = $expiresAt ?? $this->now() + 86400000;
            if ($expiry <= $this->now()) throw new GatewayException('invalid_expiry');
            $ids = [];
            foreach ($jobs as $job) {
                $id = Support::uuid(); $ids[] = $id;
                $this->q("INSERT INTO {$this->t('jobs')} (id,device_id,recipient,body,expires_at,state,created_at,updated_at) VALUES (?,?,?,?,?,'QUEUED',?,?)", [$id, $deviceId, $job['recipient'], $job['body'], $expiry, $this->now(), $this->now()]);
            }
            $this->cache('batches', $deviceId, $key, $hash, ['jobIds' => $ids]);
            return new QueueResult($ids, $key);
        });
    }

    /** Queue the same rendered batch for selected gateways; every returned job retains its gateway ID. */
    public function queueToDevices(array $deviceIds, array $recipients, string $body, array $values = [], ?string $idempotencyKey = null, ?int $expiresAt = null): array
    {
        $deviceIds=array_values(array_unique($deviceIds));
        if ($deviceIds===[] || count($deviceIds)>100 || array_filter($deviceIds,static fn(mixed $id): bool => !is_string($id))) throw new GatewayException('invalid_device_targets');
        $jobs=Template::prepare($recipients,$body,$values); $root=Support::key($idempotencyKey ?? Support::uuid());
        $hash=hash('sha256',Support::json([$jobs,$expiresAt]));
        return $this->store->transaction(function () use ($deviceIds,$jobs,$root,$hash,$expiresAt): array {
            $expiry=$expiresAt ?? $this->now()+86400000;
            if ($expiry<=$this->now()) throw new GatewayException('invalid_expiry');
            $result=[];
            foreach ($deviceIds as $deviceId) {
                $this->device($deviceId);
                $key=hash('sha256',$root.'|'.$deviceId);
                $old=$this->cached('batches',$deviceId,$key,$hash);
                if ($old!==null) { $result[$deviceId]=new QueueResult($old['jobIds'],$key); continue; }
                $ids=[];
                foreach ($jobs as $job) {
                    $id=Support::uuid(); $ids[]=$id;
                    $this->q("INSERT INTO {$this->t('jobs')} (id,device_id,recipient,body,expires_at,state,created_at,updated_at) VALUES (?,?,?,?,?,'QUEUED',?,?)",[$id,$deviceId,$job['recipient'],$job['body'],$expiry,$this->now(),$this->now()]);
                }
                $this->cache('batches',$deviceId,$key,$hash,['jobIds'=>$ids]);
                $result[$deviceId]=new QueueResult($ids,$key);
            }
            return $result;
        });
    }

    /** Explicit broadcast helper: only active gateways with an approved installation receive work. */
    public function queueToAllApproved(array $recipients, string $body, array $values = [], ?string $idempotencyKey = null, ?int $expiresAt = null): array
    {
        $ids=array_column($this->q("SELECT DISTINCT d.id FROM {$this->t('devices')} d JOIN {$this->t('installations')} i ON i.device_id=d.id WHERE d.active=1 AND i.status='approved' ORDER BY d.id")->fetchAll(),'id');
        if ($ids===[]) throw new GatewayException('no_approved_gateways',409);
        return $this->queueToDevices($ids,$recipients,$body,$values,$idempotencyKey,$expiresAt);
    }

    public function job(string $id): ?array
    {
        $row = $this->rawJob($id);
        if (!$row) return null;
        $annotation = $this->annotation($row['device_id'], 'job', $id);
        return $annotation['deleted'] ? null : $row + ['metadata' => $annotation['metadata']];
    }

    public function jobs(string $deviceId, int $limit = 100, int $offset = 0): array
    {
        $this->page($limit, $offset);
        $rows = $this->q("SELECT j.* FROM {$this->t('jobs')} j WHERE j.device_id=? AND NOT EXISTS (SELECT 1 FROM {$this->t('annotations')} a WHERE a.device_id=j.device_id AND a.kind='job' AND a.id=j.id AND a.deleted=1) ORDER BY j.created_at,j.id LIMIT $limit OFFSET $offset", [$deviceId])->fetchAll();
        return array_map(fn(array $row): array => $row + ['metadata' => $this->annotation($deviceId, 'job', $row['id'])['metadata']], $rows);
    }

    /** Consolidated server-side send queue. Each row includes gateway.id, gateway.name, and gateway.enabled. */
    public function sharedJobs(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $this->page($limit,$offset); $allowed=['deviceId','state'];
        if (array_diff(array_keys($filter),$allowed)) throw new GatewayException('unknown_filter');
        $where=["NOT EXISTS (SELECT 1 FROM {$this->t('annotations')} a WHERE a.device_id=j.device_id AND a.kind='job' AND a.id=j.id AND a.deleted=1)"]; $args=[];
        if (isset($filter['deviceId'])) { $where[]='j.device_id=?'; $args[]=Support::text($filter['deviceId'],'deviceId',64); }
        if (isset($filter['state'])) { $where[]='j.state=?'; $args[]=Support::text($filter['state'],'state',20); }
        $rows=$this->q("SELECT j.*,d.name gateway_name,d.active gateway_active FROM {$this->t('jobs')} j JOIN {$this->t('devices')} d ON d.id=j.device_id WHERE ".implode(' AND ',$where)." ORDER BY j.created_at,j.id LIMIT $limit OFFSET $offset",$args)->fetchAll();
        return array_map(fn(array $row): array => $row+['gateway'=>['id'=>$row['device_id'],'name'=>$row['gateway_name'],'enabled'=>(bool)$row['gateway_active']],'metadata'=>$this->annotation($row['device_id'],'job',$row['id'])['metadata']],$rows);
    }

    /** Returns false for claimed/submitted jobs: never claim to recall a modem send. */
    public function cancelJob(string $id): bool
    {
        return $this->store->transaction(function () use ($id): bool {
            $job = $this->job($id);
            if ($job === null) throw new GatewayException('job_not_found', 404);
            if ($job['state'] !== 'QUEUED') return false;
            $this->q("UPDATE {$this->t('jobs')} SET state='CANCELLED',updated_at=? WHERE id=?", [$this->now(), $id]);
            $this->emit($job['device_id'], 'job.cancelled', ['jobId' => $id]);
            return true;
        });
    }

    public function incoming(string $deviceId, int $limit = 100, int $offset = 0): array { return $this->receipts($deviceId, 'incoming', $limit, $offset); }
    /** Friendly inbox alias for web applications. */
    public function getInbox(string $deviceId, array $filter = [], int $limit = 100, int $offset = 0): array
    {
        return $this->filteredReceipts($deviceId, 'incoming', $filter, $limit, $offset);
    }
    public function statusEvents(string $deviceId, int $limit = 100, int $offset = 0): array { return $this->receipts($deviceId, 'status', $limit, $offset); }
    /** Consolidated inbox across every gateway; rows retain a gateway object. */
    public function sharedInbox(array $filter = [], int $limit = 100, int $offset = 0): array { return $this->sharedReceipts('incoming',$filter,$limit,$offset); }
    /** Consolidated delivery/status records across every gateway; rows retain a gateway object. */
    public function sharedStatusEvents(array $filter = [], int $limit = 100, int $offset = 0): array { return $this->sharedReceipts('status',$filter,$limit,$offset); }

    private function receipts(string $deviceId, string $kind, int $limit, int $offset): array
    {
        return $this->filteredReceipts($deviceId, $kind, [], $limit, $offset);
    }

    private function filteredReceipts(string $deviceId, string $kind, array $filter, int $limit, int $offset): array
    {
        $this->page($limit, $offset);
        $allowed = ['id', 'sender', 'from', 'to'];
        if (array_diff(array_keys($filter), $allowed)) throw new GatewayException('unknown_filter');
        $where = ['r.device_id=?', 'r.kind=?']; $args = [$deviceId, $kind];
        if (isset($filter['id'])) { $where[] = 'r.id=?'; $args[] = Support::text($filter['id'], 'id', 64); }
        if (isset($filter['sender'])) {
            $where[] = $this->store->driver() === 'mysql'
                ? "JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.sender'))=?"
                : "json_extract(r.payload, '$.sender')=?";
            $args[] = Support::text($filter['sender'], 'sender', 512, true);
        }
        $timestamp = $this->store->driver() === 'mysql'
            ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.timestamp')) AS SIGNED)"
            : "CAST(json_extract(r.payload, '$.timestamp') AS INTEGER)";
        if (isset($filter['from'])) { $where[] = "$timestamp>=?"; $args[] = Support::integer($filter['from'], 'from'); }
        if (isset($filter['to'])) { $where[] = "$timestamp<=?"; $args[] = Support::integer($filter['to'], 'to'); }
        $where[] = "NOT EXISTS (SELECT 1 FROM {$this->t('annotations')} a WHERE a.device_id=r.device_id AND a.kind=r.kind AND a.id=r.id AND a.deleted=1)";
        return array_map(fn(array $row): array => ['id' => $row['id'], 'receivedAt' => (int)$row['created_at'], 'data' => json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR), 'metadata' => $this->annotation($deviceId, $kind, $row['id'])['metadata']],
            $this->q("SELECT r.id,r.payload,r.created_at FROM {$this->t('receipts')} r WHERE " . implode(' AND ', $where) . " ORDER BY r.created_at,r.id LIMIT $limit OFFSET $offset", $args)->fetchAll());
    }

    private function sharedReceipts(string $kind, array $filter, int $limit, int $offset): array
    {
        $this->page($limit,$offset); $allowed=['deviceId','id','sender','from','to'];
        if (array_diff(array_keys($filter),$allowed)) throw new GatewayException('unknown_filter');
        $where=['r.kind=?']; $args=[$kind];
        if (isset($filter['deviceId'])) { $where[]='r.device_id=?'; $args[]=Support::text($filter['deviceId'],'deviceId',64); }
        if (isset($filter['id'])) { $where[]='r.id=?'; $args[]=Support::text($filter['id'],'id',64); }
        if (isset($filter['sender'])) { $where[]=$this->store->driver()==='mysql'?"JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.sender'))=?":"json_extract(r.payload, '$.sender')=?"; $args[]=Support::text($filter['sender'],'sender',512,true); }
        $timestamp=$this->store->driver()==='mysql'?"CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.timestamp')) AS SIGNED)":"CAST(json_extract(r.payload, '$.timestamp') AS INTEGER)";
        if (isset($filter['from'])) { $where[]="$timestamp>=?"; $args[]=Support::integer($filter['from'],'from'); }
        if (isset($filter['to'])) { $where[]="$timestamp<=?"; $args[]=Support::integer($filter['to'],'to'); }
        $where[]="NOT EXISTS (SELECT 1 FROM {$this->t('annotations')} a WHERE a.device_id=r.device_id AND a.kind=r.kind AND a.id=r.id AND a.deleted=1)";
        $rows=$this->q("SELECT r.id,r.device_id,r.payload,r.created_at,d.name gateway_name,d.active gateway_active FROM {$this->t('receipts')} r JOIN {$this->t('devices')} d ON d.id=r.device_id WHERE ".implode(' AND ',$where)." ORDER BY r.created_at,r.id LIMIT $limit OFFSET $offset",$args)->fetchAll();
        return array_map(fn(array $row): array => ['id'=>$row['id'],'receivedAt'=>(int)$row['created_at'],'data'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'gateway'=>['id'=>$row['device_id'],'name'=>$row['gateway_name'],'enabled'=>(bool)$row['gateway_active']],'metadata'=>$this->annotation($row['device_id'],$kind,$row['id'])['metadata']],$rows);
    }

    /** Durable integration events. Process idempotently, then acknowledge explicitly. */
    public function pendingEvents(int $limit = 100): array
    {
        $this->page($limit, 0);
        return array_map(static fn(array $row): array => ['id' => $row['id'], 'deviceId' => $row['device_id'], 'type' => $row['type'], 'createdAt' => (int)$row['created_at'], 'data' => json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR)],
            $this->q("SELECT * FROM {$this->t('outbox')} WHERE acknowledged=0 ORDER BY created_at,id LIMIT $limit")->fetchAll());
    }

    public function acknowledgeEvent(string $id): void
    {
        $this->store->transaction(function () use ($id): void {
            $this->q("UPDATE {$this->t('outbox')} SET acknowledged=1,payload='{}' WHERE id=?", [$id]);
        });
    }

    /** Android protocol entry point; administrative methods are never routed here. */
    public function handleDeviceRequest(string $path, #[\SensitiveParameter] string $token, #[\SensitiveParameter] array $body, string $key): array
    {
        if (!in_array($path, ['/v1/jobs/claim','/v1/incoming','/v1/events','/v1/test'], true)) throw new GatewayException('not_found', 404);
        if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $token)) throw new GatewayException('unauthorized', 401);
        $installation = Support::installation($body['deviceId'] ?? null);
        // Commit pending registration before returning 403, so admins can review it.
        $device = $this->store->transaction(function () use ($token, $installation, $path, $body): array {
            $device = $this->q("SELECT * FROM {$this->t('devices')} WHERE token_hash=? AND active=1", [hash('sha256', $token)])->fetch();
            if (!$device) {
                if ($path !== '/v1/test') throw new GatewayException('unauthorized', 401);
                $enrollment=$this->q("SELECT id FROM {$this->t('enrollments')} WHERE token_hash=? AND expires_at>?",[hash('sha256',$token),$this->now()])->fetch();
                if (!$enrollment) throw new GatewayException('unauthorized', 401);
                $name=trim(Support::text($body['gatewayName'] ?? null,'gatewayName',120));
                if ($name==='') throw new GatewayException('invalid_gatewayName');
                $device=['id'=>Support::uuid(),'name'=>$name,'token_hash'=>hash('sha256',$token),'active'=>1,'created_at'=>$this->now()];
                $this->q("INSERT INTO {$this->t('devices')} (id,name,token_hash,active,created_at) VALUES (?,?,?,1,?)",[$device['id'],$device['name'],$device['token_hash'],$device['created_at']]);
                $this->q("DELETE FROM {$this->t('enrollments')} WHERE id=?",[$enrollment['id']]);
                $this->emit($device['id'],'device.enrolled',['name'=>$name]);
            }
            $status = $this->q("SELECT status FROM {$this->t('installations')} WHERE device_id=? AND installation_id=?", [$device['id'], $installation])->fetchColumn();
            if ($status === false) {
                $this->q("INSERT INTO {$this->t('installations')} (device_id,installation_id,status,first_seen) VALUES (?,?,'pending',?)", [$device['id'], $installation, $this->now()]);
                $this->emit($device['id'], 'device.pending', ['installationId' => $installation]);
            }
            return $device;
        });
        return $this->store->transaction(function () use ($device, $token, $installation, $path, $body, $key): array {
            $current = $this->device($device['id']);
            if (!hash_equals($current['token_hash'], hash('sha256', $token))) throw new GatewayException('unauthorized', 401);
            $status = $this->q("SELECT status FROM {$this->t('installations')} WHERE device_id=? AND installation_id=?", [$device['id'], $installation])->fetchColumn();
            if ($path === '/v1/test') return ['ok' => true, 'approved' => $status === 'approved', 'status' => $status, 'deviceId' => $device['id'], 'installationId' => $installation, 'serverTime' => $this->now()];
            if ($status !== 'approved') throw new GatewayException($status === 'blocked' ? 'device_blocked' : 'device_pending_approval', 403);
            Support::key($key);
            return match ($path) {
                '/v1/jobs/claim' => $this->claim($device['id'], $body, $key),
                '/v1/incoming' => $this->receive($device['id'], 'incoming', $body, $key),
                '/v1/events' => $this->receive($device['id'], 'status', $body, $key),
            };
        });
    }

    private function claim(string $deviceId, array $body, string $key): array
    {
        $limit = $body['limit'] ?? 10;
        if (!is_int($limit) || $limit < 1 || $limit > 10) throw new GatewayException('invalid_limit');
        $hash = hash('sha256', Support::json([$limit]));
        if (($old = $this->cached('claims', $deviceId, $key, $hash)) !== null) return $old;
        $this->q("UPDATE {$this->t('jobs')} SET state='EXPIRED',updated_at=? WHERE device_id=? AND state='QUEUED' AND expires_at<=?", [$this->now(), $deviceId, $this->now()]);
        $rows = $this->q("SELECT id,recipient,body,expires_at FROM {$this->t('jobs')} WHERE device_id=? AND state='QUEUED' ORDER BY created_at,id LIMIT $limit", [$deviceId])->fetchAll();
        $jobs = [];
        foreach ($rows as $row) {
            $this->q("UPDATE {$this->t('jobs')} SET state='CLAIMED',updated_at=? WHERE id=?", [$this->now(), $row['id']]);
            $jobs[] = ['id' => $row['id'], 'recipient' => $row['recipient'], 'body' => $row['body'], 'expiresAt' => (int)$row['expires_at']];
        }
        $response = ['jobs' => $jobs];
        $this->cache('claims', $deviceId, $key, $hash, $response);
        return $response;
    }

    private function receive(string $deviceId, string $kind, array $body, string $key): array
    {
        $id = Support::text($body['id'] ?? null, 'id', 64);
        if (!hash_equals($id, $key)) throw new GatewayException('idempotency_mismatch');
        $timestamp = Support::integer($body['timestamp'] ?? null, 'timestamp');
        if ($kind === 'incoming') {
            $payload = ['sender' => Support::text($body['sender'] ?? null, 'sender', 512, true), 'body' => Support::text($body['body'] ?? null, 'body', 20000, true),
                'timestamp' => $timestamp, 'subscriptionId' => Support::integer($body['subscriptionId'] ?? null, 'subscriptionId')];
        } else {
            $payload = ['jobId' => Support::text($body['jobId'] ?? null, 'jobId', 36), 'state' => Support::text($body['state'] ?? null, 'state', 20), 'timestamp' => $timestamp];
            if (!in_array($payload['state'], ['STORED','SENDING','SENT','DELIVERED','FAILED','UNKNOWN','EXPIRED','CANCELLED'], true)) throw new GatewayException('invalid_state');
            $job = $this->rawJob($payload['jobId']);
            if (!$job || $job['device_id'] !== $deviceId) throw new GatewayException('job_not_found', 404);
        }
        $json = Support::json($payload); $hash = hash('sha256', $json);
        $old = $this->q("SELECT payload_hash FROM {$this->t('receipts')} WHERE device_id=? AND kind=? AND id=?", [$deviceId, $kind, $id])->fetchColumn();
        if ($old !== false) {
            if (!hash_equals($old, $hash)) throw new GatewayException('idempotency_conflict', 409);
            return ['ackId' => $id];
        }
        $this->q("INSERT INTO {$this->t('receipts')} (device_id,kind,id,payload_hash,payload,created_at) VALUES (?,?,?,?,?,?)", [$deviceId, $kind, $id, $hash, $json, $this->now()]);
        if ($kind === 'status') {
            $transitions = [
                'CLAIMED' => ['STORED','SENDING','SENT','DELIVERED','FAILED','UNKNOWN','EXPIRED','CANCELLED'],
                'STORED' => ['SENDING','SENT','DELIVERED','FAILED','UNKNOWN','EXPIRED','CANCELLED'],
                'SENDING' => ['SENT','DELIVERED','FAILED','UNKNOWN','CANCELLED'],
                'UNKNOWN' => ['SENT','DELIVERED','FAILED'], 'SENT' => ['DELIVERED'],
            ];
            if (in_array($payload['state'], $transitions[$job['state']] ?? [], true)) $this->q("UPDATE {$this->t('jobs')} SET state=?,updated_at=? WHERE id=?", [$payload['state'], $this->now(), $job['id']]);
        }
        $this->emit($deviceId, $kind === 'incoming' ? 'sms.received' : 'sms.status', ['id' => $id] + $payload);
        return ['ackId' => $id];
    }

    private function device(string $id, bool $requireActive = true): array
    {
        $device = $this->q("SELECT * FROM {$this->t('devices')} WHERE id=?", [$id])->fetch();
        if (!$device) throw new GatewayException('device_not_found', 404);
        if ($requireActive && !(int)$device['active']) throw new GatewayException('device_disabled', 403);
        return $device;
    }

    private function cached(string $table, string $deviceId, string $key, string $hash): ?array
    {
        $old = $this->q("SELECT payload_hash,response FROM {$this->t($table)} WHERE device_id=? AND request_key=?", [$deviceId, $key])->fetch();
        if (!$old) return null;
        if (!hash_equals($old['payload_hash'], $hash)) throw new GatewayException('idempotency_conflict', 409);
        return json_decode($old['response'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function cache(string $table, string $deviceId, string $key, string $hash, array $response): void
    {
        $this->q("INSERT INTO {$this->t($table)} (device_id,request_key,payload_hash,response) VALUES (?,?,?,?)", [$deviceId, $key, $hash, Support::json($response)]);
    }

    private function emit(string $deviceId, string $type, array $data): void
    {
        $this->q("INSERT INTO {$this->t('outbox')} (id,device_id,type,payload,created_at,acknowledged) VALUES (?,?,?,?,?,0)", [Support::uuid(), $deviceId, $type, Support::json($data), $this->now()]);
    }

    private function page(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 1000 || $offset < 0) throw new \InvalidArgumentException('Use limit 1–1000 and a non-negative offset');
    }
}
