<?php
require __DIR__ . '/../vendor/autoload.php';

use RequestForwarder\RequestForwarder;
use RequestForwarder\Logger\PdoLogger;

$pdo = new PDO('mysql:host=localhost;dbname=mydb;charset=utf8mb4', 'user', 'pass', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$logger = new PdoLogger($pdo);

$rf = new RequestForwarder(
    'https://api.example.com/webhook',
    ['method' => 'POST', 'body' => json_encode(['event' => 'ping']), 'source_label' => 'event_webhook'],
    $logger
);

$resp = $rf->forward();
print_r($resp->toArray());
