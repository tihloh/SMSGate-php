<?php
declare(strict_types=1);

namespace Tihloh\SmsGate;

/**
 * Server-observed gateway presence. Reuses the compact records table:
 * scope=presence, kind='', id=installation_id, created_at=lastSeen (ms).
 * A heartbeat is a transient HTTP request, not a persistent socket.
 *
 * @internal Used by Gateway.
 */
trait Presence
{
    // Android sends a lightweight heartbeat every 10s while visible or in a
    // foreground service. The dashboard refreshes every 5s.
    private const PRESENCE_CONNECTED_WINDOW_MS = 30000; // 3 missed heartbeats
    private const PRESENCE_ONLINE_WINDOW_MS = 45000; // last authenticated contact
    
    /** Called only after token authentication. Never trusts client timestamps. */
    private function recordPresence(string $deviceId, string $installationId, ?bool $running = null, ?int $pollSeconds = null): void
    {
        $table = $this->t('records');
        $existing = $this->q(
            "SELECT metadata,created_at FROM $table WHERE scope='presence' AND device_id=? AND kind='' AND id=?",
            [$deviceId, $installationId]
        )->fetch();
        $now = $this->now();
        if ($existing) {
            $metadata = json_decode((string)($existing['metadata'] ?? '{}'), true);
            if (!is_array($metadata)) $metadata = [];
            // Ordinary requests refresh lastSeen but do not impersonate a heartbeat.
            if ($running !== null) {
                $metadata = [
                    'running' => $running,
                    'pollSeconds' => $pollSeconds ?? 30,
                    'heartbeatAt' => $now,
                ];
            }
            $this->q(
                "UPDATE $table SET created_at=?,metadata=? WHERE scope='presence' AND device_id=? AND kind='' AND id=?",
                [$now, Support::json($metadata), $deviceId, $installationId]
            );
        } else {
            $metadata = $running === null ? [] : [
                'running' => $running,
                'pollSeconds' => $pollSeconds ?? 30,
                'heartbeatAt' => $now,
            ];
            $this->q(
                "INSERT INTO $table (scope,device_id,kind,id,metadata,created_at) VALUES ('presence',?,'',?,?,?)",
                [$deviceId, $installationId, Support::json($metadata), $now]
            );
        }
    }

    /** Same query for listDevices() and getDevice(), without leaking credentials. */
    private function deviceOverviewSql(): string
    {
        $devices = $this->t('devices');
        $installations = $this->t('installations');
        $records = $this->t('records');
        return "SELECT d.id,d.name,d.active,d.created_at, i.installation_id AS approved_installation_id," .
            " p.created_at AS last_seen_at,p.metadata AS presence_metadata" .
            " FROM $devices d" .
            " LEFT JOIN $installations i ON i.device_id=d.id AND i.status='approved'" .
            " LEFT JOIN $records p ON p.scope='presence' AND p.device_id=d.id AND p.kind='' AND p.id=i.installation_id";
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function describeDevice(array $row): array
    {
        $enabled = (int)$row['active'] === 1;
        $hasApproval = $row['approved_installation_id'] !== null;
        $presence = $this->describePresence(
            $row['last_seen_at'],
            $row['presence_metadata'],
            $enabled && $hasApproval
        );
        unset($row['presence_metadata']);
        $row['last_seen_at'] = $presence['lastSeenAt'];
        $row['lastSeenAt'] = $presence['lastSeenAt'];
        $row['lastHeartbeatAt'] = $presence['lastHeartbeatAt'];
        $row['reportedRunning'] = $presence['reportedRunning'];
        $row['pollSeconds'] = $presence['pollSeconds'];
        $row['enabled'] = $enabled;
        $row['online'] = $presence['online'];
        $row['connected'] = $presence['connected']; // heartbeat-based, not a socket.
        $row['presenceStatus'] = !$enabled ? 'disabled' : (!$hasApproval ? 'unpaired' :
            ($presence['connected'] ? 'connected' : ($presence['online'] ? 'online' : 'offline')));
        return $row;
    }

    /** @return array{lastSeenAt:?int,lastHeartbeatAt:?int,reportedRunning:?bool,pollSeconds:?int,online:bool,connected:bool} */
    private function describePresence(mixed $seen, mixed $raw, bool $approved): array
    {
        $lastSeen = $seen === null ? null : (int)$seen;
        $meta = json_decode((string)($raw ?? '{}'), true);
        if (!is_array($meta)) $meta = [];
        $reportedRunning = isset($meta['running']) && is_bool($meta['running'])
            ? $meta['running'] : null;
        $heartbeatAt = isset($meta['heartbeatAt']) && is_numeric($meta['heartbeatAt'])
            ? (int)$meta['heartbeatAt'] : null;
        $poll = isset($meta['pollSeconds']) && is_int($meta['pollSeconds'])
            ? max(15, min(3600, $meta['pollSeconds'])) : null;

        $now = $this->now();
        $online = $approved && $lastSeen !== null && $lastSeen <= $now &&
            $now - $lastSeen <= self::PRESENCE_ONLINE_WINDOW_MS;
        $connected = $online && $reportedRunning === true && $heartbeatAt !== null &&
            $heartbeatAt <= $now && ($now - $heartbeatAt) <= self::PRESENCE_CONNECTED_WINDOW_MS;
        return [
            'lastSeenAt' => $lastSeen,
            'lastHeartbeatAt' => $heartbeatAt,
            'reportedRunning' => $reportedRunning,
            'pollSeconds' => $poll,
            'online' => $online,
            'connected' => $connected,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function describeInstallation(array $row, bool $enabled): array
    {
        $status = (string)$row['status'];
        $presence = $this->describePresence(
            $row['last_seen_at'],
            $row['presence_metadata'],
            $enabled && $status === 'approved'
        );
        unset($row['presence_metadata'], $row['last_seen_at']);
        $row += $presence;
        return $row;
    }
}
