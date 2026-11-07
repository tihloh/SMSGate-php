<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

/** Returned only when issuing/rotating a token. The database stores its hash. */
final class DeviceCredentials
{
    public function __construct(public readonly string $id, #[\SensitiveParameter] private readonly string $secret) {}

    public function token(): string { return $this->secret; }

    public function setup(string $url, string $operator): SetupProfile
    {
        return new SetupProfile($url, $operator, $this->secret);
    }

    public function __debugInfo(): array { return ['id' => $this->id, 'token' => '[redacted]']; }
}
