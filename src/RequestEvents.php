<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

/** @internal Methods are exposed through Gateway. */
trait RequestEvents
{
    /** One callback per type; '*' is a fallback. Register callbacks on each request. */
    public function on(string $type, callable $callback): self
    {
        Support::text($type, 'event_type', 40);
        $this->listeners[$type] = \Closure::fromCallable($callback);
        return $this;
    }
    public function callbackErrors(): array { return $this->callbackErrors; }

    /** process() calls this after commit. Failed callbacks retry on a later request. */
    public function dispatchEvents(int $limit = 100): int
    {
        if (!$this->listeners || $this->dispatching) return 0;
        $this->page($limit, 0); $this->callbackErrors = []; $this->dispatching = true;
        try {
            $types = array_keys($this->listeners);
            $where = isset($this->listeners['*']) ? '' : ' AND kind IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $rows = $this->q("SELECT id,device_id,kind AS type,payload,created_at FROM {$this->t('records')} WHERE scope='event' AND acknowledged=0$where ORDER BY created_at,id LIMIT $limit", $where === '' ? [] : $types)->fetchAll();
            $done = 0;
            foreach ($rows as $row) {
                $event = ['id'=>$row['id'],'deviceId'=>$row['device_id'],'type'=>$row['type'],'createdAt'=>(int)$row['created_at'],'data'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)];
                try {
                    ($this->listeners[$row['type']] ?? $this->listeners['*'])($event);
                    $this->acknowledgeEvent($row['id']); $done++;
                } catch (\Throwable $error) { $this->callbackErrors[] = ['eventId'=>$row['id'],'errorType'=>get_class($error)]; }
            }
            return $done;
        } catch (\Throwable $error) { $this->callbackErrors[] = ['eventId'=>null,'errorType'=>get_class($error)]; return 0;
        } finally { $this->dispatching = false; }
    }
}
