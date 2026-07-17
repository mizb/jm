# JMComic Python Reference Adoption Implementation Notes

Date: 2026-07-07

Scope:

- Target: `D:\jm\jmcomic-api-main`
- No Rust/Tauri files were edited.
- The service remains a PHP/Docker API service on port `8088`.

Implemented:

1. API request failure classification
   - Added `ApiFailure` to classify network, retryable HTTP, client HTTP, JM business, envelope JSON, envelope shape, decrypt, payload JSON, payload shape, and scramble template failures.
   - `JmApiClient::callJson()` and `fetchScrambleId()` now record failure kind/message and only hard-penalize domains for network errors and HTTP 5xx/0 status.
   - JSON decoding now uses `json_last_error_msg()` for diagnostics.
   - Scramble ID fallback remains compatible, but fallback count and last fallback details are exposed through health diagnostics when APCu is available.

2. Upstream payload tolerance
   - Added `PayloadNormalizer`.
   - Hardened `JmAlbum`, `JmChapter`, and `JmListItem` against missing fields, mixed scalar types, non-array list fields, empty image names, and duplicate/unstable episode order.
   - Existing public JSON field names are preserved.

3. Security and diagnostics corrections
   - Redis remains optional, but `REDIS_HOST`, `REDIS_PORT`, and `REDIS_TIMEOUT_MS` can now configure a sidecar Redis.
   - Brute-force detection now counts distinct `jmid` values with a Redis sorted set and removes expired members before counting.
   - 5xx error extras are whitelisted through `safeErrorExtras()`.
   - README documents that numeric direct image requests validate chapter ID format and page bounds, not album membership.

Verification run locally:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File .\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File .\tests\docker-runtime-contract.ps1
powershell -ExecutionPolicy Bypass -File .\tests\adoption-hardening-contract.ps1
```

All four static contract checks passed locally.

Not run locally:

- `php -l index.php`: local `php` command is not installed.
- `scripts/runtime-verify.ps1`: local `docker` command is not installed.
- Docker build/run smoke checks: local `docker` command is not installed.

Run these on a Docker/PHP-capable host before production promotion:

```powershell
php -l .\index.php
powershell -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
docker compose build
docker compose up -d --force-recreate
curl -i "http://localhost:8088/?health=1"
```
