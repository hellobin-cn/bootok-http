<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | BootOk\Http —— PendingRequest 请求构建器
 |--------------------------------------------------------------------------
 |
 | 整个包的引擎室。
 | 对外：easyhttp 风格的链式 API（asJson/withToken/retry/sink...），
 | 对内：Symfony HttpClient 驱动，guzzle/psr7 已被完全移除——
 |      请求桥接用轻量的 InternalRequest，结果以 RawResponse 承载。
 |
 | 一个好的开始，就是每个依赖都名正言顺。
 |
 */

namespace BootOk\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * 请求构建器：底层使用 Symfony HttpClient。
 */
class PendingRequest
{
    protected HttpClientInterface $client;

    /**
     * Body 格式：form_params | json | multipart | body
     */
    protected string $bodyFormat;

    /**
     * 原始请求体。
     */
    protected mixed $pendingBody = null;

    protected bool $isRemoveBodyFormat = false;

    /**
     * 累积的 Guzzle 风格请求选项。
     */
    protected array $options = [];

    /**
     * 待 wait() 刷新的异步 Promise。
     *
     * @var Promise[]
     */
    protected array $promises = [];

    /**
     * 并发次数（保留兼容）。
     */
    protected ?int $concurrency = null;

    /**
     * attach()/asMultipart() 注册的 multipart 片段。
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $multipartParts = [];

    /**
     * 原始 handler 中间件。
     *
     * @var callable[]
     */
    protected array $middleware = [];

    /**
     * 请求中间件。
     *
     * @var callable[]
     */
    protected array $requestMiddleware = [];

    /**
     * 响应中间件。
     *
     * @var callable[]
     */
    protected array $responseMiddleware = [];

    /**
     * 重试次数。
     */
    protected int $retries = 0;

    /**
     * 重试间隔（毫秒）。
     */
    protected int $retrySleep = 0;

    /**
     * debug 日志类。
     */
    protected mixed $debugClass = null;

    /**
     * 常用状态码原因短语。
     *
     * @var array<int, string>
     */
    protected static array $reasonPhrases = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 410 => 'Gone',
        422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    public function __construct()
    {
        $this->client     = HttpClient::create();
        $this->bodyFormat = 'form_params';
        // 与 easyhttp 默认一致：http_errors=false 且默认跳过 SSL 校验（可通过 withVerify() 覆盖）
        $this->options    = ['http_errors' => false, 'verify' => false];
    }

    /**
     * 获取底层的 Symfony HttpClient 实例。
     */
    public function getInstance(): HttpClientInterface
    {
        return $this->client;
    }

    /**
     * 重置请求状态。
     */
    public function removeOptions(): static
    {
        $this->bodyFormat         = 'form_params';
        $this->isRemoveBodyFormat = false;
        $this->pendingBody        = null;
        $this->multipartParts     = [];
        $this->middleware         = [];
        $this->requestMiddleware  = [];
        $this->responseMiddleware = [];
        $this->retries            = 0;
        $this->retrySleep         = 0;
        $this->debugClass         = null;
        $this->concurrency        = null;
        // 与 easyhttp 默认一致：http_errors=false 且默认跳过 SSL 校验（可通过 withVerify() 覆盖）
        $this->options            = ['http_errors' => false, 'verify' => false];

        return $this;
    }

    public function asForm(): static
    {
        $this->bodyFormat = 'form_params';
        $this->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);

