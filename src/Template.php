<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

use Tihloh\SmsGate\Exception\GatewayException;

final class Template
{
    /** @return list<array{recipient:string,body:string}> */
    public static function prepare(array $recipients, string $body, array $values = []): array
    {
        Support::text($body, 'body', 4800);
        if (!array_is_list($recipients) || count($recipients) < 1 || count($recipients) > 100) throw new GatewayException('invalid_recipients');
        $global = self::values($values); $jobs = []; $seen = [];
        $without = preg_replace('/\{\{[^{}]*\}\}/u', '', $body);
        if ($without === null || str_contains($without, '{{') || str_contains($without, '}}')) throw new GatewayException('invalid_template');
        foreach ($recipients as $index => $recipient) {
            if (is_string($recipient)) { $number = $recipient; $local = []; }
            elseif (is_array($recipient)) {
                $number = $recipient['number'] ?? null;
                $local = self::values(array_key_exists('values', $recipient) ? $recipient['values'] : []);
            } else throw new GatewayException('invalid_recipient', 422, ['recipientIndex' => $index]);
            try { $number = self::recipient($number); }
            catch (GatewayException) { throw new GatewayException('invalid_recipient', 422, ['recipientIndex' => $index]); }
            $merged = array_replace($global, $local);
            $rendered = preg_replace_callback('/\{\{([^{}]*)\}\}/u', static function (array $m) use ($merged, $index): string {
                $key = trim($m[1]);
                if ($key === '' || !array_key_exists($key, $merged)) throw new GatewayException('missing_placeholder', 422, ['recipientIndex' => $index, 'key' => $key]);
                return $merged[$key];
            }, $body);
            Support::text($rendered, 'rendered_body', 4800);
            $identity = Support::json([$number, $rendered]);
            if (!isset($seen[$identity])) $jobs[] = ['recipient' => $number, 'body' => $rendered];
            $seen[$identity] = true;
        }
        return $jobs;
    }

    /** Accept E.164 and the Philippine local 09xxxxxxxxx format; store a single E.164 form. */
    public static function recipient(mixed $number): string
    {
        if (!is_string($number)) throw new GatewayException('invalid_recipient');
        $number=trim($number);
        if (preg_match('/^09[0-9]{9}$/D',$number)) return '+63'.substr($number,1);
        if (preg_match('/^\+[1-9][0-9]{6,14}$/D',$number)) return $number;
        throw new GatewayException('invalid_recipient');
    }

    private static function values(mixed $values): array
    {
        if (!is_array($values)) throw new GatewayException('invalid_values');
        $result = [];
        foreach ($values as $key => $value) {
            $key = (string)$key;
            if ($key === '' || trim($key) !== $key || str_contains($key, '{') || str_contains($key, '}')) throw new GatewayException('invalid_placeholder_key');
            if (!is_string($value) && !is_int($value) && !is_bool($value) && !(is_float($value) && is_finite($value))) throw new GatewayException('invalid_placeholder_value');
            $result[$key] = is_string($value) ? $value : Support::json($value);
        }
        return $result;
    }
}
