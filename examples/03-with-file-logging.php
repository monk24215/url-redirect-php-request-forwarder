<?php
require __DIR__ . '/../vendor/autoload.php';

use RequestForwarder\RequestForwarder;
use RequestForwarder\Logger\FileLogger;

$logger = new FileLogger(__DIR__ . '/../logs/forwards.jsonl');

$rf = new RequestForwarder(
    'https://httpbin.org/get',
    ['source_label' => 'health_check'],
    $logger
);

$resp = $rf->forward();
echo $resp->ok ? "OK\n" : "FAIL: {$resp->error}\n";
