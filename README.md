# bootok/http

基于 Symfony HttpClient 的现代 PHP HTTP 客户端，PHP 8.2+，完全兼容 easyhttp 门面 API，零 guzzle 依赖。

> 一个好的开始，是每一次请求都看得懂底层。

## 特性

- **Laravel 风格门面 API**：`Http::get()` / `asJson()` / `withHeaders()` / `retry()` / `sink()` 链式调用
- **easyhttp 兼容**：门面方法与行为逐一对齐 yzh52521/easyhttp，老项目平滑替换
- **Symfony 引擎**：传输层由 `symfony/http-client` 驱动，响应直接持有 `ResponseInterface`，不背 guzzle/psr7
- **轻量值对象**：Request/Response 为零依赖的轻量实现（guzzle/psr7 已完全移除）
- **PHP 8.2+**：为高版本 PHP 设计，无历史包袱

## 环境要求

- PHP >= 8.2
- symfony/http-client ^7.1|^8.0
- symfony/mime ^7.1|^8.0

## 安装

```bash
composer require bootok/http
```

## 快速开始

```php
use BootOk\Http\Http;

// GET
$response = Http::get('https://httpbin.org/get');
if ($response->ok()) {
    $data = $response->json();
}

// JSON POST
$response = Http::asJson()->post('https://httpbin.org/post', [
    'name' => 'bootok',
]);

// 请求头 / Token / 超时 / 重试
$response = Http::withHeaders(['X-Custom' => 'v'])
    ->withToken('your-token')
    ->timeout(5)
    ->retry(2, 100)
    ->post('https://httpbin.org/post', ['k' => 'v']);

// 表单 / 文件下载
Http::asForm()->post('https://httpbin.org/post', ['a' => 1]);
Http::timeout(30)->sink('/tmp/file.zip')->get('https://example.com/file.zip');

// 响应判断
$response->successful();   // 2xx
$response->clientError();  // 4xx
$response->serverError();  // 5xx
echo $response->status();
echo $response->header('Content-Type');
(string) $response;        // 原始报文 body
```

## 门面方法

请求形态：`asJson` / `asForm` / `asMultipart` / `attach`
请求修饰：`withHeaders` / `withBody` / `withToken` / `withBasicAuth` / `withDigestAuth` / `withCookies` / `withUA` / `withCert` / `withProxy` / `withVersion` / `withHost` / `withOptions` / `withMiddleware`
传输控制：`timeout` / `connectTimeout` / `retry` / `delay` / `maxRedirects` / `withRedirect` / `withVerify` / `withStream` / `sink` / `removeBodyFormat` / `debug` / `concurrency`
请求动词：`get` / `post` / `patch` / `put` / `delete` / `head` / `options`
异步并发：`getAsync` / `postAsync` … `multiAsync` / `wait`

## 为什么重写 easyhttp

[yzh52521/easyhttp](https://github.com/yzh52521/easy-http) 在高版本 PHP 下存在依赖兼容问题（guzzle 传递依赖链）。本包保留其门面 API 的使用习惯，把引擎整体替换为 Symfony HttpClient，响应值对象轻量化自持——迁移成本接近零，依赖面干净可控。

## License

[MIT](LICENSE)
