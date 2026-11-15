# Validation — 2026-11-15

Tested with PHP 8.4.27, cURL, SimpleXML and ZipArchive. Live calls used the JSD Payments application's sandbox credentials. Credentials and live response files are excluded from Git.

## Live Payments Canada checks

| Request | Observed result |
| --- | --- |
| OAuth client credentials | HTTP 200; access token issued, server supplied expiry |
| Send payment (bundled sample) | HTTP 200; pacs.002; ACSP |
| Payment enquiry (bundled sample) | HTTP 200; pacs.002; ACSP |
| Heartbeat (bundled sample) | HTTP 200; admi.011 acknowledgement |
| Interest report (bundled sample) | HTTP 200; camt.004 report |
| Balance: end of reconciliation cycle | HTTP 200; camt.004 report |
| Balance: current, DSP/settlement agent | HTTP 200; camt.004 report |
| Payment enquiry scenario 11 | HTTP 500, as the scenario specifies |
| Heartbeat scenario 03 | HTTP 401, as the scenario specifies |
| Heartbeat scenario 04 | HTTP 200; admi.002 rejection, FF02, missing required fields |

Calls used the documented vendor JSON media type, generated traceability IDs and no JWS signature. This establishes sandbox behavior only. No real money moved. Not all 64 scenarios were executed live.

## Local checks

- PHP syntax checks and JavaScript syntax check passed.
- 159 offline assertions passed with all 64 imported scenarios plus 6 bundled samples loaded. The test count is lower without imported scenarios.
- Real local HTTP checks passed for catalog loading, session persistence, CSRF rejection, private configuration URL returning 404, and untrusted Host rejection.
- DOM interaction tests passed for five operation tabs, scenario selection, individual-field editing, response display, history and copying payment identifiers to an enquiry. These used a simulated transport, separately from the live PHP API checks.
- Both headless browser launch and shared-browser local preview were unavailable in the execution environment. A rendered desktop/mobile visual check has **not** been completed.

The v3.0 workbook contains one invalid trailing comma in enquiry scenario 11. The importer explicitly corrects it, preserves the source request, and labels the change. The corrected request produced the documented HTTP 500 behavior.
