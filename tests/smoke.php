<?php
require __DIR__ . '/../vendor/autoload.php';

$tests = [];
// 1. GET
$r = BootOk\Http::get('https://httpbin.org/get', ['q' => 'bootok']);
$tests['GET+query'] = $r->successful() && ($r->array()['args']['q'] ?? '') === 'bootok';
// 2. POST json
$r = BootOk\Http::asJson()->post('https://httpbin.org/post', ['name' => 'BootOk']);
$tests['POST+json'] = $r->successful() && ($r->array()['json']['name'] ?? '') === 'BootOk';
// 3. headers/token
$r = BootOk\Http::withToken('tk')->withHeaders(['X-Custom' => 'abc'])->get('https://httpbin.org/get');
$h = $r->array()['headers'];
$tests['withToken+withHeaders'] = ($h['Authorization'] ?? $h['authorization'] ?? '') === 'Bearer tk' && ($h['X-Custom'] ?? $h['x-custom'] ?? '') === 'abc';
// 4. form
$r = BootOk\Http::asForm()->post('https://httpbin.org/post', ['a' => '1', 'b' => '2']);
$tests['asForm'] = $r->successful() && ($r->array()['form']['a'] ?? '') === '1';
// 5. ArrayAccess + __toString
$r = BootOk\Http::get('https://httpbin.org/get');
$tests['ArrayAccess'] = isset($r['url']) && is_string((string)$r);
// 6. 状态码判断
$r = BootOk\Http::get('https://httpbin.org/status/404');
$tests['clientError'] = $r->clientError() && !$r->successful();
// 7. 超时异常
try {
    BootOk\Http::timeout(2)->get('https://httpbin.org/delay/10');
    $tests['timeout-exception'] = false;
} catch (BootOk\Http\ConnectionException $e) {
    $tests['timeout-exception'] = true;
}
// 8. 异步
$result = null;
BootOk\Http::getAsync('https://httpbin.org/get', ['async' => 'ok'], function ($res) use (&$result) { $result = $res->array(); })->wait();
$tests['async'] = ($result['args']['async'] ?? '') === 'ok';
// 9. sink 下载
$tmp = tempnam(sys_get_temp_dir(), 'bok');
$r = BootOk\Http::sink($tmp)->get('https://httpbin.org/bytes/1024');
$tests['sink'] = $r->successful() && filesize($tmp) > 0;
unlink($tmp);

foreach ($tests as $name => $ok) printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
echo count(array_filter($tests)), '/', count($tests), ' passed', PHP_EOL;
exit(count(array_filter($tests)) === count($tests) ? 0 : 1);
