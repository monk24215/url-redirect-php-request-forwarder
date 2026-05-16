<?php
declare(strict_types=1);

namespace RequestForwarder\Logger;

use PDO;
use RequestForwarder\ForwardResult;

final class PdoLogger implements LoggerInterface
{
    public function __construct(
        private readonly PDO    $pdo,
        private readonly string $table  = 'request_forward_log',
        private readonly int    $bodyMax = 65535
    ) {}

    public function log(array $request, ForwardResult $result): void
    {
        try {
            $sql = "INSERT INTO {$this->table}
                    (source_label, method, target_url, final_url,
                     request_headers, request_body,
                     response_status, response_headers, response_body,
                     attempts, duration_ms, ok, error_message, client_ip)
                    VALUES
                    (:source_label, :method, :target_url, :final_url,
                     :req_headers, :req_body,
                     :resp_status, :resp_headers, :resp_body,
                     :attempts, :duration_ms, :ok, :error_message, :client_ip)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':source_label'  => $request['source_label'] ?? null,
                ':method'        => $request['method'],
                ':target_url'    => $request['target_url'],
                ':final_url'     => $result->finalUrl,
                ':req_headers'   => $this->truncate(json_encode($request['request_headers'])),
                ':req_body'      => $this->truncate($request['request_body']),
                ':resp_status'   => $result->status ?: null,
                ':resp_headers'  => $this->truncate(json_encode($result->headers)),
                ':resp_body'     => $this->truncate($result->body),
                ':attempts'      => $result->attempts,
                ':duration_ms'   => $result->durationMs,
                ':ok'            => $result->ok ? 1 : 0,
                ':error_message' => $result->error,
                ':client_ip'     => $request['client_ip'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break forwarding
            error_log('PdoLogger failed: ' . $e->getMessage());
        }
    }

    private function truncate(?string $s): ?string
    {
        if ($s === null) return null;
        return strlen($s) > $this->bodyMax ? substr($s, 0, $this->bodyMax) . '...[truncated]' : $s;
    }
}
