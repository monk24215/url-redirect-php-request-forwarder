<?php
declare(strict_types=1);

namespace RequestForwarder;

use RequestForwarder\Logger\LoggerInterface;
use RequestForwarder\Logger\NullLogger;
use RequestForwarder\Exception\ForwarderException;

/**
 * Forwards HTTP requests with full passthrough, retries, and pluggable logging.
 *
 * Faithfully forwards method, query string, body, headers, and cookies.
 * Retries on transient failures (5xx, network errors) with exponential backoff.
 * Does NOT retry 4xx — those are client-side conditions that won't change.
 */
final class RequestForwarder
{
    /** @var array<string,mixed> */
    private array $opts;
    private LoggerInterface $logger;

    /** Hop-by-hop and proxy-sensitive headers that should not be forwarded. */
    private const DEFAULT_STRIP_HEADERS = [
        'host', 'content-length', 'connection', 'expect',
        'accept-encoding', 'proxy-connection', 'transfer-encoding',
        'keep-alive', 'te', 'trailer', 'upgrade',
    ];

    /**
     * @param string $targetUrl Destination URL
     * @param array<string,mixed> $opts Configuration overrides
     * @param LoggerInterface|null $logger Optional logger (defaults to NullLogger)
     */
    public function __construct(
        private readonly string $targetUrl,
        array $opts = [],
        ?LoggerInterface $logger = null
    ) {
        if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            throw new ForwarderException("Invalid target URL: $targetUrl");
        }

        $this->opts = $opts + [
            'method'           => 'GET',
            'query'            => [],
            'body'             => '',
            'headers'          => [],
            'cookies'          => [],
            'timeout'          => 30,
            'connect_timeout'  => 10,
            'max_retries'      => 3,
            'retry_delay_ms'   => 250,
            'follow_redirects' => true,
            'max_redirects'    => 5,
            'verify_ssl'       => true,
            'strip_headers'    => self::DEFAULT_STRIP_HEADERS,
            'source_label'     => null,
            'user_agent'       => 'php-request-forwarder/1.0',
            'auto_detect_incoming' => false,
        ];

        if ($this->opts['auto_detect_incoming']) {
            $this->autoDetectFromIncomingRequest();
        }

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create a forwarder pre-populated from the current incoming HTTP request.
     * Use this for transparent webhook/proxy scenarios.
     */
    public static function fromIncomingRequest(
        string $targetUrl,
        array $opts = [],
        ?LoggerInterface $logger = null
    ): self {
        $opts['auto_detect_incoming'] = true;
        return new self($targetUrl, $opts, $logger);
    }

