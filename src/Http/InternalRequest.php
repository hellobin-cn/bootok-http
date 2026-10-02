<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— InternalRequest / RawResponse
 |--------------------------------------------------------------------------
 |
 | 消掉 guzzle/psr7 后的轻量替代值对象：
 | InternalRequest 承载中间件链上流转的请求；RawResponse 承载已完成响应。
 | 它们不是 PSR-7，也不打算成为——保持最小，保持私有。
 |
 */

namespace BootOk\Http;

/**
 * 内部请求值对象（中间件桥接用）。
 */
class InternalRequest
{
    /**
     * @param string               $method
     * @param string               $uri
     * @param array<string, mixed> $headers
     * @param string               $body
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers = [],
        public string $body = ''
    ) {
    }
}

/**
 * 原始响应值对象（execute 完成后交 Response 包装）。
 */
class RawResponse
{
    /**
     * @param int                  $status
     * @param array<string, mixed> $headers
     * @param string               $content
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $content = ''
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        return $this->content;
    }
}
