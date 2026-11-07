<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

/** One-time credential used to let a phone create its own pending gateway record. */
final class EnrollmentCredentials
{
    public function __construct(public readonly string $id, #[\SensitiveParameter] private readonly string $secret) {}

    public function token(): string { return $this->secret; }
    public function setup(string $url, string $operator): SetupProfile { return new SetupProfile($url, $operator, $this->secret); }
    public function __debugInfo(): array { return ['id' => $this->id, 'token' => '[redacted]']; }
}
