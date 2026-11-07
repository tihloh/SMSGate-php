<?php
declare(strict_types=1);

// Copy this snippet into your trusted server code, not a public web directory.
// Environment variables are preferable to hard-coded MySQL credentials.
require dirname(__DIR__) . '/vendor/autoload.php';

use Tihloh\SmsGate\Gateway;

$dsn = getenv('SMSGATE_MYSQL_DSN')
    ?: 'mysql:host=127.0.0.1;port=3306;dbname=your_app;charset=utf8mb4';
$username = getenv('SMSGATE_MYSQL_USER') ?: '';
$password = getenv('SMSGATE_MYSQL_PASSWORD') ?: '';

$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// Use a *dedicated* PDO connection; SMSGate manages its own transactions.
// Missing smsgate_ tables are created on first use by this constructor.
$gateway = Gateway::pdo($pdo, 'smsgate_');

// Optional deployment command; safe to repeat:
// $gateway->installSchema();

// The gateway is ready for trusted CRUD calls or for ->process() in your endpoint.
// Never expose database credentials or registration/admin methods to devices.
