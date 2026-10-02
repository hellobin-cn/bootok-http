<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— RequestException
 |--------------------------------------------------------------------------
 |
 | HTTP 层逻辑错误：状态语义、中间件、包装层抛出的异常基点。
 |
 */

namespace BootOk\Http;

/**
 * 请求处理异常（HTTP 层逻辑错误）。
 */
class RequestException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct($previous?->getMessage() ?? 'Http request exception', 0, $previous);
    }
}