    /** Execute the forward and return a structured result. */
    public function forward(): ForwardResult
    {
        $this->stripHopHeaders();
        $this->mergeCookiesIntoHeaders();
        $this->ensureUserAgent();

        $finalUrl    = $this->buildFinalUrl();
        $curlHeaders = $this->flattenHeaders();

        $started   = microtime(true);
        $attempt   = 0;
        $lastError = null;
        $status    = 0;
        $body      = '';
        $respHeaders = [];

        while ($attempt < $this->opts['max_retries']) {
            $attempt++;
            $respHeaders = [];

            $ch = curl_init($finalUrl);
            if ($ch === false) {
                throw new ForwarderException('curl_init() failed');
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => strtoupper($this->opts['method']),
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => (bool) $this->opts['follow_redirects'],
                CURLOPT_MAXREDIRS      => (int) $this->opts['max_redirects'],
                CURLOPT_TIMEOUT        => (int) $this->opts['timeout'],
                CURLOPT_CONNECTTIMEOUT => (int) $this->opts['connect_timeout'],
                CURLOPT_SSL_VERIFYPEER => (bool) $this->opts['verify_ssl'],
                CURLOPT_SSL_VERIFYHOST => $this->opts['verify_ssl'] ? 2 : 0,
                CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$respHeaders): int {
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $name = trim($parts[0]);
                        $val  = trim($parts[1]);
                        // Support repeated headers (e.g. Set-Cookie)
                        if (isset($respHeaders[$name])) {
                            $respHeaders[$name] = is_array($respHeaders[$name])
                                ? array_merge($respHeaders[$name], [$val])
                                : [$respHeaders[$name], $val];
                        } else {
                            $respHeaders[$name] = $val;
                        }
                    }
                    return strlen($header);
                },
            ]);

            $methodUpper = strtoupper($this->opts['method']);
            if (!in_array($methodUpper, ['GET', 'HEAD'], true) && $this->opts['body'] !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->opts['body']);
            }

            $rawBody = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno   = curl_errno($ch);
            $errmsg  = curl_error($ch);
            curl_close($ch);

            $body = $rawBody === false ? '' : (string) $rawBody;

            // Success or non-retryable (2xx/3xx/4xx) → done
            if ($errno === 0 && $status > 0 && $status < 500) {
                $result = $this->buildResult(
                    ok: $status >= 200 && $status < 400,
                    status: $status,
                    headers: $respHeaders,
                    body: $body,
                    attempts: $attempt,
                    duration: microtime(true) - $started,
                    finalUrl: $finalUrl,
                    error: null
                );
                $this->doLog($result);
                return $result;
            }

            $lastError = $errno !== 0 ? "cURL $errno: $errmsg" : "HTTP $status";

            if ($attempt < $this->opts['max_retries']) {
                $delay = $this->opts['retry_delay_ms'] * 1000 * (2 ** ($attempt - 1));
                usleep((int) $delay);
            }
        }

        $result = $this->buildResult(
            ok: false,
            status: $status,
            headers: $respHeaders,
            body: $body,
            attempts: $attempt,
            duration: microtime(true) - $started,
            finalUrl: $finalUrl,
            error: $lastError
        );
        $this->doLog($result);
        return $result;
    }

    /**
     * Forward and echo the upstream response back to the current HTTP client, then exit.
     * Use this for transparent proxy / webhook relay scenarios.
     */
    public function proxy(): never
    {
        $resp = $this->forward();

        http_response_code($resp->status ?: 502);

        $skip = ['transfer-encoding', 'content-encoding', 'content-length'];
        foreach ($resp->headers as $name => $value) {
            if (in_array(strtolower($name), $skip, true)) continue;
            if (is_array($value)) {
                foreach ($value as $v) header("$name: $v", false);
            } else {
                header("$name: $value", true);
            }
        }

        echo $resp->body;
        exit;
    }

    // ---------- internals ----------

    private function autoDetectFromIncomingRequest(): void
    {
        if (empty($this->opts['method']) || $this->opts['method'] === 'GET') {
            $this->opts['method'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }
        if (empty($this->opts['query'])) {
            $this->opts['query'] = $_GET ?? [];
        }
        if ($this->opts['body'] === '') {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $this->opts['body'] = $raw;
            } elseif (!empty($_POST)) {
                $this->opts['body'] = http_build_query($_POST);
            }
        }
        if (empty($this->opts['headers'])) {
            $this->opts['headers'] = $this->getIncomingHeaders();
        }
        if (empty($this->opts['cookies'])) {
            $this->opts['cookies'] = $_COOKIE ?? [];
        }
    }

    /** @return array<string,string> */
    private function getIncomingHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            return $h !== false ? $h : [];
        }
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $h[$name] = $v;
            }
        }
        return $h;
    }

    private function stripHopHeaders(): void
    {
        $strip = array_map('strtolower', (array) $this->opts['strip_headers']);
        foreach ($this->opts['headers'] as $k => $_) {
            if (in_array(strtolower((string) $k), $strip, true)) {
                unset($this->opts['headers'][$k]);
            }
        }
    }

    private function mergeCookiesIntoHeaders(): void
    {
        if (empty($this->opts['cookies'])) return;
        $parts = [];
        foreach ($this->opts['cookies'] as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $this->opts['headers']['Cookie'] = implode('; ', $parts);
    }

    private function ensureUserAgent(): void
    {
        $hasUa = false;
        foreach ($this->opts['headers'] as $k => $_) {
            if (strtolower((string) $k) === 'user-agent') { $hasUa = true; break; }
        }
        if (!$hasUa && !empty($this->opts['user_agent'])) {
            $this->opts['headers']['User-Agent'] = $this->opts['user_agent'];
        }
    }

    private function buildFinalUrl(): string
    {
        $existing = [];
        $parsed   = parse_url($this->targetUrl);
        if (!empty($parsed['query'])) parse_str($parsed['query'], $existing);
        $merged = array_merge($existing, (array) $this->opts['query']);
        $qs     = http_build_query($merged);
        $base   = strtok($this->targetUrl, '?');
        return $qs !== '' ? "$base?$qs" : $base;
    }

    /** @return array<int,string> */
    private function flattenHeaders(): array
    {
        $out = [];
        foreach ($this->opts['headers'] as $k => $v) $out[] = "$k: $v";
        return $out;
    }

    private function buildResult(
        bool $ok, int $status, array $headers, string $body,
        int $attempts, float $duration, string $finalUrl, ?string $error
    ): ForwardResult {
        return new ForwardResult(
            ok: $ok,
            status: $status,
            headers: $headers,
            body: $body,
            attempts: $attempts,
            durationMs: (int) ($duration * 1000),
            finalUrl: $finalUrl,
            error: $error,
        );
    }

    private function doLog(ForwardResult $result): void
    {
        try {
            $this->logger->log([
                'source_label'    => $this->opts['source_label'],
                'method'          => strtoupper($this->opts['method']),
                'target_url'      => $this->targetUrl,
                'request_headers' => $this->opts['headers'],
                'request_body'    => (string) $this->opts['body'],
                'client_ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
            ], $result);
        } catch (\Throwable $e) {
            error_log('RequestForwarder logger threw: ' . $e->getMessage());
        }
    }
}
