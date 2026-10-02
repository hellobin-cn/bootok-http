<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— Response 响应包装
 |--------------------------------------------------------------------------
 |
 | 一个好的开始，从一个干净的响应对象开始。
 | 底座直接持有 Symfony HttpClient 的 ResponseInterface，不背 guzzle/psr7。
 | API 与 easyhttp 的 Response 完全兼容：body()/array()/json()/status() + ArrayAccess。
 |
 */

namespace BootOk\Http;

/**
 * 响应包装器，兼容 easyhttp 的 Response API。
 * 底座直接持有 Symfony HttpClient 的 ResponseInterface，不再依赖 guzzle/psr7。
 */
class Response implements \ArrayAccess
{
    /**
     * Symfony 响应对象（Symfony\Contracts\HttpClient\ResponseInterface）。
     */
    protected object $response;

    /**
     * 响应头（标题大小写规范化的 name => string[]）。
     */
    protected array $headers;

    /**
     * 响应体。
     */
    protected string $content;

    /**
     * 缓存的 JSON 解码结果（关联数组）。
     */
    protected mixed $decodedArray = null;

    /**
     * 缓存的 JSON 解码结果（对象）。
     */
    protected mixed $decodedObject = null;

    public function __construct(object $response, array $headers = [], string $content = '')
    {
        $this->response = $response;
        $this->headers = $headers ?: self::normalizeHeaders($response->getHeaders(false));
        try {
            $this->content = $content !== '' ? $content : $response->getContent(false);
        } catch (\Throwable) {
            $this->content = '';
        }
    }

    /**
     * 响应体。
     */
    public function body(): string
    {
        return $this->content;
    }

    /**
     * JSON 解码（关联数组），解码失败返回 null。
     */
    public function array(): mixed
    {
        if ($this->decodedArray === null) {
            $data = json_decode($this->content, true);
            $this->decodedArray = is_array($data) ? $data : null;
        }

        return $this->decodedArray;
    }

    /**
     * JSON 解码（对象），解码失败返回 null。
     */
    public function json(): mixed
    {
        if ($this->decodedObject === null) {
            $data = json_decode($this->content);
            $this->decodedObject = $data;
        }

        return $this->decodedObject;
    }

    /**
     * 取单个响应头（多个值逗号连接），不存在返回空串。
     */
    public function header(string $header): string
    {
        foreach ($this->headers as $name => $values) {
            if (strcasecmp($name, $header) === 0) {
                return implode(', ', (array) $values);
            }
        }

        return '';
    }

    /**
     * 全部响应头（标题大小写规范化的 name => string[]）。
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 状态码。
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function ok(): bool
    {
        return $this->status() === 200;
    }

    public function redirect(): bool
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->array()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->array()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Response is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Response is immutable');
    }

    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * Symfony 返回的小写头名规范化为标题大小写，兼容 Guzzle 行为。
     */
    public static function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            $result[ucwords(strtolower($name), '-')] = array_values((array) $values);
        }

        return $result;
    }
}
