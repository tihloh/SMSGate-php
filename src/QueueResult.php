<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

final class QueueResult
{
    /** @param list<string> $jobIds */
    public function __construct(public readonly array $jobIds, public readonly string $idempotencyKey) {}
}
