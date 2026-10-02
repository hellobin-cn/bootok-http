<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— Promise 异步原语
 |--------------------------------------------------------------------------
 |
 | 让异步像同步一样好读：then 挂回调，wait 收结果。
 | 包装 Symfony 响应的惰性求值，失败以异常传递，绝不静默。
 |
 */

namespace BootOk\Http;

/**
 * 异步 Promise：包装 Symfony 响应的惰性求值。
 * then() 注册成功/失败回调；wait() 阻塞至完成。
 */
class Promise
{
    /** @var callable|null 完成态：fn(): object */
    protected $resolver = null;

    protected ?object $response = null;

    protected ?\Throwable $exception = null;

    /** @var callable[] */
    protected array $successCallbacks = [];

    /** @var callable[] */
    protected array $failCallbacks = [];

    protected bool $settled = false;

    /**
     * @param callable|null $resolver fn(Promise $promise): void —— 执行请求并 resolve/reject 本 promise
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public function then(?callable $success = null, ?callable $fail = null): static
    {
        if ($success) {
            $this->successCallbacks[] = $success;
        }
        if ($fail) {
            $this->failCallbacks[] = $fail;
        }
        if ($this->settled) {
            $this->settle();
        }

        return $this;
    }

    public function resolve(object $response): void
    {
        $this->response = $response;
        $this->exception = null;
        $this->settled = true;
        $this->settle();
    }

    public function reject(\Throwable $exception): void
    {
        $this->exception = $exception;
        $this->response = null;
        $this->settled = true;
        $this->settle();
    }

    /**
     * 阻塞等待完成。
     *
     * @param bool $unwrap true 时返回 RawResponse（rejected 时抛出异常，同步语义）；
     *                     false 返回 null（仅驱动回调，异步消费用）
     */
    public function wait(bool $unwrap = true): ?RawResponse
    {
        if (!$this->settled && $this->resolver !== null) {
            $resolver = $this->resolver;
            $this->resolver = null;
            $resolver($this);
        }
        $this->settle();

        if ($unwrap && $this->exception !== null) {
            throw $this->exception;
        }

        return $unwrap ? $this->response : null;
    }

    protected function settle(): void
    {
        if (!$this->settled) {
            return;
        }
        if ($this->response !== null) {
            foreach ($this->successCallbacks as $callback) {
                $callback($this->response);
            }
            $this->successCallbacks = [];
        } elseif ($this->exception !== null) {
            foreach ($this->failCallbacks as $callback) {
                $callback($this->exception);
            }
            $this->failCallbacks = [];
        }
    }
}
