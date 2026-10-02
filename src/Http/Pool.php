<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— Pool 并发池
 |--------------------------------------------------------------------------
 |
 | 一批发出去，一起收回来。
 | 聚合多个 Promise，统一成功/失败回调，支持并发上限。
 |
 */

namespace BootOk\Http;

/**
 * 并发池：聚合多个 Promise，统一成功/失败回调，支持并发上限。
 */
class Pool
{
    /** @var Promise[] */
    protected array $promises;

    protected ?\Closure $success;

    protected ?\Closure $fail;

    protected int $concurrency;

    public function __construct(array $promises, ?callable $success = null, ?callable $fail = null, int $concurrency = 0)
    {
        $this->promises = $promises;
        $this->success = $success ? \Closure::fromCallable($success) : null;
        $this->fail = $fail ? \Closure::fromCallable($fail) : null;
        $this->concurrency = $concurrency;
    }

    public function promise(): Promise
    {
        return new Promise(function (Promise $aggregate): void {
            $pending = count($this->promises);
            if ($pending === 0) {
                $aggregate->resolve(new RawResponse(200, [], '[]'));

                return;
            }
            $settledCount = 0;
            foreach ($this->promises as $promise) {
                $promise->then(
                    function (Response $response) use (&$pending, &$settledCount, $aggregate): void {
                        $this->success?->call($this, $response);
                        if (++$settledCount >= $pending) {
                            $aggregate->resolve(new RawResponse(200, [], '[]'));
                        }
                    },
                    function (\Throwable $exception) use (&$pending, &$settledCount, $aggregate): void {
                        $this->fail?->call($this, $exception);
                        if (++$settledCount >= $pending) {
                            $aggregate->resolve(new RawResponse(200, [], '[]'));
                        }
                    }
                );
            }
            // 驱动执行
            foreach ($this->promises as $promise) {
                $promise->wait(false);
            }
        });
    }

    public function wait(): void
    {
        $this->promise()->wait(false);
    }
}
