# JSD Payments — RTR sandbox explorer

A small, framework-free PHP application for Payments Canada's `rtr-sandbox-product`. Browser workbench and command-line client share the same client. PHP 8.2+; no Composer, Node build, database or framework.

## Start locally

Install PHP with **curl**, **xml/SimpleXML**, and **zip** extensions. On Debian/Ubuntu: `sudo apt install php-cli php-curl php-xml php-zip`.

```sh
git clone https://github.com/jsdyer/payments.git
cd payments
cp config.example.php config.local.php
# Edit config.local.php to enter your consumer key and secret.
chmod 600 config.local.php
php bin/import-scenarios.php
php -S 127.0.0.1:8080 -t public
```

Open **http://127.0.0.1:8080**. Start with **Test authentication**, then **Heartbeat → Send to sandbox**. Environment variables `RTR_CONSUMER_KEY` and `RTR_CONSUMER_SECRET` work instead of a local config file. Credentials supplied during development were used only in an ignored local configuration; they are not in this repository.

Only `public/` is the web document root. Never serve the repository root. Local mode checks both the client address and Host header. To use your own Apache/PHP-FPM server, set `RTR_APP_PASSWORD` (or `app_password` in local config), use HTTPS, and restrict access to your private network. HTTP Basic accepts any username and that password. Forward the Authorization header to PHP if your server requires it. Do not expose this credential-bearing developer tool as a public payment form.

PHP needs write access to `var/` for private session files. The application creates `var/sessions/` with mode 0700. The importer also writes its local catalog under `var/`.

## Explore

| Operation | Endpoint below `/rtr-sandbox` | Request → response |
| --- | --- | --- |
| Send payment | `/payments` | pacs.008 → pacs.002 |
| Payment enquiry | `/payments/status` | pacs.028 → pacs.002 |
| Heartbeat | `/inbound-heartbeat` | admi.004 → admi.011 |
| Interest report | `/interest-report` | camt.003 → camt.004 |
| Balance report | `/payment-capacity-balance-report` | camt.003 → camt.004 |

Errors can also return admi.002 or HTTP errors. These are **all five operations currently listed in the official RTR sandbox specifications**, not every production RTR capability.

- Six bundled requests from Payments Canada's MIT-licensed Postman collection.
- Import 64 official v3.0 test scenarios: 21 payments, 11 enquiries, 4 heartbeats, 9 interest and 19 balance reports. Includes domestic/cross-border payments, participant types, business rejections, schema errors and other failure cases. The UI shows trigger instructions and reference responses separately from live results.
- Edit raw JSON or expand **Edit individual fields**, then **Reload fields from JSON**. Field editing preserves scalar types. Load/save your own request files.
- View HTTP status, timing, response headers, raw response, and extracted statuses/reasons/balances. **HTTP 200 does not mean the payment was accepted.**
- **Enquire about last payment** copies original identifiers from the latest payment in session history. Review before sending. This is a convenience for inspecting message relationships, not a claim of stateful sandbox settlement.
- Inspect the last ten exchanges and export redacted JSON records. Tokens stay server-side; no Authorization header is included in exports. Request/response data can still include anything you typed, so use synthetic test data.
- Set optional `traceability-id`, `x-uetr`, and `x-jws-signature` headers. A new traceability UUID is generated when blank. Request dates and business identifiers are preserved because some are scenario triggers.

### What the sandbox actually simulates

The official workbook says responses are **trigger-based**. Its balance/interest reports use mocked data, rather than reflecting payments you sent. Outbound participant APIs were removed from the sandbox's scope. This app does not invent refunds, real-account funding, settlement tracking, webhooks, or production access.

The specifications mark JWS signature headers as required, while the official Postman examples omit them. Live sandbox checks accepted unsigned requests. The client therefore leaves signatures optional and lets you supply one manually. It does not implement production JWS signing, response-signature verification, certificates, or participant onboarding. A supplied signature must correspond to the exact JSON bytes sent; formatting/editing the body changes those bytes.

### Scenario import

```sh
php bin/import-scenarios.php
# Or use a workbook you downloaded from the official portal:
php bin/import-scenarios.php /path/to/RTR_Sandbox_API_Test_Scenarios_v3.0_1.xlsx
```

The importer downloads the official v3.0 workbook to ignored `var/`, extracts its requests, responses, trigger instructions and provenance into `var/scenarios.json`, and does not make any RTR API calls. These portal materials are not republished in this public repo. The importer corrects one known source typo: a trailing comma after `original_uetr` in `pacs.028_Payment Status Request.11`. It labels the correction and retains the original under `source_request`. Other malformed JSON is preserved and reported, not silently rewritten. If the workbook URL changes, download the new workbook and pass its path; review its structure before assuming compatibility.

## CLI

```sh
php bin/rtr.php list
php bin/rtr.php auth
php bin/rtr.php send heartbeat
php bin/rtr.php send payment samples/payment.json
php bin/rtr.php send status samples/status.json
php bin/rtr.php send interest
php bin/rtr.php send balance samples/balance-current.json
php bin/rtr.php send balance samples/balance-cycle.json
# Optional third file: JSON object of allowed request headers
php bin/rtr.php send payment my-request.json my-headers.json
```

The CLI prints the redacted exchange and uses exit code 2 for non-2xx API responses, 1 for local/auth/transport errors. A business rejection inside HTTP 200 still exits 0; inspect the JSON. CLI processes obtain their own token. Browser sessions cache tokens using the server's `expires_in` value, refreshing before expiry. A 401 clears the cached token but is **never automatically retried**, because it can be an intentional scenario result. Transport errors may have an unknown outcome; inspect before manually resending.

## Layout and checks

```text
public/                Browser UI and same-origin API handler
src/Client.php         OAuth, fixed sandbox endpoints, transport, redaction
src/Catalog.php        Operations and examples
samples/               Official Postman requests with upstream MIT notice
bin/                   CLI client and scenario importer
tests/run.php          Offline client and catalog regression checks
var/                   Ignored imported scenario data
config.local.php       Ignored credentials (create locally)
```

```sh
php tests/run.php
find . -name '*.php' -not -name config.local.php -exec php -l {} \;
```

Tests cover token reuse, all endpoint mappings, JSON and header validation, arbitrary-URL rejection, secret redaction, 401 behavior, business-status extraction and loaded templates. Live validation observations are in [VALIDATION.md](VALIDATION.md).

## Sources

- [Official RTR sandbox overview](https://developer.payments.ca/rtr-sandbox-api/apis)
- [Official OpenAPI viewer](https://storage.googleapis.com/prod-apigee-bucket-swagger/index.html)
- [Official v3.0 scenario workbook](https://developer.payments.ca/sites/default/files/2025-09/RTR_Sandbox_API_Test_Scenarios_v3.0_1.xlsx)
- [Payments Canada API samples](https://github.com/paymentscanada/api-samples), with MIT notice in `samples/LICENSE.txt`

Endpoint definitions and source materials checked on 2026-11-15. This is an independent JSD developer tool.
