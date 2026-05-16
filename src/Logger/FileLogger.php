<?php
declare(strict_types=1);

namespace RequestForwarder\Logger;

use RequestForwarder\ForwardResult;
use RequestForwarder\Exception\ForwarderException;

final class FileLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $path,
        private readonly int    $bodyMax = 8192
    ) {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ForwarderException("Cannot create log directory: $dir");
        }
    }

    public function log(array $request, ForwardResult $result): void
    {
        $entry = [
            'ts'              => date('c'),
            'source_label'    => $request['source_label'] ?? null,
            'method'          => $request['method'],
            'target_url'      => $request['target_url'],
            'final_url'       => $result->finalUrl,
            'request_headers' => $request['request_headers'],
            'request_body'    => $this->truncate($request['request_body']),
            'response_status' => $result->status,
            'response_headers'=> $result->headers,
            'response_body'   => $this->truncate($result->body),
            'attempts'        => $result->attempts,
            'duration_ms'     => $result->durationMs,
            'ok'              => $result->ok,
            'error'           => $result->error,
            'client_ip'       => $request['client_ip'] ?? null,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private function truncate(string $s): string
    {
        return strlen($s) > $this->bodyMax ? substr($s, 0, $this->bodyMax) . '...[truncated]' : $s;
    }
}
