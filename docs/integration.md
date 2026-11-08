# Integration reference

## One-line POST processing

Your PHP application owns one endpoint and decides that the request is POST. The Android JSON body selects the action, so processing is one line:

```php
$sms->process()->send();
```

The setup profile `url` is the exact endpoint. Every request goes there with an `action` value of `pull`, `push`, `status`, or `test`.

| Action | Android trigger | Data effect |
|---|---|---|
| `pull` | Polls for work | Atomically claims at most 10 queued SMS and returns them. |
| `push` | Incoming receiver/inbox sync | Stores one incoming SMS, deduplicates, then acknowledges. |
| `status` | Modem sent/delivery callback | Stores the reported result and advances effective job state when valid. |
| `test` | User taps Test connection | Verifies token, registers installation as pending, and reports approval without reading/sending SMS. |

The package deliberately does not register the endpoint or enforce POST.

`process()` reads `HTTP_AUTHORIZATION`, `CONTENT_TYPE`, `HTTP_IDEMPOTENCY_KEY`, and up to 1 MiB from `php://input`. For a framework/controller that already parsed the request:

```php
$response = $sms->process(
    request: $requestData,
    token: $bearerToken,
    idempotencyKey: $requestKey,
);

return your_response($response->json(), $response->status, $response->headers());
```

`Response::send()` sends status and JSON/no-cache headers and echoes JSON; it does not exit. `data`, `status`, `headers()`, and `json()` support adapters. All error bodies are JSON. Infrastructure errors become `{ "error": "server_error" }` without exception details.

## Construction and tables

```php
$sms = \Tihloh\SmsGate\Gateway::sqlite(__DIR__.'/private/smsgate.sqlite');
// MySQL: create named database if missing, then ensure SMSGate tables:
$sms = \Tihloh\SmsGate\Gateway::mysql(
    'mysql:host=127.0.0.1;dbname=your_app;charset=utf8mb4',
    $username, $password, prefix: 'smsgate_'
);
// Or reuse a dedicated PDO connection to an existing database:
$sms = \Tihloh\SmsGate\Gateway::pdo($pdo, prefix: 'smsgate_');
```

The SQLite parent directory and missing tables are created automatically. MySQL can create a missing database when permissions allow; it never drops/replaces it or modifies unrelated host tables. `installSchema()` remains an optional explicit deployment command. Supported PDO drivers are SQLite and MySQL. Use a dedicated PDO outside host transactions because SMSGate owns its short transactions. Fresh installations use the five-table version-two schema. Missing tables are created only when the SDK is allowed to initialize its storage; existing schema version-one databases must be explicitly migrated before upgrading the package. A mismatched schema version fails closed.

## Business callbacks initiated by Android

```php
$sms->on('sms.received', function (array $event): void {
    // Deduplicate your business effect by $event['id'].
    // $event['data'] contains id, sender, body, timestamp, subscriptionId.
});
$sms->on('sms.status', $statusHandler);
$sms->process($action)->send();
```

Callbacks run only after the SMSGate database commit and only because a valid Android request called `process()`. A callback failure does not make Android retry an already stored SMS. Instead, its event remains pending and retries on another valid Android request. `callbackErrors()` returns `eventId` and exception class for failures in the current dispatch; it excludes exception messages and payloads. Use `'*'` as a fallback handler. One handler per type is retained on a Gateway instance.

Callbacks are at-least-once. Deduplicate your side effects by event ID. Multiple PHP requests can race to process the same unacknowledged event, so the business handler must be idempotent. Use `pendingEvents()` / `acknowledgeEvent()` manually if you later prefer your own worker. No callback executes inside an SMSGate transaction.

Event types: `device.pending`, `device.approved`, `device.blocked`, `device.updated`, `device.enabled`, `device.disabled`, `device.token_rotated`, `job.cancelled`, `sms.received`, and `sms.status`.

## Developer CRUD API

## Single and multi-gateway deployments

Every phone can use the same endpoint URL. Its bearer token selects its gateway, so no device ID is trusted from the URL or chosen by the client. A one-phone deployment uses the normal device-scoped methods. For a shared deployment, `sharedJobs()`, `sharedInbox()`, and `sharedStatusEvents()` return consolidated server data; each row includes `gateway.id`, `gateway.name`, and `gateway.enabled`.

