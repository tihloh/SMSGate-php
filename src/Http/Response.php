<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Http;

use Tihloh\SmsGate\Support;

/** Plain PHP result. The host may emit it or adapt it to its own response type. */
final class Response
{
    public function __construct(public readonly array $data, public readonly int $status = 200) {}
    public function json(): string { return Support::json($this->data); }
    public function headers(): array
    {
        return ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
    /** Does not exit: the host controls the rest of its request lifecycle. */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers() as $name => $value) header($name . ': ' . $value);
        echo $this->json();
    }
}
