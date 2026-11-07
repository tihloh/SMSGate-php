# SMSGate for PHP

**`tihloh/smsgate`** is a Composer library for integrating the SMSGate Android app into your own PHP application. It is independent of the example servers, has no framework dependency, and does not start a server, create routes, or migrate a database during Composer installation.

It includes approved device installations, hashed device tokens and rotation, personalized bulk jobs, persistent claims and acknowledgements, incoming SMS, delivery-status tracking, durable integration events, QR-compatible setup JSON, and a plain PHP request processor. It has no runtime library dependency beyond PHP, PDO, and JSON.

**Package status:** maintained in the private SMSGate monorepo and mirrored to the [public SMSGate-php repository](https://github.com/tihloh/SMSGate-php), with `composer.json` at the repository root. Packagist registration is separate and has not been completed. Until it is registered, use the local path installation below. See [release instructions](docs/releasing.md).

## Install locally today

Requires PHP 8.2+, PDO, and either PDO SQLite or PDO MySQL. In your application's `composer.json`, add:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/path/to/SMSGate/packages/php",
      "options": {"versions": {"tihloh/smsgate": "dev-main"}}
    }
  ],
  "require": {"tihloh/smsgate": "dev-main"}
}
```

Run `composer update tihloh/smsgate`. Windows paths can use forward slashes, such as `H:/Projects/SMSGate/packages/php`. No Nyholm, PSR, framework, router, or HTTP-client package is required.

## Quick start

### 1. Configure storage

```php
require __DIR__ . '/vendor/autoload.php';

use Tihloh\SmsGate\Gateway;

// The directory and required tables are created when missing.
$gateway = Gateway::sqlite(__DIR__ . '/private/smsgate.sqlite');
```

For MySQL 8.0, use a **dedicated PDO MySQL connection**, not `mysqli`:

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=your_app;charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$gateway = Gateway::pdo($pdo, 'smsgate_');
$gateway->installSchema(); // Optional explicit deployment step; normal use also checks.
```

See the [full PDO MySQL setup guide](docs/mysql-pdo.md) and [working connection example](examples/mysql-pdo.php).

The compact schema uses **five tables**: `smsgate_meta`, `smsgate_devices`, `smsgate_installations`, `smsgate_jobs` and `smsgate_records`. The typed records table stores enrollment credentials, deduplication caches, incoming and status receipts, callback events, and metadata without losing their individual uniqueness rules. Tables are prefixed and created automatically when missing. `installSchema()` remains available for deployment scripts and is repeatable. MySQL uses InnoDB. Use a dedicated connection: the package owns its transactions and rejects nesting inside your application's transaction. See [storage and reliability](docs/reliability.md).

### 2. Process the single Android endpoint

```php
if ($requestIsPost) {
    $gateway->process()->send();
}
```

The app sends every request to the exact setup URL and includes one action in its JSON body:

- `pull`: Android fetches queued SMS.
- `push`: Android uploads an incoming SMS.
- `status`: Android uploads sent/delivery results.
- `test`: Android tests credentials and reads approval status without touching SMS.

The package does not register the endpoint or check POST because those are your application's responsibility. With no arguments, `process()` reads the bearer token, JSON body, `action`, and idempotency header from PHP globals. Controllers that already parsed the body can call `process($parsedJson)`. It returns a plain `Http\Response`; `send()` sets JSON/no-cache headers and echoes without exiting.

```php
$response = $gateway->process($parsedJson, token: $bearerToken, idempotencyKey: $idempotencyKey);
return your_framework_response($response->json(), $response->status, $response->headers());
```

Use the exact endpoint, such as `https://your-domain.example/sms-gateway`, as the setup URL. HTTP works but exposes credentials and SMS. [Plain PHP example](examples/public/index.php) · [Integration reference](docs/integration.md)

### 3. Create an enrollment QR and approve the phone

Run these methods only from your trusted administration code:

```php
$enrollment = $gateway->createEnrollment();

// Display privately once. It expires in 15 minutes and creates no device record yet.
$setupJson = $enrollment->setup('https://your-domain.example/sms-gateway', 'Your organization')->toJson();
```

