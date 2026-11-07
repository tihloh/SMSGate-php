<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$gateway = \Tihloh\SmsGate\Gateway::sqlite($argv[1]);
echo json_encode($gateway->handleDeviceRequest('/v1/jobs/claim', $argv[2], ['deviceId' => $argv[3]], $argv[4]), JSON_THROW_ON_ERROR);
