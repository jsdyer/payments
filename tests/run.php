<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$checks = 0;
function check(bool $condition, string $name): void {
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
}
function rejects(Closure $fn, string $name): void {
    try { $fn(); } catch (InvalidArgumentException|JsonException $e) { check(true, $name); return; }
    check(false, $name);
}
$calls = []; $status = 200;
$transport = static function (string $url, array $headers, string $body) use (&$calls, &$status): array {
    $calls[] = compact('url', 'headers', 'body');
    $auth = $url === Client::TOKEN_URL;
    return ['status' => $auth ? 200 : $status, 'headers' => ['HTTP/2 ' . ($auth ? 200 : $status)], 'duration_ms' => 12.3,
        'body' => $auth ? '{"access_token":"test-token-secret","expires_in":300}' : '{"payment":{"transaction_status":"RJCT","status_reason_information":{"reason":{"code":"AC01"}}},"echo":"test-consumer-key test-consumer-secret test-token-secret"}'];
};
$client = new Client(['consumer_key' => 'test-consumer-key', 'consumer_secret' => 'test-consumer-secret'], [], $transport);
$r = $client->send('heartbeat', '{}');
check(count($calls) === 2, 'auth before request');
check($calls[0]['body'] === 'grant_type=client_credentials&scope=client_credentials', 'OAuth form');
check($calls[1]['url'] === Client::BASE . '/inbound-heartbeat', 'fixed sandbox endpoint');
check(str_contains(implode('\n', $calls[1]['headers']), 'application/vnd.api.v1+json'), 'vendor content type');
check(!str_contains(pretty($r), 'test-consumer') && !str_contains(pretty($r), 'test-token'), 'redacted exchange');
check($r['summary']['payment.transaction_status'] === 'RJCT', 'business rejection visible despite HTTP 200');
check($r['summary']['payment.status_reason_information.reason.code'] === 'AC01', 'reason code extraction');
foreach (array_keys(Catalog::OPERATIONS) as $op) { $client->send($op, '{}'); }
check(count($calls) === 7, 'all endpoints reuse unexpired token');
rejects(fn() => $client->send('https://evil.example', '{}'), 'arbitrary URL rejected');
rejects(fn() => $client->send('payment', '{'), 'malformed JSON rejected');
rejects(fn() => $client->send('payment', '[]'), 'non-object rejected');
rejects(fn() => $client->send('payment', '{}', ['Authorization' => 'evil']), 'auth override rejected');
rejects(fn() => $client->send('payment', '{}', ['x-uetr' => "x\r\nAuthorization: evil"]), 'header injection rejected');
rejects(fn() => $client->send('payment', str_repeat(' ', 262145)), 'oversized request rejected');
check(count($calls) === 7, 'invalid requests never reach transport');
$status = 401;
$r = $client->send('heartbeat', '{}');
check(count($calls) === 8 && $client->tokenState() === [], '401 never replayed and token cleared');
check(!str_contains(pretty($r), 'test-token-secret'), '401 redaction before token clear');
$client->send('heartbeat', '{}');
check(count($calls) === 10, 'next manual request authenticates again');
foreach (Catalog::templates() as $template) {
    check(isset(Catalog::OPERATIONS[$template['operation']]), 'known template operation');
    check(json_decode($template['request'], false, 512, JSON_THROW_ON_ERROR) instanceof stdClass, 'template JSON valid: ' . $template['id']);
}
check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Client::uuid()) === 1, 'UUID v4 format');
echo "$checks checks passed (no network requests).\n";
