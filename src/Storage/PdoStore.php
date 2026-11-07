<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Storage;

use PDO;
use PDOStatement;

/** Owns its transactions: give it a dedicated PDO connection. */
final class PdoStore
{
    private readonly string $driver;
    private bool $running = false;
    private bool $ready = false;

    public function __construct(private readonly PDO $pdo, private readonly string $prefix = 'smsgate_')
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $prefix)) throw new \InvalidArgumentException('Invalid table prefix');
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['sqlite', 'mysql'], true)) throw new \InvalidArgumentException('Use PDO SQLite or MySQL');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver === 'sqlite') $pdo->exec('PRAGMA busy_timeout = 5000');
        else $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function table(string $name): string
    {
        if (!in_array($name, ['meta','devices','installations','enrollments','jobs','claims','batches','receipts','outbox','annotations'], true)) throw new \InvalidArgumentException('Invalid table');
        return $this->prefix . $name;
    }

    /** @internal Used to keep portable queries inside the package. */
    public function driver(): string { return $this->driver; }

    public function query(string $sql, array $values = []): PDOStatement
    {
        $query = $this->pdo->prepare($sql); $query->execute($values); return $query;
    }

    /** Check required tables once per instance; create missing tables automatically. */
    public function ensureInstalled(): void
    {
        if ($this->ready) return;
        if ($this->pdo->inTransaction() || $this->running) throw new \LogicException('SMSGate needs its own connection outside host transactions');
        $tables = array_map($this->table(...), ['meta','devices','installations','enrollments','jobs','claims','batches','receipts','outbox','annotations']);
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $sql = $this->driver === 'sqlite'
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ($placeholders)"
            : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($placeholders)";
        if ((int)$this->query($sql, $tables)->fetchColumn() !== count($tables)) $this->install();
        if ((int)$this->query("SELECT version FROM {$this->table('meta')} WHERE id=1")->fetchColumn() !== 1) throw new \RuntimeException('Unsupported SMSGate schema version');
        $this->ready = true;
    }

    /** Explicit installation remains available, but ordinary use initializes automatically. */
    public function install(): void
    {
        if ($this->pdo->inTransaction() || $this->running) throw new \LogicException('Install outside a transaction');
        $text = $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $schemas = [
            'meta' => 'id INTEGER PRIMARY KEY, version INTEGER NOT NULL',
            'devices' => 'id VARCHAR(36) PRIMARY KEY, name VARCHAR(120) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, active INTEGER NOT NULL, created_at BIGINT NOT NULL',
            'installations' => 'device_id VARCHAR(36) NOT NULL, installation_id VARCHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, first_seen BIGINT NOT NULL, reviewed_at BIGINT NULL, PRIMARY KEY(device_id,installation_id)',
            'enrollments' => 'id VARCHAR(36) PRIMARY KEY, token_hash VARCHAR(64) NOT NULL UNIQUE, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL',
            'jobs' => "id VARCHAR(36) PRIMARY KEY, device_id VARCHAR(36) NOT NULL, recipient VARCHAR(16) NOT NULL, body $text NOT NULL, expires_at BIGINT NOT NULL, state VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL",
            'claims' => "device_id VARCHAR(36) NOT NULL, request_key VARCHAR(128) NOT NULL, payload_hash VARCHAR(64) NOT NULL, response $text NOT NULL, PRIMARY KEY(device_id,request_key)",
            'batches' => "device_id VARCHAR(36) NOT NULL, request_key VARCHAR(128) NOT NULL, payload_hash VARCHAR(64) NOT NULL, response $text NOT NULL, PRIMARY KEY(device_id,request_key)",
            'receipts' => "device_id VARCHAR(36) NOT NULL, kind VARCHAR(20) NOT NULL, id VARCHAR(64) NOT NULL, payload_hash VARCHAR(64) NOT NULL, payload $text NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(device_id,kind,id)",
            'outbox' => "id VARCHAR(36) PRIMARY KEY, device_id VARCHAR(36) NOT NULL, type VARCHAR(40) NOT NULL, payload $text NOT NULL, created_at BIGINT NOT NULL, acknowledged INTEGER NOT NULL DEFAULT 0",
            'annotations' => "device_id VARCHAR(36) NOT NULL, kind VARCHAR(20) NOT NULL, id VARCHAR(64) NOT NULL, metadata $text NOT NULL, deleted INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(device_id,kind,id)",
        ];
        foreach ($schemas as $table => $sql) $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table($table) . " ($sql)$suffix");
        // The schema marker also coordinates short write transactions across processes.
        $insert = $this->driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->pdo->exec("$insert INTO {$this->table('meta')} (id,version) VALUES (1,1)");
        if ((int)$this->query("SELECT version FROM {$this->table('meta')} WHERE id=1")->fetchColumn() !== 1) throw new \RuntimeException('Unsupported SMSGate schema version');
        foreach (['jobs' => 'device_id,state,created_at', 'outbox' => 'acknowledged,created_at', 'receipts' => 'device_id,kind,created_at'] as $table => $columns) {
            $index = $this->table($table) . '_lookup';
            if ($this->driver === 'sqlite') $this->pdo->exec("CREATE INDEX IF NOT EXISTS $index ON {$this->table($table)} ($columns)");
            else {
                $exists = $this->query('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?', [$this->table($table), $index])->fetchColumn();
                if (!(int)$exists) {
                    try { $this->pdo->exec("CREATE INDEX $index ON {$this->table($table)} ($columns)"); }
                    catch (\PDOException $error) { if (($error->errorInfo[1] ?? null) !== 1061) throw $error; } // Concurrent first request.
                }
            }
        }
    }

    /** @template T @param callable():T $work @return T */
    public function transaction(callable $work): mixed
    {
        if ($this->running || $this->pdo->inTransaction()) throw new \LogicException('SMSGate requires its own transaction');
        $this->ensureInstalled();
        $this->running = true; $started = false;
        try {
            if ($this->driver === 'sqlite') $this->pdo->exec('BEGIN IMMEDIATE');
            else $this->pdo->beginTransaction();
            $started = true;
            $version = $this->query("SELECT version FROM {$this->table('meta')} WHERE id=1" . ($this->driver === 'mysql' ? ' FOR UPDATE' : ''))->fetchColumn();
            if ((int)$version !== 1) throw new \RuntimeException('Install the SMSGate schema first');
            $result = $work();
            $this->pdo->exec('COMMIT'); $started = false;
            return $result;
        } catch (\Throwable $error) {
            if ($started) $this->pdo->exec('ROLLBACK');
            throw $error;
        } finally { $this->running = false; }
    }
}
