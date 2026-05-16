<?php
// Drop this behind any URL to transparently relay incoming requests upstream.
require __DIR__ . '/../vendor/autoload.php';

use RequestForwarder\RequestForwarder;

RequestForwarder::fromIncomingRequest(
    'https://your-upstream.example.com/endpoint',
    ['source_label' => 'public_webhook_relay']
)->proxy();
