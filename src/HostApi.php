<?php
declare(strict_types=1);

namespace Tihloh\SmsGate;

/**
 * Trusted, database-independent PHP API for host applications.
 *
 * The host supplies an existing database connection, then uses this facade
 * for all SMSGate operations. No table names, SQL or PDO objects are exposed.
 *
 * Do not route these methods to unauthenticated HTTP callers. Android uses
 * Gateway::process(), which exposes a separate, restricted device protocol.
 */
final class HostApi
{
    public function __construct(private readonly Gateway $gateway) {}

    /** @return list<array<string, mixed>> */
    public function listDevices(int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->devices($limit, $offset);
    }

    public function getDevice(string $deviceId): ?array
    {
        return $this->gateway->getDevice($deviceId);
    }

    /**
     * Status monitor for registered gateways. Online/connected are recent
     * heartbeat estimates, not persistent network sockets.
     *
     * @return list<array<string,mixed>>
     */
    public function listDeviceStatuses(int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->devices($limit, $offset);
    }

    /** @return array<string,mixed>|null */
    public function getDeviceStatus(string $deviceId): ?array
    {
        return $this->gateway->getDevice($deviceId);
    }

    /** @return list<array<string, mixed>> */
    public function listInstallations(string $deviceId, int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->installations($deviceId, $limit, $offset);
    }

    /**
     * Fetch jobs from all registered gateways with optional device/state
     * filters. Each row includes its gateway details.
     *
     * @param array<string, mixed> $filters deviceId and/or state
     * @return list<array<string, mixed>>
     */
    public function listJobs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->sharedJobs($filters, $limit, $offset);
    }

    /** @return list<array<string, mixed>> */
    public function listDeviceJobs(string $deviceId, int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->jobs($deviceId, $limit, $offset);
    }

    public function getJob(string $jobId): ?array
    {
        return $this->gateway->getJob($jobId);
    }

    /**
     * Queue an SMS batch for a specific gateway.
     *
     * A stable request key prevents duplicate batches on HTTP/form retries.
     *
     * @param list<string|array<string,mixed>> $recipients
     * @param array<string,mixed> $values
     * @return array{jobIds: list<string>, idempotencyKey: string}
     */
    public function queueSms(
        string $deviceId,
        array $recipients,
        string $body,
        array $values = [],
        ?string $requestKey = null,
        ?int $expiresAt = null
    ): array {
        $queued = $this->gateway->queue($deviceId, $recipients, $body, $values, $requestKey, $expiresAt);
        return ['jobIds' => $queued->jobIds, 'idempotencyKey' => $queued->idempotencyKey];
    }

    public function cancelJob(string $jobId): bool
    {
        return $this->gateway->cancelJob($jobId);
    }

    public function updateJob(string $jobId, array $changes): array
    {
        return $this->gateway->updateJob($jobId, $changes);
    }

    public function deleteJob(string $jobId): bool
    {
        return $this->gateway->deleteJob($jobId);
    }

    /** @return list<array<string, mixed>> */
    public function listInbox(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->sharedInbox($filters, $limit, $offset);
    }

    /** @return list<array<string, mixed>> */
    public function listStatuses(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->gateway->sharedStatusEvents($filters, $limit, $offset);
    }

    public function getInboxMessage(string $deviceId, string $messageId): ?array
    {
        return $this->gateway->getInboxMessage($deviceId, $messageId);
    }

    public function updateInbox(string $deviceId, string|array $target, array $metadata): int
    {
        return $this->gateway->updateInbox($deviceId, $target, $metadata);
    }

    public function deleteInbox(string $deviceId, string|array $target): int
    {
        return $this->gateway->deleteInbox($deviceId, $target);
    }

    /**
     * Generate a fresh, one-time mobile pairing QR without exposing its token
     * in the result. Return the image data URI for display by the host UI.
     *
     * @return array{enrollmentId: string, image: string}
     */
    public function createPairing(
        string $endpointUrl,
        string $operator,
        string $format = 'svg',
        int $size = 400
    ): array {
        // Validate the profile and the image format/size before recording a
        // one-time enrollment. Invalid requests must not create DB records.
        $format = strtolower($format);
        if (!in_array($format, ['svg', 'png'], true)) {
            throw new \InvalidArgumentException('Pairing QR format must be svg or png.');
        }
        if ($size < 240 || $size > 1600) {
            throw new \InvalidArgumentException('Pairing QR image size must be 240–1600 pixels.');
        }
        if ($format === 'png' && !extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD is required for pairing PNG. Use SVG instead.');
        }

        // Validate the mobile setup fields before persisting a new token.
        new SetupProfile($endpointUrl, $operator, 'validation-token');

        $enrollment = $this->gateway->createEnrollment();
        return [
            'enrollmentId' => $enrollment->id,
            'image' => $enrollment->qrDataUri($endpointUrl, $operator, $format, $size),
        ];
    }

    public function approveInstallation(string $deviceId, string $installationId): void
    {
        $this->gateway->approveDevice($deviceId, $installationId);
    }

    public function blockInstallation(string $deviceId, string $installationId): void
    {
        $this->gateway->blockDevice($deviceId, $installationId);
    }

    public function updateDevice(string $deviceId, array $changes): array
    {
        return $this->gateway->updateDevice($deviceId, $changes);
    }

    /** @return list<array<string, mixed>> */
    public function pendingEvents(int $limit = 100): array
    {
        return $this->gateway->pendingEvents($limit);
    }

    public function acknowledgeEvent(string $eventId): void
    {
        $this->gateway->acknowledgeEvent($eventId);
    }
}
