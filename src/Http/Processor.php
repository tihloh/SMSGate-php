<?php
declare(strict_types=1);
namespace Tihloh\SmsGate\Http;

use Tihloh\SmsGate\{Gateway, Support};
use Tihloh\SmsGate\Exception\GatewayException;

/** Host code owns the endpoint and method check; the Android body selects its action. */
final class Processor
{
    public function __construct(private readonly Gateway $gateway) {}

    public function process(string|array|null $request = null, ?array $data = null, #[\SensitiveParameter] ?string $token = null, ?string $key = null): Response
    {
        try {
            if (is_array($request)) { $data = $request; $request = null; }
            if ($data === null) {
                $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
                if ($contentType !== 'application/json') throw new GatewayException('expected_application_json', 415);
                $raw = file_get_contents('php://input', false, null, 0, 1048577);
                if ($raw === false) throw new GatewayException('invalid_body', 400);
                if (strlen($raw) > 1048576) throw new GatewayException('request_too_large', 413);
                try { $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new GatewayException('invalid_json', 400); }
                if (!$decoded instanceof \stdClass) throw new GatewayException('expected_object', 400);
                $data = get_object_vars($decoded);
            } elseif (strlen(Support::json($data)) > 1048576) throw new GatewayException('request_too_large', 413);
            $action = is_string($request) ? $request : ($data['action'] ?? null);
            if (!is_string($action)) throw new GatewayException('unknown_action', 422);
            unset($data['action']);
            $path = match ($action) {
                'pull' => '/v1/jobs/claim', 'push' => '/v1/incoming', 'status' => '/v1/events', 'test' => '/v1/test', 'heartbeat' => '/v1/presence',
                default => throw new GatewayException('unknown_action', 404),
            };
            if ($token === null) {
                $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
                if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $authorization, $match)) throw new GatewayException('unauthorized', 401);
                $token = $match[1];
            }
            $result = $this->gateway->handleDeviceRequest($path, $token, $data, $key ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
            $response = new Response($result);
        } catch (GatewayException $error) {
            $response = new Response(['error' => $error->reason] + $error->details, $error->httpStatus);
        } catch (\Throwable) {
            return new Response(['error' => 'server_error'], 500);
        }
        // Business callbacks are independent of the committed device acknowledgement.
        // Invalid/unauthenticated requests cannot trigger business processing.
        if ($response->status === 200 || in_array($response->data['error'] ?? '', ['device_pending_approval', 'device_blocked'], true)) {
            $this->gateway->dispatchEvents();
        }
        return $response;
    }
}