Use `queueToDevices([$idA, $idB], ...)` to create the same recipient/template batch for selected gateways in one transaction. Use `queueToAllApproved(...)` only when an intentional broadcast to every enabled, approved gateway is required. Each generated job still belongs to one gateway and every incoming/status record retains its originating gateway.

For web inboxes, `getInbox()`, `getInboxMessage()`, `updateInbox()`, and `deleteInbox()` provide array-based CRUD without exposing package tables. The list method accepts `id`, exact `sender`, `from`, and `to` SMS timestamp filters. Bulk update/delete accept a message ID or the same non-empty filter and affect at most 1,000 rows per call.

Host-application methods are never reachable through `process()`:

### Devices

- Phone-led onboarding: `createEnrollment()` → `EnrollmentCredentials`; show its setup JSON once. The phone supplies its name during its first `test` action, which creates a pending device record.
- Direct provisioning: `registerDevice($name)` → `DeviceCredentials`; call `token()` only to display/store it once.
- Read: `getDevice($id)`, `devices($limit, $offset)`; token hashes are omitted.
- Update: `updateDevice($id, ['name'=>..., 'enabled'=>bool])`; convenience `setDeviceEnabled()`.
- Delete: `deleteDevice($id)` deletes all server records owned by that credential. It cannot recall downloaded SMS.
- Rotate: `rotateToken($id)` invalidates the old token and blocks installations until reapproval.

### Installations

- Create: Android test/pull/push/status registers pending automatically; trusted code may use `registerInstallation()`.
- Read: `getInstallation($deviceId, $installationId)`, `installations()`.
- Update: `updateInstallation(..., 'pending'|'approved'|'blocked')`; `approveDevice()` / `blockDevice()` are shortcuts. Approval blocks a previously approved installation for the credential.
- Delete: `deleteInstallation()`; its next valid-token request registers it pending again.

### Jobs

- Create: `queue()` handles 1–100 recipients, templates, global/local values, expiry, deduplication, and idempotency.
- Read: `getJob()` / `job()` and `jobs()`.
- Update: `updateJob($id, ['recipient'=>..., 'body'=>..., 'expiresAt'=>..., 'metadata'=>[...]])`. Content/expiry changes require QUEUED; metadata may be added later (maximum encoded metadata: 16 KiB).
- Delete: `deleteJob()` hides and redacts queued/terminal content while retaining IDs/replay protection. CLAIMED, STORED, SENDING, and UNKNOWN jobs return `job_in_flight` because deletion could hide an uncertain send. Cached claims are scrubbed when safe.
- Cancel: `cancelJob()` changes only QUEUED to CANCELLED and never claims to recall modem submissions.

### Incoming SMS and status records

- Create/import: `createIncoming(...)`, `createStatusEvent(...)`. Android uses push/status actions normally.
- Read: `getIncoming()`, `incoming()`, `getStatusEvent()`, `statusEvents()`.
- Update: `updateIncoming($deviceId,$id,$metadata)` and `updateStatusEvent(...)`. Original transport facts remain immutable for retry verification; your application's mutable fields belong in metadata.
- Delete: `deleteIncoming()` / `deleteStatusEvent()` hide and clear stored payloads, clear matching pending callback copies, and preserve transport hashes so Android retries acknowledge without restoring deleted data.

List pagination accepts 1–1000 rows and a non-negative offset. Times are Unix milliseconds. Store your own application identifiers in metadata rather than modifying package tables.

## Approval and test

`test` accepts a valid token before approval and returns:

```json
{
  "ok": true,
  "approved": false,
  "status": "pending",
  "deviceId": "SERVER_DEVICE_UUID",
  "installationId": "ANDROID_INSTALLATION_UUID",
  "serverTime": 1788500000000
}
```

It does not read, queue, claim, upload, or send SMS. Invalid tokens get 401. Pull/push/status get 403 while pending/blocked. Review both IDs in your administration UI before `approveDevice()`.

## Error handling

Trusted CRUD methods throw `Tihloh\SmsGate\Exception\GatewayException` with `reason`, `httpStatus`, and safe `details`. Examples: `device_not_found`, `installation_not_found`, `idempotency_conflict`, `job_in_flight`, `unknown_update_field`, and `invalid_metadata`. Database/filesystem errors remain infrastructure exceptions. The request processor translates both categories to safe JSON.

Keep admin methods behind your application's authentication/authorization. Device tokens only authorize the four device actions. Approval binds token + installation UUID, not hardware; a copied pair can impersonate the phone. Use HTTPS to protect tokens and SMS in transit.

