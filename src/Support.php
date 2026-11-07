<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

use Tihloh\SmsGate\Exception\GatewayException;

/** @internal */
final class Support
{
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function installation(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) {
            throw new GatewayException('invalid_deviceId');
        }
        return strtolower($id);
    }

    public static function text(mixed $value, string $field, int $max, bool $empty = false): string
    {
        if (!is_string($value) || (!$empty && $value === '') || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new GatewayException('invalid_' . $field);
        }
        return $value;
    }

    public static function integer(mixed $value, string $field): int
    {
        if (!is_int($value)) throw new GatewayException('invalid_' . $field);
        return $value;
    }

    public static function key(string $key): string
    {
        if (!preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $key)) throw new GatewayException('invalid_idempotency_key', 400);
        return $key;
    }

    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