        return $this;
    }

    public function asJson(): static
    {
        $this->bodyFormat = 'json';
        $this->withHeaders(['Content-Type' => 'application/json']);

        return $this;
    }

    public function asMultipart(string $name, string $contents, ?string $filename = null, array $headers = []): static
    {
        $this->bodyFormat = 'multipart';
        $this->addMultipartPart($name, $contents, $filename, $headers);

        return $this;
    }

    public function attach(string $name, string $contents, ?string $filename = null, array $headers = []): static
    {
        $this->bodyFormat = 'multipart';
        $this->addMultipartPart($name, $contents, $filename, $headers);

        return $this;
    }

    protected function addMultipartPart(string $name, string $contents, ?string $filename, array $headers): void
    {
        $this->multipartParts[] = array_filter([
            'name'     => $name,
            'contents' => $contents,
            'headers'  => $headers,
            'filename' => $filename,
        ]);
    }

    public function withMiddleware(callable $middleware): static
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function withRequestMiddleware(callable $middleware): static
    {
        $this->requestMiddleware[] = $middleware;

        return $this;
    }

    public function withResponseMiddleware(callable $middleware): static
    {
        $this->responseMiddleware[] = $middleware;

        return $this;
    }

    public function withHost(string $host): static
    {
        $this->options['base_uri'] = $host;

        return $this;
    }

    public function withOptions(array $options): static
    {
        unset($this->options[$this->bodyFormat], $this->options['body']);

        $this->options = array_merge_recursive($this->options, $options);

        return $this;
    }

    public function withCert(string $path, string $password): static
    {
        $this->options['cert'] = [$path, $password];

        return $this;
    }

    public function withHeaders(array $headers): static
    {
        $this->options = array_merge_recursive($this->options, [
            'headers' => $headers,
        ]);

        return $this;
    }

    public function withBody(mixed $content, string $contentType = 'application/json'): static
    {
        $this->bodyFormat = 'body';

        $this->options['headers']['Content-Type'] = $contentType;

        $this->pendingBody = $content;

        return $this;
    }

    public function withBasicAuth(string $username, string $password): static
    {
        $this->options['auth'] = [$username, $password];

        return $this;
    }

    public function withDigestAuth(string $username, string $password): static
    {
        $this->options['auth'] = [$username, $password, 'digest'];

        return $this;
    }

    public function withUA(string $ua): static
    {
        $this->options['headers']['User-Agent'] = trim($ua);

        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->options['headers']['Authorization'] = trim($type . ' ' . $token);

        return $this;
    }

    public function withCookies(array $cookies, string $domain): static
    {
        $this->options['cookies'] = $cookies;

        return $this;
    }

    public function withProxy(string|array $proxy): static
    {
        $this->options['proxy'] = $proxy;

        return $this;
    }

    public function withVersion(string $version): static
    {
        $this->options['version'] = $version;

        return $this;
    }

    public function maxRedirects(int $max): static
    {
        $this->options['allow_redirects'] = ['max' => $max];

        return $this;
    }

    public function withRedirect(bool|array $redirect = false): static
    {
        $this->options['allow_redirects'] = $redirect;

        return $this;
    }

    public function withVerify(bool|string $verify = false): static
    {
        $this->options['verify'] = $verify;

        return $this;
    }

    public function withStream(bool $boolean = false): static
    {
        $this->options['stream'] = $boolean;

        return $this;
    }

    public function concurrency(int $times): static
    {
        $this->concurrency = $times;

        return $this;
    }

    public function retry(int $retries = 1, int $sleep = 0): static
    {
        $this->retries    = $retries;
        $this->retrySleep = $sleep;

        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->options['delay'] = $seconds * 1000;

        return $this;
    }

    public function timeout(float $seconds): static
    {
        $this->options['timeout'] = $seconds;

        return $this;
    }

    public function connectTimeout(float $seconds): static
    {
        $this->options['connect_timeout'] = $seconds;

        return $this;
    }

    /**
     * @param string|resource $to
     */
    public function sink(mixed $to): static
    {
        $this->options['sink'] = $to;

        return $this;
    }

    public function removeBodyFormat(): static
    {
        $this->isRemoveBodyFormat = true;

        return $this;
    }

    public function debug(mixed $class): static
    {
        $this->debugClass = $class;

        return $this;
    }

    public function get(string $url, array $query = []): Response
    {
        $guzzle          = $this->options;
        $guzzle['query'] = array_merge($this->queryFromUrl($url), $query);

        return $this->dispatch('GET', $url, $guzzle, $query);
    }

    public function post(string $url, array $data = []): Response
    {
        return $this->dispatch('POST', $url, $this->options, $data);
    }

    public function patch(string $url, array $data = []): Response
    {
        return $this->dispatch('PATCH', $url, $this->options, $data);
    }

    public function put(string $url, array $data = []): Response
    {
        return $this->dispatch('PUT', $url, $this->options, $data);
    }

    public function delete(string $url, array $data = []): Response
    {
        return $this->dispatch('DELETE', $url, $this->options, $data);
    }

    public function head(string $url, array $data = []): Response
    {
        return $this->dispatch('HEAD', $url, $this->options, $data);
    }

    public function options(string $url, array $data = []): Response
    {
        return $this->dispatch('OPTIONS', $url, $this->options, $data);
    }

    public function getAsync(string $url, ?array $query = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        $guzzle = $this->options;
        if ($query !== null) {
            $guzzle['query'] = array_merge($this->queryFromUrl($url), $query);
        }

        return $this->requestAsync('GET', $url, $guzzle, $query, $success, $fail);
    }

    public function postAsync(string $url, ?array $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        return $this->async('POST', $url, $data, $success, $fail);
    }

    public function patchAsync(string $url, ?array $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        return $this->async('PATCH', $url, $data, $success, $fail);
    }

    public function putAsync(string $url, ?array $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        return $this->async('PUT', $url, $data, $success, $fail);
    }

    public function deleteAsync(string $url, ?array $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        return $this->async('DELETE', $url, $data, $success, $fail);
    }

    public function headAsync(string $url, ?array $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        return $this->async('HEAD', $url, $data, $success, $fail);
    }

    public function optionsAsync(string $url, ?array $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        return $this->async('OPTIONS', $url, $data, $success, $fail);
    }

    /**
     * 原生请求。
     *
     * @param array $options Guzzle 风格原始选项
     *
     * @throws ConnectionException
     */
    public function client(string $method, string $url, array $options = []): Response
    {
        $method = strtoupper($method);

        if (empty($options)) {
            $guzzle = $this->applyBody($method, $this->options, null);
        } else {
            $guzzle = $this->resolveBody($method, $options);
        }

        try {
            return $this->response($this->send($method, $url, $guzzle));
        } catch (TransportExceptionInterface $e) {
            throw new ConnectionException($e->getMessage(), $e);
        }
    }

    /**
     * 原生异步请求。
     *
     * @param array $options Guzzle 风格原始选项
     */
    public function clientAsync(string $method, string $url, array $options = []): Promise
    {
        $method = strtoupper($method);

        if (empty($options)) {
            $guzzle = $this->applyBody($method, $this->options, null);
        } else {
            $guzzle = $this->resolveBody($method, $options);
        }

        return $this->requestAsync($method, $url, $guzzle, null, null, null);
    }

    /**
     * 并发发送多个 Promise。
     *
     * @param Promise[]     $promises
     * @param callable|null $success
     * @param callable|null $fail
     */
    public function multiAsync(array $promises, ?callable $success = null, ?callable $fail = null): Pool
    {
        $pool = new Pool($promises, $success, $fail, $this->concurrency ?? 0);
        $this->promises[] = $pool->promise();

        return $pool;
    }

    /**
     * 等待所有异步请求完成。
     */
    public function wait(): void
    {
        if (!empty($this->promises)) {
            foreach ($this->promises as $promise) {
                $promise->wait(false);
            }
        }
        $this->promises = [];
    }

    /**
     * 同步请求分发。
     *
     * @param mixed $data
     */
    protected function dispatch(string $method, string $url, array $guzzle, mixed $data): Response
    {
        $method = strtoupper($method);
        $guzzle = $this->applyBody($method, $guzzle, $data);

        try {
            return $this->response($this->send($method, $url, $guzzle));
        } catch (TransportExceptionInterface $e) {
            throw new ConnectionException($e->getMessage(), $e);
        }
    }

    /**
     * 异步请求分发。
     *
     * @param array|null    $data
     * @param callable|null $success
     * @param callable|null $fail
     */
    protected function async(string $method, string $url, ?array $data, ?callable $success = null, ?callable $fail = null): Promise
    {
        $guzzle = $this->options;

        return $this->requestAsync($method, $url, $guzzle, $data, $success, $fail);
    }

    /**
     * 组装请求并返回 Promise。
     *
     * @param mixed         $data
     * @param callable|null $success
     * @param callable|null $fail
     */
    protected function requestAsync(string $method, string $url, array $guzzle, mixed $data = null, ?callable $success = null, ?callable $fail = null): Promise
    {
        $method = strtoupper($method);
        $guzzle = $this->applyBody($method, $guzzle, $data);

        $promise = $this->composeSender()(
            $this->buildInternalRequest($method, $url, $guzzle),
            $guzzle
        );

        $fulfilled = function ($response) use ($success): Response {
            $response = $this->response($response);
            if ($success !== null) {
                $success($response);
            }

            return $response;
        };

        $rejected = function ($exception) use ($fail): void {
            if ($fail !== null) {
                $fail($this->exception($exception));
            }
        };

        $promise->then($fulfilled, $rejected);

        $this->promises[] = $promise;

        return $promise;
    }

    /**
     * 发送请求并返回 RawResponse。
     */
    protected function send(string $method, string $url, array $guzzle): RawResponse
    {
        $internalRequest = $this->buildInternalRequest($method, $url, $guzzle);
        $promise         = $this->composeSender()($internalRequest, $guzzle);

        return $promise->wait();
    }

    /**
     * 组装中间件发送链。
     */
    protected function composeSender(): callable
    {
        $base = function (InternalRequest $request, array $options): Promise {
            return $this->execute($request, $options);
        };

        $sender = $base;
        foreach ($this->middleware as $middleware) {
            $sender = $middleware($sender);
        }
        foreach ($this->requestMiddleware as $middleware) {
            $sender = $middleware($sender);
        }
        foreach ($this->responseMiddleware as $middleware) {
            $sender = $middleware($sender);
        }

        return $sender;
    }

    /**
     * 实际执行 Symfony 请求，返回 Promise。
     */
    protected function execute(InternalRequest $request, array $options): Promise
    {
        return new Promise(function (Promise $promise) use ($request, $options): void {
            $attempts   = 0;
            $maxRetries = $this->retries;
            $sleep      = $this->retrySleep;

            while (true) {
                $attempts++;
                $symfonyOptions = [];
                try {
                    $symfonyOptions = $this->translateMisc($options);

                    // 请求头取自（可能被中间件修改的）内部请求
                    $headers = $request->headers;
                    if (!empty($symfonyOptions['headers'])) {
                        $headers = array_merge($headers, $symfonyOptions['headers']);
                    }
                    if ($headers) {
                        $symfonyOptions['headers'] = $headers;
                    }

                    // 请求体
                    $content = $request->body;
                    if ($content !== '' && !in_array($request->method, ['GET', 'HEAD'], true)) {
                        $symfonyOptions['body'] = $content;
                    }

                    // 延时
                    if (isset($options['delay']) && (float) $options['delay'] > 0) {
                        usleep((int) ((float) $options['delay'] * 1000));
                    }

                    $response = $this->client->request($request->method, $request->uri, $symfonyOptions);

                    $status = $response->getStatusCode();
                } catch (HttpExceptionInterface $e) {
                    // 重定向次数耗尽等场景：取回原始响应
                    $response = $e->getResponse();
                    $status   = $response->getStatusCode();
                } catch (TransportExceptionInterface $e) {
                    if ($attempts <= $maxRetries) {
                        $this->sleepRetry($sleep, $attempts);
                        continue;
                    }
                    $promise->reject(new ConnectionException($e->getMessage(), $e));

                    return;
                } catch (\Throwable $e) {
                    $promise->reject($e);

                    return;
                }

                // 读取响应头与响应体（兼容 http_errors=false，不抛异常）
                try {
                    $headers = Response::normalizeHeaders($response->getHeaders(false));
                    $content = (string) $response->getContent(false);
                } catch (\Throwable) {
                    $headers = [];
                    $content = '';
                }

                if ($status >= 500 && $attempts <= $maxRetries) {
                    $this->sleepRetry($sleep, $attempts);
                    continue;
                }

                // sink
                if (!empty($options['sink'])) {
                    $this->writeSink($options['sink'], $content);
                }

                if ($this->debugClass) {
                    $this->debugLog($symfonyOptions, $request->method, $request->uri, $headers, $status, $content);
                }

                $promise->resolve(new RawResponse($status, $headers, $content));

                return;
            }
        });
    }

    protected function sleepRetry(int $sleepMs, int $attempt): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * $attempt * 1000);
        }
    }

    protected function writeSink(mixed $sink, string $content): void
    {
        if (is_resource($sink)) {
            fwrite($sink, $content);
        } else {
            file_put_contents($sink, $content);
        }
    }

    /**
     * 将 Guzzle 风格选项翻译为 Symfony 选项。
     */
    protected function translateMisc(array $guzzle): array
    {
        $symfony = [];

        if (isset($guzzle['auth']) && is_array($guzzle['auth'])) {
            $auth = $guzzle['auth'];
            $type = strtolower((string) ($auth[2] ?? 'basic'));
            if ($type === 'digest') {
                $symfony['auth_basic'] = [$auth[0], $auth[1]];
                if (defined('CURLOPT_HTTPAUTH') && defined('CURLAUTH_DIGEST')) {
                    $symfony['extra']['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                }
            } else {
                $symfony['auth_basic'] = [$auth[0], $auth[1]];
            }
        }

        if (array_key_exists('verify', $guzzle)) {
            $verify = $guzzle['verify'];
            if (is_string($verify) && $verify !== '' && !in_array($verify, ['false', '0'], true)) {
                $symfony['cafile']      = $verify;
                $symfony['verify_peer'] = true;
                $symfony['verify_host'] = true;
            } else {
                $v = (bool) $verify;
                $symfony['verify_peer'] = $v;
                $symfony['verify_host'] = $v;
            }
        }

        if (array_key_exists('allow_redirects', $guzzle)) {
            $redirect = $guzzle['allow_redirects'];
            if ($redirect === false) {
                $symfony['max_redirects'] = 0;
            } elseif (is_array($redirect)) {
                $symfony['max_redirects'] = (int) ($redirect['max'] ?? 20);
            } else {
                $symfony['max_redirects'] = 20;
            }
        }

        if (isset($guzzle['proxy'])) {
            $proxy = $guzzle['proxy'];
            if (is_array($proxy)) {
                $proxy = $proxy['https'] ?? $proxy['http'] ?? reset($proxy);
            }
            if ($proxy) {
                $symfony['proxy'] = $proxy;
            }
        }

        if (isset($guzzle['version'])) {
            $symfony['http_version'] = (string) $guzzle['version'];
        }

        if (isset($guzzle['cert'])) {
            $cert = $guzzle['cert'];
            $symfony['local_cert'] = is_array($cert) ? $cert[0] : $cert;
            if (is_array($cert) && isset($cert[1]) && $cert[1] !== '') {
                $symfony['passphrase'] = $cert[1];
            }
        }

        if (isset($guzzle['ssl_key'])) {
            $symfony['local_pk'] = is_array($guzzle['ssl_key']) ? $guzzle['ssl_key'][0] : $guzzle['ssl_key'];
        }

        if (isset($guzzle['timeout'])) {
            $symfony['timeout'] = (float) $guzzle['timeout'];
        }

        if (isset($guzzle['connect_timeout'])) {
            $symfony['max_connect_duration'] = (float) $guzzle['connect_timeout'];
        }

        if (!empty($guzzle['stream'])) {
            $symfony['buffer'] = false;
        }

        if (isset($guzzle['cookies'])) {
            $symfony['headers']['Cookie'] = $this->cookiesToHeader($guzzle['cookies']);
        }

        return $symfony;
    }

    /**
     * Cookie 数组转请求头（会话级 cookie 由调用方管理，符合 90% 场景）。
     */
    protected function cookiesToHeader(mixed $cookies): string
    {
        $header = [];
        foreach ((array) $cookies as $name => $value) {
            $header[] = $name . '=' . $value;
        }

        return implode('; ', $header);
    }

    /**
     * 构建内部请求对象（中间件桥接用，替代 guzzle/psr7 Request）。
     */
    protected function buildInternalRequest(string $method, string $url, array $guzzle): InternalRequest
    {
        $uri = $url;

        $baseUri = $guzzle['base_uri'] ?? null;
        if ($baseUri && !preg_match('#^[a-z][a-z0-9+.\-]*://#i', $uri)) {
            $uri = rtrim((string) $baseUri, '/') . '/' . ltrim($uri, '/');
        }

        if (!empty($guzzle['query'])) {
            $pos  = strpos($uri, '?');
            $path = $pos !== false ? substr($uri, 0, $pos) : $uri;
            $uri  = $path . '?' . http_build_query($guzzle['query']);
        }

        $headers = [];
        foreach ($guzzle['headers'] ?? [] as $name => $value) {
            $headers[$name] = is_array($value) ? array_values($value) : $value;
        }

        $body = '';
        if (array_key_exists('body', $guzzle) && $guzzle['body'] !== '') {
            $b = $guzzle['body'];
            $body = is_resource($b)
                ? (string) stream_get_contents($b)
                : (is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE) : (string) $b);
        }

        return new InternalRequest($method, $uri, $headers, $body);
    }

    /**
     * 根据 bodyFormat 将请求数据固化为具体的 body / query。
     *
     * @param mixed $data
     */
    protected function applyBody(string $method, array $guzzle, mixed $data): array
    {
        if ($this->isRemoveBodyFormat) {
            unset($guzzle[$this->bodyFormat], $guzzle['body']);

            return $guzzle;
        }

        if ($this->bodyFormat === 'body') {
            if ($this->pendingBody !== null) {
                $guzzle['body'] = $this->pendingBody;
            } elseif ($data) {
                $guzzle['body'] = $data;
            }
        } elseif ($data !== null) {
            $guzzle[$this->bodyFormat] = $data;
        }

        return $this->resolveBody($method, $guzzle);
    }

    /**
     * 将 form_params/json/multipart 解析为具体 body（Guzzle 兼容行为）。
     */
    protected function resolveBody(string $method, array $guzzle): array
    {
        $method = strtoupper($method);

        if (isset($guzzle['json'])) {
            $guzzle['body'] = json_encode($guzzle['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (empty($guzzle['headers']['Content-Type'])) {
                $guzzle['headers']['Content-Type'] = 'application/json';
            }
            unset($guzzle['json']);
        } elseif (isset($guzzle['multipart']) || !empty($this->multipartParts)) {
            $data = $guzzle['multipart'] ?? [];
            unset($guzzle['multipart']);
            $form                              = $this->buildMultipartBody(is_array($data) ? $data : []);
            $guzzle['body']                    = $form['body'];
            $guzzle['headers']['Content-Type'] = $form['contentType'];
        } elseif (isset($guzzle['form_params'])) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                $guzzle['query'] = array_merge($guzzle['query'] ?? [], $guzzle['form_params']);
            } else {
                $guzzle['body'] = http_build_query($guzzle['form_params']);
                if (empty($guzzle['headers']['Content-Type'])) {
                    $guzzle['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                }
            }
            unset($guzzle['form_params']);
        }

        return $guzzle;
    }

    /**
     * 构建 multipart 请求体。
     *
     * @param array $data 额外的表单字段
     *
     * @return array{body: string, contentType: string}
     */
    protected function buildMultipartBody(array $data): array
    {
        $fields = [];

        foreach ($this->multipartParts as $part) {
            $name = $part['name'];
            if (!empty($part['filename'])) {
                $dataPart = new DataPart($part['contents'], $part['filename']);
                foreach ((array) ($part['headers'] ?? []) as $headerName => $headerValue) {
                    $dataPart->getHeaders()->addTextHeader($headerName, is_array($headerValue) ? implode(', ', $headerValue) : (string) $headerValue);
                }
                $fields[$name] = $dataPart;
            } else {
                $fields[$name] = $part['contents'];
            }
        }

        foreach ($data as $name => $value) {
            if (is_scalar($value)) {
                $fields[$name] = $value;
            } elseif (is_array($value)) {
                $fields[$name] = new DataPart(json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        }

        $form        = new FormDataPart($fields);
        $contentType = $form->getPreparedHeaders()->get('Content-Type');

        return [
            'body'        => $form->bodyToString(),
            'contentType' => $contentType ? $contentType->getBodyAsString() : '',
        ];
    }

    protected function queryFromUrl(string $url): array
    {
        $params = parse_url($url, PHP_URL_QUERY);
        parse_str($params ?: '', $result);

        return $result;
    }

    protected function reasonPhrase(int $status): string
    {
        return self::$reasonPhrases[$status] ?? '';
    }

    /**
     * debug 日志输出。
     */
    protected function debugLog(array $symfonyOptions, string $method, string $url, array $responseHeaders, int $status, string $content): void
    {
        $class = $this->debugClass;
        $level = $status >= 300 ? 'notice' : 'info';

        $requestHeaders = [];
        foreach ($symfonyOptions['headers'] ?? [] as $name => $values) {
            foreach ((array) $values as $value) {
                $requestHeaders[] = $name . ': ' . $value;
            }
        }

        $responseLines = [];
        foreach ($responseHeaders as $name => $values) {
            foreach ((array) $values as $value) {
                $responseLines[] = $name . ': ' . $value;
            }
        }

        $requestBody = $symfonyOptions['body'] ?? '';
        if (is_array($requestBody)) {
            $requestBody = json_encode($requestBody);
        }

        $message = sprintf(
            "Request %s\n%s %s HTTP/1.1\r\n%s\r\n\r\n%s\r\n--------------------\r\nHTTP/%s %d %s\r\n%s\r\n\r\n%s",
            $url,
            $method,
            $url,
            implode("\r\n", $requestHeaders),
            $requestBody,
            '1.1',
            $status,
            $this->reasonPhrase($status),
            implode("\r\n", $responseLines),
            $content
        );

        if (is_object($class)) {
            $class->log($level, $message);
        } else {
            $class::log($level, $message);
        }
    }

    protected function response(object $raw): Response
    {
        if ($raw instanceof Response) {
            return $raw;
        }

        return new Response($raw);
    }

    protected function exception(\Throwable $exception): RequestException
    {
        return new RequestException($exception);
    }
}