Encode `$setupJson` directly in a QR, or supply it as a setup file. The app recognizes this format:

```json
{"version":1,"url":"https://your-domain.example/sms-gateway","operator":"Your organization","token":"GENERATED_DEVICE_TOKEN"}
```

The `url` is the single exact POST endpoint. No `/v1/` prefix or additional route configuration is assumed.

The setup profile also offers `toArray()`. Generate a QR directly from `toJson()`, or save that JSON as the setup file. Keep all exports private. Debug output redacts credentials, but explicit token/export methods return secrets.

When the configured phone first uses **Test connection**, it sends its own gateway name. The one-time enrollment then creates its gateway record and a **pending** installation. Obtain the server `deviceId` from the test response or the `device.enrolled` integration event, then list and approve that specific installation:

```php
$pending = $gateway->installations($deviceId);
$gateway->approveDevice($deviceId, $installationIdYouReviewed);
```

There are two IDs: `$deviceId` identifies the server credential/queue; the **installation ID** is the UUID generated by the Android installation. Never automatically approve the first pending row. Only one installation per server device is approved; approving a replacement blocks the previous one. Device HTTP credentials cannot invoke administrative methods. The app's current access-denied display is generic; after approval, retry sync.

### 4. Queue SMS with arbitrary placeholders

```php
$batch = $gateway->queue(
    deviceId: $deviceId,
    recipients: [
        ['number' => '+639171234567', 'values' => ['name' => 'Juan', 'obr_no' => '2026-00123']],
        ['number' => '+639181234567', 'values' => ['name' => 'Maria', 'obr_no' => '2026-00124']],
    ],
    body: 'Hi {{name}}, your OBR {{obr_no}} was processed by {{organization}}.',
    values: ['organization' => 'Provincial Budget Office'],
    idempotencyKey: 'processed-obrs-batch-2026-0001',
);

$jobIds = $batch->jobIds;
```

Simple sending: `$gateway->queue($deviceId, ['+639171234567'], 'Hello!', idempotencyKey: $yourStableKey)`.

Recipient values override global values. Keys are arbitrary and case-sensitive; dotted names are literal keys. Strings, finite numbers, and booleans are supported; use strings for exact money formatting. Values are substituted once, so placeholder-looking values are not recursively evaluated. Missing values or any invalid recipient reject the **whole batch**. Identical final number/message pairs within a batch are deduplicated.

Limits: 1–100 recipients, E.164 numbers, and 4,800 UTF-8 bytes per template/rendered message. Long SMS may require multiple carrier segments. `expiresAt` is optional Unix milliseconds (default: 24 hours from the first successful enqueue). Queuing is allowed before phone approval, but claiming is not. An omitted idempotency key creates new work each call; supply a stable key for application retries. Reusing a key with a different effective batch/expiry raises `idempotency_conflict`.

### 5. Read SMS and results

```php
$job = $gateway->job($jobIds[0]);
$state = $job['state']; // QUEUED, CLAIMED, STORED, SENDING, SENT, DELIVERED, ...
$messages = $gateway->incoming($deviceId, limit: 50);
$reports = $gateway->statusEvents($deviceId, limit: 50);
```

For an inbox page, use the friendlier CRUD names. They return ordinary PHP arrays, ready for templates or `json_encode()`:

```php
$rows = $gateway->getInbox($deviceId, ['sender' => '+639171234567'], limit: 50);
$one = $gateway->getInboxMessage($deviceId, $messageId);
$gateway->updateInbox($deviceId, $messageId, ['read' => true]);
$gateway->deleteInbox($deviceId, ['to' => 1788192000000]);
```

Inbox filters are `id`, exact `sender`, `from`, and `to` (the SMS timestamp in Unix milliseconds). `updateInbox()` and `deleteInbox()` accept either one message ID or a non-empty filter and return the affected count. Bulk operations are capped at 1,000 records per call. Table names, SQL, redaction, deduplication records, and replay protection stay inside the package.

### Shared endpoint: one or many gateways

