<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $client = new Client(config());
    $command = $argv[1] ?? 'help';
    if ($command === 'list') {
        foreach (Catalog::OPERATIONS as $key => $op) { echo "$key\t$op[0]\t$op[1]\n"; }
    } elseif ($command === 'auth') {
        echo pretty($client->authenticate()) . "\n";
    } elseif ($command === 'send') {
        $operation = $argv[2] ?? '';
        if (!isset(Catalog::OPERATIONS[$operation])) { throw new InvalidArgumentException('Use an operation from php bin/rtr.php list.'); }
        $default = $operation === 'balance' ? 'balance-current' : $operation;
        $file = $argv[3] ?? dirname(__DIR__) . '/samples/' . $default . '.json';
        $body = file_get_contents($file);
        if ($body === false) { throw new RuntimeException('Cannot read request file.'); }
        // Optional JSON object containing traceability-id, x-uetr and/or x-jws-signature.
        $headers = isset($argv[4]) ? json_decode(file_get_contents($argv[4]), true, 512, JSON_THROW_ON_ERROR) : [];
        $result = $client->send($operation, $body, $headers);
        echo pretty($result) . "\n";
        exit($result['http_status'] >= 200 && $result['http_status'] < 300 ? 0 : 2);
    } else {
        echo "php bin/rtr.php list\nphp bin/rtr.php auth\nphp bin/rtr.php send OPERATION [request.json] [headers.json]\nphp bin/import-scenarios.php [official-workbook.xlsx]\n\nHTTP 200 may still contain a business rejection. Inspect summary and response_body.\n";
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
