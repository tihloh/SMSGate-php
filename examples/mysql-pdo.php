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

// Preferred host integration when $database is already supplied by your app:
// $gateway = Gateway::database($database, 'smsgate_');
//
// Standalone fallback when no host database object exists:
// create the MySQL database if missing (with CREATE privilege).
$gateway = Gateway::mysql($dsn, $username, $password, 'smsgate_');

// If you already have a *dedicated* PDO connection to an existing database,
// the original API is also available: Gateway::pdo($pdo, 'smsgate_').
// No mysqli adapter or separate SQL installation script is necessary.

// The gateway is ready for trusted CRUD calls or for ->process() in your endpoint.
// Never expose database credentials or registration/admin methods to devices.