One endpoint can serve one phone or many. The Android token identifies its gateway, while shared server views preserve that origin in every row:

```php
// Each row includes gateway: ['id' => ..., 'name' => ..., 'enabled' => ...].
$queue = $gateway->sharedJobs(['state' => 'QUEUED']);
$inbox = $gateway->sharedInbox(['sender' => '+639171234567']);
$reports = $gateway->sharedStatusEvents(['deviceId' => $gatewayId]);

// Target specific gateways, or explicitly broadcast only to approved gateways.
$batches = $gateway->queueToDevices([$gatewayA, $gatewayB], $recipients, $body, $values, $stableKey);
$broadcast = $gateway->queueToAllApproved($recipients, $body, $values, $stableKey);
```

`queueToDevices()` is transactional: it validates every selected gateway before creating any jobs. `queueToAllApproved()` is explicit and targets only enabled gateways with an approved installation. For a single-phone deployment, keep using `queue($gatewayId, ...)`, `getInbox($gatewayId)`, and `statusEvents($gatewayId)`.

Business callbacks can be driven entirely by Android requests:

```php
$gateway->on('sms.received', function (array $event): void {
    $yourApplication->processSmsGateEvent($event);
});
$gateway->on('sms.status', $yourStatusCallback);
$gateway->process($action)->send();
```

Events are stored in the same transaction as their originating change. Callbacks run afterward. A failure does not change the Android acknowledgement; the event retries on a later valid Android request. Deduplicate your side effects by `$event['id']`. `callbackErrors()` reports safe event/error-class details for the current dispatch. You can also use `pendingEvents()` and `acknowledgeEvent()` manually. No callback runs inside a database transaction.

## Administration

```php
$gateway->devices(limit: 100, offset: 0);       // list
$gateway->getDevice($deviceId);                 // read
$gateway->updateDevice($deviceId, ['name' => 'Office phone']);
$gateway->deleteDevice($deviceId);              // destructive: all related server records
$gateway->blockDevice($deviceId, $installationId);
$gateway->setDeviceEnabled($deviceId, false); // Deny all requests for the token.
$replacement = $gateway->rotateToken($deviceId); // Old token immediately invalid.
// Rotation blocks installations; reconfigure the phone and explicitly reapprove it.
$gateway->cancelJob($jobId); // true only if still QUEUED; cannot recall claimed SMS.
$gateway->getJob($jobId);
$gateway->updateJob($jobId, ['metadata' => ['reference' => 'OBR-001']]);
$gateway->deleteJob($jobId); // redacts content, keeps replay protection
$gateway->getIncoming($deviceId, $messageId);
$gateway->updateIncoming($deviceId, $messageId, ['reviewed' => true]);
$gateway->deleteIncoming($deviceId, $messageId);
```

Keep these methods behind your own authentication/authorization. A valid token plus a copied approved installation ID can impersonate that installation: approval is not hardware attestation. Blocking stops future server requests, not SMS already downloaded by the app.

## Run the included example

From this package folder:

```sh
composer install
php examples/console.php init
php examples/console.php register "Honor X9c" "http://YOUR_PC_IP:8080/" "Your organization"
php -S 0.0.0.0:8080 -t examples/public examples/public/index.php
```

Scan the privately printed JSON, enable desired features, and start the app. In another terminal:

```sh
php examples/console.php installations SERVER_DEVICE_ID
php examples/console.php approve SERVER_DEVICE_ID INSTALLATION_ID
php examples/console.php queue SERVER_DEVICE_ID examples/message.json
```

The sample queue contains example recipients: replace them with your authorized test numbers before sending. `SMSGATE_SQLITE` overrides the example database path. The built-in server is for development. No real messages are sent by the package's test suite.

## Development

```sh
composer install
composer check
```

Tests cover the Android contract, approval/revocation, templates, idempotency, data isolation, HTTP validation, persisted retries, and competing SQLite claim processes. See [release notes](CHANGELOG.md), [reliability boundaries](docs/reliability.md), and [publishing](docs/releasing.md). This package has not been published or deployed by merely creating these files.

