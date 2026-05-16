# PHP Request Forwarder

A small, framework-agnostic PHP library for forwarding HTTP requests with faithful passthrough, automatic retry, and pluggable logging.

[![Tests](https://github.com/monk24215/url-redirect-php-request-forwarder/actions/workflows/tests.yml/badge.svg)](https://github.com/monk24215/url-redirect-php-request-forwarder/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.0-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## What it does

- Retries on transient failures (5xx, network errors) with exponential backoff
- Does **not** retry 4xx — those are client-side conditions that won't change
- Strips hop-by-hop headers (`Host`, `Content-Length`, `Connection`, etc.) automatically
- Returns a structured `ForwardResult` object (status, body, headers, attempts, duration, error)
- Optional transparent proxy mode (echoes upstream response back to the caller)
- Pluggable logging — ships with file (JSONL), PDO (MySQL/Postgres/SQLite), null, or bring your own

## What it does NOT do

- **It cannot guarantee 100% delivery.** Networks fail, targets go down, certificates expire. What this library guarantees is faithful passthrough, sensible retry behavior, and structured error reporting so your caller can make informed decisions.
- It is not a streaming proxy — request and response bodies are buffered in memory. For multi-gigabyte payloads use a different tool.
- It does not handle authentication for you — pass any auth headers in via the `headers` option.

## Installation

```bash
composer require monk242/url-redirect-php-request-forwarder
```

Requires PHP 8.0+, ext-curl, ext-json.

## Quick start

### Basic forward

```php
use RequestForwarder\RequestForwarder;

$rf = new RequestForwarder('https://api.example.com/webhook', [
    'method'  => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => json_encode(['event' => 'order.created']),
]);

$resp = $rf->forward();

if ($resp->ok) {
    echo "Forwarded successfully in {$resp->durationMs}ms";
} else {
    error_log("Forward failed after {$resp->attempts} attempts: {$resp->error}");
}
```

### Transparent proxy (relay incoming request to upstream)

```php
use RequestForwarder\RequestForwarder;

RequestForwarder::fromIncomingRequest('https://upstream.example.com/endpoint')
    ->proxy();
```

This auto-detects the incoming request's method, query, body, headers, and cookies, forwards them, and echoes the upstream response back to the caller.

### With file logging

```php
use RequestForwarder\RequestForwarder;
use RequestForwarder\Logger\FileLogger;

$logger = new FileLogger('/var/log/forwards.jsonl');
$rf = new RequestForwarder('https://api.example.com', [], $logger);
$rf->forward();
```

### With database logging

Apply `sql/schema.sql` to your database, then:

```php
use RequestForwarder\Logger\PdoLogger;

$pdo = new PDO('mysql:host=localhost;dbname=mydb', $user, $pass);
$logger = new PdoLogger($pdo);
$rf = new RequestForwarder('https://api.example.com', [], $logger);
```

### Custom logger

Implement `LoggerInterface`:

```php
use RequestForwarder\Logger\LoggerInterface;
use RequestForwarder\ForwardResult;

final class MyLogger implements LoggerInterface {
    public function log(array $request, ForwardResult $result): void {
        // ship to Monolog / Sentry / Datadog / etc.
    }
}
```

## Configuration

All options passed via the second constructor argument:

| Option | Default | Description |
|---|---|---|
| `method` | `'GET'` | HTTP method |
| `query` | `[]` | Query params (merged into target URL) |
| `body` | `''` | Request body (string) |
| `headers` | `[]` | Request headers (`['Name' => 'Value']`) |
| `cookies` | `[]` | Cookies (merged into `Cookie` header) |
| `timeout` | `30` | Total timeout (seconds) |
| `connect_timeout` | `10` | Connection timeout (seconds) |
| `max_retries` | `3` | Max attempts on 5xx/network errors |
| `retry_delay_ms` | `250` | Base delay (exponential backoff) |
| `follow_redirects` | `true` | Follow 3xx |
| `max_redirects` | `5` | Redirect cap |
| `verify_ssl` | `true` | Verify peer + host (**leave on in production**) |
| `strip_headers` | hop-by-hop list | Headers to strip before forwarding |
| `source_label` | `null` | Tag for log entries |
| `user_agent` | `'url-redirect-php-request-forwarder/1.0'` | Sent if no `User-Agent` header provided |
| `auto_detect_incoming` | `false` | Populate from `$_SERVER`/`$_GET`/`php://input` |

## ForwardResult

```php
$resp->ok          // bool — true if 2xx/3xx and no transport error
$resp->status      // int — HTTP status code (0 if request never completed)
$resp->headers     // array — response headers (repeated headers become arrays)
$resp->body        // string — response body
$resp->attempts    // int — how many tries it took
$resp->durationMs  // int — total wall-clock time
$resp->finalUrl    // string — URL actually requested (with merged query)
$resp->error       // ?string — error message if !ok
$resp->json()      // mixed — convenience JSON decode of body
$resp->toArray()   // array — full result as array
```

## Retry semantics

- **5xx and network errors are retried** up to `max_retries` times with exponential backoff (`retry_delay_ms * 2^(attempt-1)`)
- **4xx is NOT retried** — these indicate a client-side condition (bad request, auth, not found) that won't change between retries
- **2xx/3xx returns immediately**
- A failure after all retries still returns a `ForwardResult` (not an exception) with `ok=false` and the last error

## Security notes

- Leave `verify_ssl` on in production. Disabling it exposes you to MITM attacks.
- Be careful when using `fromIncomingRequest()` on a public endpoint — you become a proxy. Restrict which targets are allowed, rate-limit, and authenticate callers.
- Request and response bodies may contain sensitive data. Configure your logger's body cap accordingly, or implement a redacting logger.

## Testing

```bash
composer install
composer test
```

Tests hit `httpbin.org` and require outbound HTTP. To run offline, you'll need to mock cURL or use a local HTTP server.

## Contributing

Pull requests welcome. Please:
1. Open an issue first for non-trivial changes
2. Add tests for new behavior
3. Follow PSR-12

## License

MIT — see [LICENSE](LICENSE).
