<?php
declare(strict_types=1);

/** Small sandbox-only client. No automatic replay of payment requests. */
final class Client
{
    public const BASE = 'https://api.payments.ca/rtr-sandbox';
    public const TOKEN_URL = 'https://api.payments.ca/accesstoken';
    private Closure $transport;
    private array $token;

    public function __construct(private array $config, array $token = [], ?Closure $transport = null)
    {
        $this->token = $token;
        $this->transport = $transport ?? self::http(...);
    }

    public function tokenState(): array { return $this->token; }

    public function authenticate(bool $force = false): array
    {
        if (!$force && !empty($this->token['access_token']) && ($this->token['expires_at'] ?? 0) > time() + 30) {
            return ['authenticated' => true, 'expires_at' => $this->token['expires_at'], 'cached' => true];
        }
        if (empty($this->config['consumer_key']) || empty($this->config['consumer_secret'])) {
            throw new RuntimeException('Set RTR_CONSUMER_KEY and RTR_CONSUMER_SECRET, or create config.local.php.');
        }
        $basic = base64_encode($this->config['consumer_key'] . ':' . $this->config['consumer_secret']);
        $r = ($this->transport)(self::TOKEN_URL, [
            'Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
        ], 'grant_type=client_credentials&scope=client_credentials');
        $data = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
            $this->token = [];
            throw new RuntimeException('OAuth failed (HTTP ' . $r['status'] . '). Check credentials and the rtr-sandbox-product subscription.');
        }
        $this->token = ['access_token' => $data['access_token'], 'expires_at' => time() + max(0, (int) ($data['expires_in'] ?? 300))];
        return ['authenticated' => true, 'expires_at' => $this->token['expires_at'], 'cached' => false];
    }

    public function send(string $operation, string $body, array $headers = []): array
    {
        if (!isset(Catalog::OPERATIONS[$operation])) {
            throw new InvalidArgumentException('Unknown sandbox operation.');
        }
        if (strlen($body) > 262144) { throw new InvalidArgumentException('Request exceeds 256 KiB.'); }
        // Check JSON syntax only: allow schema-invalid objects for negative scenarios.
        $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) { throw new InvalidArgumentException('Request must be a JSON object.'); }
        $extra = ['traceability-id' => self::uuid()];
        foreach ($headers as $name => $value) {
            if (!in_array($name, ['traceability-id', 'x-uetr', 'x-jws-signature'], true) || !is_string($value) || preg_match('/[\r\n]/', $value) || strlen($value) > 8192) {
                throw new InvalidArgumentException('Invalid request header.');
            }
            if ($value !== '') { $extra[$name] = $value; }
        }
        $this->authenticate();
        $out = ['Content-Type: application/vnd.api.v1+json', 'Accept: application/vnd.api.v1+json'];
        foreach ($extra as $name => $value) { $out[] = $name . ': ' . $value; }
        $url = self::BASE . Catalog::OPERATIONS[$operation][1];
        $r = ($this->transport)($url, [...$out, 'Authorization: Bearer ' . $this->token['access_token']], $body);
        $result = [
            'time' => gmdate('c'), 'operation' => $operation, 'url' => $url,
            'request_headers' => $out, 'request_body' => $body,
            'http_status' => $r['status'], 'duration_ms' => $r['duration_ms'],
            'response_headers' => $r['headers'], 'response_body' => $r['body'],
            'summary' => self::summary($r['body']),
        ];
        // Some documented scenarios deliberately return 401. Never retry them silently.
        $result = $this->redact($result);
        if ($r['status'] === 401) { $this->token = []; }
        return $result;
    }

    public static function summary(string $body): array
    {
        $data = json_decode($body, true);
        $found = [];
        $walk = function (mixed $value, string $path = '') use (&$walk, &$found): void {
            if (!is_array($value)) { return; }
            foreach ($value as $key => $child) {
                $next = $path === '' ? (string) $key : "$path.$key";
                if (is_scalar($child) && (preg_match('/status|reason|error|message_definition_identifier|balance|amount/i', (string) $key) || preg_match('/reason|error|balance/', $path))) {
                    $found[$next] = $child;
                }
                $walk($child, $next);
            }
        };
        $walk($data);
        return $found;
    }

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) { $value[$k] = $this->redact($v); }
            return $value;
        }
        if (!is_string($value)) { return $value; }
        $secrets = [$this->config['consumer_key'] ?? '', $this->config['consumer_secret'] ?? '', $this->token['access_token'] ?? '', $this->config['app_password'] ?? ''];
        $secrets[] = base64_encode(($this->config['consumer_key'] ?? '') . ':' . ($this->config['consumer_secret'] ?? ''));
        foreach ($secrets as $secret) { if (strlen($secret) > 3) { $value = str_replace($secret, '[REDACTED]', $value); } }
        return $value;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public static function http(string $url, array $headers, string $body): array
    {
        if (!extension_loaded('curl')) { throw new RuntimeException('The PHP cURL extension is required.'); }
        $ch = curl_init($url);
        $responseHeaders = []; $responseBody = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $responseHeaders[] = trim($line); return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > 2 * 1024 * 1024) { return 0; }
                $responseBody .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 1);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($ok === false) { throw new RuntimeException("Transport failed (cURL $error). Outcome may be unknown; inspect before manually retrying."); }
        return ['status' => $status, 'body' => $responseBody, 'headers' => array_values(array_filter($responseHeaders)), 'duration_ms' => $ms];
    }
}
