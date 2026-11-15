<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (!in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/', '/index.php'], true)) {
    http_response_code(404); exit('Not found.');
}
$config = config();
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$host = strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: '');
if (empty($config['app_password'])) {
    if (!$local || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403); exit('Local access only. Set RTR_APP_PASSWORD to enable protected remote access.');
    }
} elseif (!hash_equals($config['app_password'], $_SERVER['PHP_AUTH_PW'] ?? '')) {
    header('WWW-Authenticate: Basic realm="JSD Payments Sandbox"'); http_response_code(401); exit('Authentication required.');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
session_name('jsd_rtr');
$sessionDir = dirname(__DIR__) . '/var/sessions';
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    http_response_code(500); exit('Cannot create private session directory. Give PHP write access to var/.');
}
session_save_path($sessionDir);
if (!session_start(['use_strict_mode' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'])) {
    http_response_code(500); exit('Cannot start session. Check var/sessions permissions.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$client = new Client($config, $_SESSION['token'] ?? []);

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            http_response_code(403); echo '{"error":"Invalid CSRF token or method."}'; exit;
        }
        $raw = file_get_contents('php://input', false, null, 0, 300001);
        if (strlen($raw) > 300000) { throw new InvalidArgumentException('Input is too large.'); }
        $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $action = $input['action'] ?? '';
        switch ($action) {
            case 'catalog':
                $result = ['operations' => Catalog::OPERATIONS, 'templates' => Catalog::templates(), 'history' => $_SESSION['history'] ?? [], 'configured' => !empty($config['consumer_key']) && !empty($config['consumer_secret'])];
                break;
            case 'auth': $result = $client->authenticate(true); break;
            case 'send':
                $result = $client->send((string) ($input['operation'] ?? ''), (string) ($input['body'] ?? ''), $input['headers'] ?? []);
                $_SESSION['history'] = array_slice([$result, ...($_SESSION['history'] ?? [])], 0, 10);
                break;
            case 'clear': $_SESSION['history'] = []; $result = ['cleared' => true]; break;
            case 'forget': $client = new Client($config); $result = ['forgotten' => true]; break;
            default: throw new InvalidArgumentException('Unknown action.');
        }
        $_SESSION['token'] = $client->tokenState();
        echo pretty($result);
    } catch (Throwable $e) {
        $_SESSION['token'] = $client->tokenState();
        http_response_code(400); echo pretty(['error' => $client->redact($e->getMessage())]);
    }
    exit;
}
function h(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= h($_SESSION['csrf']) ?>">
<title>JSD Payments · RTR sandbox</title><link rel="stylesheet" href="app.css"><script src="app.js" defer></script></head>
<body>
<header><div><span class="eyebrow">JSD / DEVELOPER LAB</span><h1>Explore the Real-Time Rail.</h1><p>Build a request. Follow the message. See what comes back.</p></div><span class="badge">SANDBOX ONLY</span></header>
<main>
<div class="notice">This sandbox uses trigger-based responses and mocked reports. Sending a payment does not create a real transfer or update a real account balance.</div>
<section class="connection"><div><strong>JSD Payments</strong><span id="connection">Checking configuration…</span></div><div class="actions"><button id="auth">Test authentication</button><button id="forget" class="quiet">Forget token</button></div></section>
<p id="message" role="status" aria-live="polite"></p>
<nav id="operations" aria-label="API operations"></nav>
<div class="workspace">
<section class="panel editor"><div class="section-heading"><h2 id="operation-title">Request</h2><span id="message-type" class="badge"></span></div><p id="description"></p><code id="endpoint"></code>
<label for="template">Example or test scenario</label><select id="template"></select>
<details id="scenario-details"><summary>Scenario instructions and expected outcome</summary><p id="scenario-category"></p><p id="scenario-description"></p><h3>Trigger fields</h3><pre id="trigger"></pre><h3>Expected result</h3><pre id="expected"></pre><details><summary>Reference response (not a live result)</summary><pre id="reference"></pre></details></details>
<div class="actions tools"><button id="format" class="quiet">Format JSON</button><button id="download-request" class="quiet">Save request</button><label class="file-button">Load JSON<input id="upload" type="file" accept=".json,application/json"></label><button id="enquire" class="quiet">Enquire about last payment</button></div>
<label for="body">JSON request body</label><textarea id="body" spellcheck="false" rows="23"></textarea>
<details><summary>Edit individual fields</summary><p>These fields update the JSON editor. Identifiers and dates stay unchanged unless you edit them.</p><button id="fields-refresh" class="quiet">Reload fields from JSON</button><input id="field-search" type="search" placeholder="Filter by field name" aria-label="Filter fields"><div id="fields"></div></details>
<details><summary>Request headers</summary><p>Traceability ID is generated for each call if blank. The sandbox accepted our unsigned heartbeat; production signing is not implemented. You can supply a signature for your exact JSON bytes.</p><label for="trace">traceability-id</label><input id="trace" placeholder="Generate automatically"><label for="uetr">x-uetr</label><input id="uetr" placeholder="Optional UETR"><label for="signature">x-jws-signature</label><textarea id="signature" rows="2" placeholder="Optional supplied signature"></textarea></details>
<div class="send-row"><button id="send" class="primary">Send to sandbox</button><span>One request. No automatic retries.</span></div>
</section>
<section class="panel response"><div class="section-heading"><h2>Response inspector</h2><button id="download-result" class="quiet" disabled>Export exchange</button></div><div id="response-meta" class="response-meta">Your next response will appear here.</div><p class="muted">HTTP success is separate from payment acceptance. Inspect the message status and reason codes below.</p><div id="summary"></div><h3>Response body</h3><pre id="response-body" class="code-block">No live request yet.</pre><details><summary>HTTP headers and request sent</summary><pre id="response-headers"></pre><pre id="sent-request"></pre></details></section>
</div>
<section class="panel"><div class="section-heading"><h2>Session history</h2><button id="clear" class="quiet">Clear history</button></div><p class="muted">Last 10 exchanges in this browser session. Export anything you want to keep.</p><div id="history"></div></section>
<footer><p>Plain PHP + cURL. No Composer, database or JavaScript build step.</p><p><a href="https://developer.payments.ca/rtr-sandbox-api/apis" target="_blank" rel="noreferrer">Official RTR documentation</a> · <a href="https://storage.googleapis.com/prod-apigee-bucket-swagger/index.html" target="_blank" rel="noreferrer">API schemas</a></p><p>Load the full official scenario catalog with <code>php bin/import-scenarios.php</code>. Outbound participant APIs are outside this sandbox’s scope.</p></footer>
</main></body></html>
