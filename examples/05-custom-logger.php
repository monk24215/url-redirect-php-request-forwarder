<?php
require __DIR__ . '/../vendor/autoload.php';

use RequestForwarder\RequestForwarder;
use RequestForwarder\Logger\LoggerInterface;
use RequestForwarder\ForwardResult;

// Plug in any logging backend — Monolog, Sentry, syslog, Slack, etc.
final class StderrLogger implements LoggerInterface {
    public function log(array $request, ForwardResult $result): void {
        fwrite(STDERR, sprintf(
            "[%s] %s %s -> %d (%dms, %d attempts)\n",
            $request['source_label'] ?? '-',
            $request['method'],
            $request['target_url'],
            $result->status,
            $result->durationMs,
            $result->attempts
        ));
    }
}

(new RequestForwarder('https://httpbin.org/get', [], new StderrLogger()))->forward();
