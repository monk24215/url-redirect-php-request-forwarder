<?php
declare(strict_types=1);

namespace RequestForwarder;

/**
 * Immutable result object returned by RequestForwarder::forward().
 */
final class ForwardResult
{
    public function __construct(
        public readonly bool    $ok,
        public readonly int     $status,
        public readonly array   $headers,
        public readonly string  $body,
        public readonly int     $attempts,
        public readonly int     $durationMs,
        public readonly string  $finalUrl,
        public readonly ?string $error = null,
    ) {}

    public function toArray(): array
    {
        return [
            'ok'          => $this->ok,
            'status'      => $this->status,
            'headers'     => $this->headers,
            'body'        => $this->body,
            'attempts'    => $this->attempts,
            'duration_ms' => $this->durationMs,
            'final_url'   => $this->finalUrl,
            'error'       => $this->error,
        ];
    }

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }
}
