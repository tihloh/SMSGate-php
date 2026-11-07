<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$gateway = require __DIR__ . '/bootstrap.php';
function argument(int $index): string {
    global $argv;
    if (!isset($argv[$index])) throw new InvalidArgumentException('Missing argument; run without arguments for usage.');
    return $argv[$index];
}
try {
    switch ($argv[1] ?? '') {
        case 'init': $gateway->installSchema(); echo "Schema ready.\n"; break;
        case 'register':
            $name = argument(2); $url = argument(3); $operator = argument(4);
            // Validate setup before issuing a credential that is only shown once.
            new \Tihloh\SmsGate\SetupProfile($url, $operator, 'validation-only');
            $credentials = $gateway->registerDevice($name);
            echo "Server device ID: " . $credentials->id . "\nSetup JSON (keep private):\n" . $credentials->setup($url, $operator)->toJson() . "\n";
            break;
        case 'devices': echo json_encode($gateway->devices(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"; break;
        case 'installations': echo json_encode($gateway->installations(argument(2)), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"; break;
        case 'approve': $gateway->approveDevice(argument(2), argument(3)); echo "Approved.\n"; break;
        case 'block': $gateway->blockDevice(argument(2), argument(3)); echo "Blocked.\n"; break;
        case 'disable': $gateway->setDeviceEnabled(argument(2), false); echo "Disabled.\n"; break;
        case 'enable': $gateway->setDeviceEnabled(argument(2), true); echo "Enabled.\n"; break;
        case 'queue':
            $deviceId = argument(2); $file = argument(3);
            $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $result = $gateway->queue($deviceId, $data['recipients'], $data['body'], $data['values'] ?? [], $data['idempotencyKey'] ?? null, $data['expiresAt'] ?? null);
            echo json_encode(['jobIds' => $result->jobIds, 'idempotencyKey' => $result->idempotencyKey], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            break;
        default:
            echo "php examples/console.php init\n"
                . "php examples/console.php register \"Honor X9c\" \"https://sms.example.com/\" \"Your organization\"\n"
                . "php examples/console.php devices\n"
                . "php examples/console.php installations DEVICE_ID\n"
                . "php examples/console.php approve DEVICE_ID INSTALLATION_ID\n"
                . "php examples/console.php block DEVICE_ID INSTALLATION_ID\n"
                . "php examples/console.php disable DEVICE_ID\n"
                . "php examples/console.php enable DEVICE_ID\n"
                . "php examples/console.php queue DEVICE_ID examples/message.json\n";
    }
} catch (\Throwable $error) { fwrite(STDERR, get_class($error) . ': operation failed. Check arguments, configuration, and schema.\n'); exit(1); }
