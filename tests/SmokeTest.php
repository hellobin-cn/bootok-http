<?php

declare(strict_types=1);

namespace BootOk\Tests;

use BootOk\Http\Http;
use BootOk\Http\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * 冒烟测试：真实网络请求（需要外网；离线环境跳过）
 */
class SmokeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $online = @file_get_contents('https://httpbin.org/get', false, stream_context_create(['http' => ['timeout' => 3]]));
        if ($online === false) {
            self::markTestSkipped('需要外网环境');
        }
    }

    public function testGetWithQuery(): void
    {
        $res = Http::get('https://httpbin.org/get', ['q' => 'bootok']);
        $this->assertTrue($res->successful());
        $data = $res->array();
        $this->assertSame('bootok', $data['args']['q']);
        $this->assertNotEmpty($res->header('Content-Type'));
    }

    public function testPostJson(): void
    {
        $res = Http::asJson()->post('https://httpbin.org/post', ['name' => 'BootOk']);
        $this->assertTrue($res->successful());
        $data = $res->array();
        $this->assertSame('BootOk', $data['json']['name']);
    }

    public function testWithHeadersAndToken(): void
    {
        $res = Http::withToken('test-token')->withHeaders(['X-Custom' => 'abc'])->get('https://httpbin.org/get');
        $data = $res->array();
        $this->assertSame('Bearer test-token', $data['headers']['Authorization'] ?? $data['headers']['authorization'] ?? null);
        $this->assertSame('abc', $data['headers']['X-Custom'] ?? $data['headers']['x-custom'] ?? null);
    }

    public function testArrayAccessAndToString(): void
    {
        $res = Http::get('https://httpbin.org/get');
        $this->assertArrayHasKey('url', $res);
        $this->assertIsString((string) $res);
    }

    public function testTimeoutAndConnectionException(): void
    {
        $this->expectException(ConnectionException::class);
        Http::timeout(1)->connectTimeout(1)->get('https://10.255.255.1/delay');
    }

    public function testAsync(): void
    {
        $result = null;
        $promise = Http::getAsync('https://httpbin.org/get', ['async' => '1'], function ($res) use (&$result) {
            $result = $res->array();
        });
        $promise->wait();
        $this->assertSame('1', $result['args']['async'] ?? null);
    }

    public function testRetryOnServerError(): void
    {
        // httpbin status 端点：/status/500 返回 500；retry 后最终仍 500（幂等验证重试机制走通）
        $res = Http::retry(2, 50)->get('https://httpbin.org/status/500');
        $this->assertTrue($res->serverError());
    }
}
