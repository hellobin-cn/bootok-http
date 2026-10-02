<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— ConnectionException
 |--------------------------------------------------------------------------
 |
 | 传输层失败：DNS、超时、SSL。与逻辑错误分开，便于上层差异化重试。
 |
 */

namespace BootOk\Http;

/**
 * 连接层异常（DNS/超时/SSL 等传输失败）。
 */
class ConnectionException extends \RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
