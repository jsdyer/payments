<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Import locally rather than republishing the portal's workbook in this public repo.
const WORKBOOK_URL = 'https://developer.payments.ca/sites/default/files/2025-09/RTR_Sandbox_API_Test_Scenarios_v3.0_1.xlsx';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function xml(string $text): SimpleXMLElement
{
    if (stripos($text, '<!DOCTYPE') !== false) { throw new RuntimeException('DOCTYPE is not permitted.'); }
    $doc = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
    if ($doc === false) { throw new RuntimeException('Invalid workbook XML.'); }
    return $doc;
}

try {
    if (!extension_loaded('zip') || !extension_loaded('simplexml')) { throw new RuntimeException('Install PHP zip and xml extensions.'); }
    $file = $argv[1] ?? dirname(__DIR__) . '/var/scenarios.xlsx';
    if (!isset($argv[1])) {
        $ch = curl_init(WORKBOOK_URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $bytes = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($bytes === false || $status !== 200 || strlen($bytes) > 10 * 1024 * 1024) { throw new RuntimeException('Workbook download failed. Download it from the portal and pass its local filename.'); }
        if (file_put_contents($file, $bytes) === false) { throw new RuntimeException('Cannot write workbook.'); }
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) { throw new RuntimeException('Cannot open XLSX.'); }
    $read = static function (string $name) use ($zip): string {
        $stat = $zip->statName($name);
        if (!$stat || $stat['size'] > 20 * 1024 * 1024) { throw new RuntimeException('Missing or oversized XLSX entry.'); }
        return $zip->getFromName($name);
    };
    $strings = [];
    foreach (xml($read('xl/sharedStrings.xml'))->si as $si) {
        $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings[] = implode('', array_map(strval(...), $si->xpath('.//m:t')));
    }
    $relationships = [];
    foreach (xml($read('xl/_rels/workbook.xml.rels'))->Relationship as $rel) {
        $relationships[(string) $rel['Id']] = 'xl/' . ltrim((string) $rel['Target'], '/');
    }
    $scenarios = []; $invalid = [];
    foreach (xml($read('xl/workbook.xml'))->sheets->sheet as $sheet) {
        $name = (string) $sheet['name'];
        $operation = match (true) {
            str_contains($name, 'Send Payment') => 'payment',
            str_contains($name, 'Payment Inqu') => 'status',
            str_contains($name, 'Interest Report') => 'interest',
            str_contains($name, 'Balance Report') => 'balance',
            str_contains($name, 'Heartbeat') => 'heartbeat',
            default => null,
        };
        if ($operation === null) { continue; }
        $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach (xml($read($relationships[$id]))->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $column = preg_replace('/\d/', '', (string) $c['r']);
                $cells[$column] = (string) $c['t'] === 's' ? $strings[(int) $c->v] : (string) $c->v;
            }
            if (empty($cells['F']) || ($cells['F'] ?? '') === 'Test ID' || empty($cells['L'])) { continue; }
            $request = trim($cells['L']);
            $note = '';
            $sourceRequest = $request;
            // One known v3.0 source typo: trailing comma after original_uetr.
            if ($cells['F'] === 'pacs.028_Payment Status Request.11') {
                $request = preg_replace('/("original_uetr"\s*:\s*"[^"]+")\s*,(\s*})/', '$1$2', $request);
                if ($request !== $sourceRequest) { $note = 'Corrected the source workbook trailing comma after original_uetr. No trigger fields changed; original retained as source_request in the local catalog.'; }
            }
            try { json_decode($request, false, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException) { $invalid[] = $cells['F']; }
            $scenarios[] = [
                'id' => $cells['F'], 'operation' => $operation, 'title' => trim($cells['C'] ?? $cells['F']),
                'category' => trim($cells['B'] ?? ''), 'description' => trim($cells['I'] ?? '') . ($note ? "\n\nImporter note: " . $note : ''),
                'trigger' => trim($cells['J'] ?? ''), 'expected' => trim($cells['K'] ?? ''),
                'request' => $request, 'response' => trim($cells['M'] ?? ''),
                'source' => WORKBOOK_URL, 'sheet' => $name, 'row' => (int) $row['r'], 'source_request' => $sourceRequest,
            ];
        }
    }
    $zip->close();
    if (count($scenarios) < 5) { throw new RuntimeException('Unexpected workbook structure; existing catalog preserved.'); }
    $target = dirname(__DIR__) . '/var/scenarios.json';
    $temp = $target . '.tmp';
    if (file_put_contents($temp, pretty($scenarios)) === false || !rename($temp, $target)) { throw new RuntimeException('Cannot save scenario catalog.'); }
    echo 'Imported ' . count($scenarios) . " scenarios to var/scenarios.json.\n";
    if ($invalid) { echo 'Source samples with invalid JSON (preserved for inspection): ' . implode(', ', $invalid) . "\n"; }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
