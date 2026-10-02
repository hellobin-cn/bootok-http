<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk —— Http 门面
 |--------------------------------------------------------------------------
 |
 | BootOk HTTP 客户端入口。API 与 yzh52521/easyhttp 完全兼容，
 | 底层由 Symfony HttpClient 驱动，面向 PHP 8.2+ 的现代替代。
 |
 |     use BootOk\Http\Http;
 |
 |     Http::get('https://api.example.com/users');
 |     Http::asJson()->post('https://api.example.com/users', ['name' => 'foo']);
 |     Http::withHeaders(['X-Token' => 'abc'])->get('https://api.example.com/me');
 |     Http::timeout(5)->retry(2, 100)->sink('/tmp/a.zip')->get('https://x/file');
 |
 | 一个好的开始（Boot Ok），从一个顺手的 HTTP 客户端开始。
 |
 */

namespace BootOk;

/**
 * @method static PendingRequest asJson()
 * @method static PendingRequest asForm()
 * @method static PendingRequest asMultipart(string $name, string $contents, string|null $filename = null, array $headers = [])
 * @method static PendingRequest attach(string $name, string $contents, string|null $filename = null, array $headers = [])
 *
 * @method static PendingRequest withRedirect(bool|array $redirect)
 * @method static PendingRequest withStream(bool $boolean)
 * @method static PendingRequest withVerify(bool|string $verify)
 * @method static PendingRequest withHost(string $host)
 * @method static PendingRequest withHeaders(array $headers)
 * @method static PendingRequest withBody($content, string $contentType = 'application/json')
 * @method static PendingRequest withBasicAuth(string $username, string $password)
 * @method static PendingRequest withDigestAuth(string $username, string $password)
 * @method static PendingRequest withUA(string $ua)
 * @method static PendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static PendingRequest withCookies(array $cookies, string $domain)
 * @method static PendingRequest withProxy(string|array $proxy)
 * @method static PendingRequest withVersion(string $version)
 * @method static PendingRequest withCert(string $path, string $password)
 * @method static PendingRequest withOptions(array $options)
 * @method static PendingRequest withMiddleware(callable $middleware)
 * @method static PendingRequest withRequestMiddleware(callable $middleware)
 * @method static PendingRequest withResponseMiddleware(callable $middleware)
 *
 * @method static PendingRequest debug(mixed $class)
 * @method static PendingRequest retry(int $retries = 1, int $sleep = 0)
 * @method static PendingRequest delay(int $seconds)
 * @method static PendingRequest timeout(float $seconds)
 * @method static PendingRequest connectTimeout(float $seconds)
 * @method static PendingRequest sink(string|resource $to)
 * @method static PendingRequest concurrency(int $times)
 * @method static PendingRequest removeBodyFormat()
 * @method static PendingRequest maxRedirects(int $max)
 *
 * @method static Http\Response get(string $url, array $query = [])
 * @method static Http\Response post(string $url, array $data = [])
 * @method static Http\Response patch(string $url, array $data = [])
 * @method static Http\Response put(string $url, array $data = [])
 * @method static Http\Response delete(string $url, array $data = [])
 * @method static Http\Response head(string $url, array $data = [])
 * @method static Http\Response options(string $url, array $data = [])
 * @method static Http\Response client(string $method, string $url, array $options = [])
 * @method static Http\Promise clientAsync(string $method, string $url, array $options = [])
 *
 * @method static Http\Promise getAsync(string $url, array|null $query = null, callable $success = null, callable $fail = null)
 * @method static Http\Promise postAsync(string $url, array|null $data = null, callable $success = null, callable $fail = null)
 * @method static Http\Promise patchAsync(string $url, array|null $data = null, callable $success = null, callable $fail = null)
 * @method static Http\Promise putAsync(string $url, array|null $data = null, callable $success = null, callable $fail = null)
 * @method static Http\Promise deleteAsync(string $url, array|null $data = null, callable $success = null, callable $fail = null)
 * @method static Http\Promise headAsync(string $url, array|null $data = null, callable $success = null, callable $fail = null)
 * @method static Http\Promise optionsAsync(string $url, array|null $data = null, callable $success = null, callable $fail = null)
 * @method static Http\Pool multiAsync(array $promises, callable $success = null, callable $fail = null)
 * @method static void wait()
 */
class Http
{
    protected Http\PendingRequest $request;

    public function __construct()
    {
        $this->request = new Http\PendingRequest();
    }

    public function __call(string $name, array $params): mixed
    {
        $this->request->removeOptions();

        return $this->request->{$name}(...$params);
    }

    public static function __callStatic(string $name, array $params): mixed
    {
        return (new static())->{$name}(...$params);
    }
}
