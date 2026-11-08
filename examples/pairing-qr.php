<?php
declare(strict_types=1);

// Run only behind your application's authenticated administration screen.
// This file demonstrates display; your host owns the actual UI and permission checks.
require dirname(__DIR__) . '/vendor/autoload.php';

use Tihloh\SmsGate\Gateway;

$gateway = Gateway::mysql(
    getenv('SMSGATE_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=smsgate;charset=utf8mb4',
    getenv('SMSGATE_MYSQL_USER') ?: '',
    getenv('SMSGATE_MYSQL_PASSWORD') ?: '',
    'smsgate_'
);

$enrollment = $gateway->createEnrollment();
$profile = $enrollment->setup(
    'https://your-domain.example/sms-gateway',
    'Your organization'
);

// The image is generated locally in the SMSGate library; the host only renders.
// Credentials are inside the QR. Never cache or expose this page publicly.
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=UTF-8');

$src = htmlspecialchars($profile->qrDataUri(), ENT_QUOTES, 'UTF-8');
echo '<img width="400" height="400" alt="Scan with SMSGate to pair" src="' . $src . '">';
