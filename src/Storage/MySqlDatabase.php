<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Storage;

use PDO;
use PDOException;

/**
 * Open an existing MySQL database or create it when MySQL reports error 1049.
 *
 * Creation is deliberately limited to the database named in the supplied DSN.
 * A valid server-side account needs CREATE privilege only on the first use
 * when the database is absent. Never drop or replace an existing database.
 */
final class MySqlDatabase
{
    public static function connect(
        string $dsn,
        string $username,
        #[\SensitiveParameter] string $password,
        array $options = []
    ): PDO {
        [$name, $serverDsn] = self::parseDsn($dsn);
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $error) {
            // Do not create anything when credentials, network or privileges fail.
            if ((int)($error->errorInfo[1] ?? 0) !== 1049) throw $error;
        }

        $server = new PDO($serverDsn, $username, $password, $options);
        try {
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS ' . self::quoted($name) .
                ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
            );
        } catch (PDOException $error) {
            throw new \RuntimeException(
                'MySQL database "' . $name . '" is missing and could not be created. ' .
                'Grant database CREATE permission or create it once with a privileged account.',
                previous: $error
            );
        } finally {
            unset($server);
        }

        // Reconnect directly to the requested database without changing a
        // shared application's current database using USE.
        return new PDO($dsn, $username, $password, $options);
    }

    /** @return array{string,string} database name and server-only DSN */
    private static function parseDsn(string $dsn): array
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new \InvalidArgumentException('Expected a mysql: PDO DSN with dbname.');
        }
        $parts = [];
        $database = null;
        $seen = [];
        foreach (explode(';', substr($dsn, 6)) as $entry) {
            if ($entry === '') continue;
            if (!str_contains($entry, '=')) {
                throw new \InvalidArgumentException('Invalid MySQL PDO DSN field.');
            }
            [$key, $value] = explode('=', $entry, 2);
            $key = strtolower(trim($key));
            if (!in_array($key, ['host', 'port', 'unix_socket', 'charset', 'dbname'], true)
                || isset($seen[$key])) {
                throw new \InvalidArgumentException('Unsupported or repeated MySQL PDO DSN field.');
            }
            $seen[$key] = true;
            if ($key === 'dbname') {
                $database = $value;
            } else {
                $parts[] = $key . '=' . $value;
            }
        }
        if ($database === null || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $database)
            || in_array(strtolower($database), ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
            throw new \InvalidArgumentException('A safe application database name is required in dbname.');
        }
        return [$database, 'mysql:' . implode(';', $parts)];
    }

    private static function quoted(string $name): string
    {
        // The parser restricts identifiers; quoting handles reserved words
        // and hyphens in database names.
        return chr(96) . $name . chr(96);
    }
}
