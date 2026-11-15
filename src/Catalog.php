<?php
declare(strict_types=1);

final class Catalog
{
    public const OPERATIONS = [
        'payment' => ['Send payment', '/payments', 'pacs.008 → pacs.002', 'Send a sandbox customer credit transfer.'],
        'status' => ['Payment enquiry', '/payments/status', 'pacs.028 → pacs.002', 'Enquire about a payment using its original identifiers.'],
        'heartbeat' => ['Heartbeat', '/inbound-heartbeat', 'admi.004 → admi.011', 'Explore participant and connection-service-provider availability messages.'],
        'interest' => ['Interest report', '/interest-report', 'camt.003 → camt.004', 'Request a mocked settlement-account interest report.'],
        'balance' => ['Balance report', '/payment-capacity-balance-report', 'camt.003 → camt.004', 'Explore current balances, reconciliation-cycle balances and participant types.'],
    ];

    public static function templates(): array
    {
        $templates = [];
        foreach (['payment', 'status', 'heartbeat', 'interest', 'balance-cycle', 'balance-current'] as $id) {
            $operation = str_starts_with($id, 'balance-') ? 'balance' : $id;
            $templates[] = [
                'id' => 'sample-' . $id, 'operation' => $operation,
                'title' => 'Sample: ' . $id, 'category' => 'Official Postman sample',
                'request' => trim(file_get_contents(dirname(__DIR__) . '/samples/' . $id . '.json')),
                'trigger' => 'Official sample identifiers are preserved. Changing trigger values may change the sandbox response.',
                'expected' => '', 'response' => '',
            ];
        }
        $path = dirname(__DIR__) . '/var/scenarios.json';
        if (is_file($path)) {
            $templates = array_merge($templates, json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        }
        return $templates;
    }
}
