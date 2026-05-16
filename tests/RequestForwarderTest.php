<?php
declare(strict_types=1);

namespace RequestForwarder\Tests;

use PHPUnit\Framework\TestCase;
use RequestForwarder\RequestForwarder;
use RequestForwarder\ForwardResult;
use RequestForwarder\Logger\LoggerInterface;
use RequestForwarder\Exception\ForwarderException;

final class RequestForwarderTest extends TestCase
{
    public function testInvalidUrlThrows(): void
    {
        $this->expectException(ForwarderException::class);
        new RequestForwarder('not-a-url');
    }

    public function testGetReturnsOkResult(): void
    {
        $rf = new RequestForwarder('https://httpbin.org/get', ['timeout' => 15]);
        $resp = $rf->forward();
        $this->assertTrue($resp->ok);
        $this->assertSame(200, $resp->status);
        $this->assertGreaterThanOrEqual(1, $resp->attempts);
    }

    public function testPostBodyIsForwarded(): void
    {
        $payload = json_encode(['ping' => 'pong']);
        $rf = new RequestForwarder('https://httpbin.org/post', [
            'method'  => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $payload,
            'timeout' => 15,
        ]);
        $resp = $rf->forward();
        $this->assertTrue($resp->ok);
        $data = $resp->json();
        $this->assertSame('pong', $data['json']['ping'] ?? null);
    }

    public function testRetriesOnServerError(): void
    {
        $rf = new RequestForwarder('https://httpbin.org/status/503', [
            'max_retries'    => 3,
            'retry_delay_ms' => 50,
            'timeout'        => 10,
        ]);
        $resp = $rf->forward();
        $this->assertFalse($resp->ok);
        $this->assertSame(3, $resp->attempts);
    }

    public function testDoesNotRetryOn404(): void
    {
        $rf = new RequestForwarder('https://httpbin.org/status/404', [
            'max_retries' => 3,
            'timeout'     => 10,
        ]);
        $resp = $rf->forward();
        $this->assertSame(1, $resp->attempts);
        $this->assertSame(404, $resp->status);
    }

    public function testLoggerIsCalled(): void
    {
        $logger = new class implements LoggerInterface {
            public array $calls = [];
            public function log(array $request, ForwardResult $result): void {
                $this->calls[] = compact('request', 'result');
            }
        };

        $rf = new RequestForwarder('https://httpbin.org/get', ['timeout' => 15], $logger);
        $rf->forward();
        $this->assertCount(1, $logger->calls);
    }

    public function testQueryStringMerging(): void
    {
        $rf = new RequestForwarder('https://httpbin.org/get?a=1', [
            'query'   => ['b' => '2'],
            'timeout' => 15,
        ]);
        $resp = $rf->forward();
        $data = $resp->json();
        $this->assertSame('1', $data['args']['a'] ?? null);
        $this->assertSame('2', $data['args']['b'] ?? null);
    }
}
