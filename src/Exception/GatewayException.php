<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Exception;

/** Safe error codes suitable for HTTP clients. Never include SMS or credentials. */
final class GatewayException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 422, public readonly array $details = [])
    {
        parent::__construct($reason);
    }
}
