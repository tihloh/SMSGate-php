<?php
declare(strict_types=1);
$sms = require dirname(__DIR__) . '/bootstrap.php';
// Your application owns this single endpoint and its method check.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); return; }
// Optional business callbacks run after commit and retry on later Android requests.
// $sms->on('sms.received', function (array $event): void { ...your app logic... });
$sms->process()->send();
