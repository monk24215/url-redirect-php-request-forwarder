<?php
require __DIR__ . '/../vendor/autoload.php';

use RequestForwarder\RequestForwarder;

$rf = new RequestForwarder('https://httpbin.org/post', [
    'method'  => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => json_encode(['hello' => 'world']),
]);

$resp = $rf->forward();

echo "OK: " . ($resp->ok ? 'yes' : 'no') . PHP_EOL;
echo "Status: {$resp->status}" . PHP_EOL;
echo "Attempts: {$resp->attempts}" . PHP_EOL;
echo "Duration: {$resp->durationMs}ms" . PHP_EOL;
echo "Body: {$resp->body}" . PHP_EOL;
