<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

$path = getenv('SMSGATE_SQLITE') ?: __DIR__ . '/private/gateway.sqlite';
if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
return \Tihloh\SmsGate\Gateway::sqlite($path);
