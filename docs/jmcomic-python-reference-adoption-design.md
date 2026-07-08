# JMComic Python Reference Adoption Design for jmcomic-api-main

Date: 2026-07-07

## Scope

Target project:

```text
D:\jm\jm-boom-master\jmcomic-api-main
```

Reference project:

```text
D:\jm\JMComic-Crawler-Python-master
```

This design is for the PHP/Docker API service in `jmcomic-api-main`. It does not target the Tauri/Rust desktop project and should not introduce Rust changes.

The goal is to absorb useful design ideas from `JMComic-Crawler-Python-master` while preserving the current deployed API service shape:

- single PHP entrypoint: `index.php`
- Docker/GHCR deployment
- port `8088`
- existing JSON response contracts
- no required Redis
- no image file cache
- no dynamic plugins
- no shell execution

## Current Assessment

`jmcomic-api-main` has already absorbed several strong ideas from the Python reference:

- `JmApiClient`: token/header construction, encrypted API response decoding, API domain retry.
- `DomainHealth`: APCu-backed domain ordering, failure streak, cooldown, EWMA latency.
- `MemoryCache`: APCu cache for domains, scramble IDs, reader manifests, decoded pages, and single-flight locks.
- `JmAlbum` / `JmChapter` / `JmListItem`: typed-ish model mapping around unstable upstream payloads.
- `ScrambleDecoder`: image row unscrambling.
- `JmService`: application orchestration, prefetch, decoded page cache, next-chapter preheat.
- `SecurityManager` / `InputValidator`: rate limit, brute-force guard, input restrictions, anti-open-proxy boundary.
- PowerShell contract tests under `tests/`.

Therefore the next adoption round should not rewrite the service. It should tighten the weak points around request failure semantics, upstream payload tolerance, diagnostics, and contract tests.

## Python Reference Ideas Worth Adopting

### 1. Centralized Request Execution Semantics

The Python reference centralizes request/retry/domain switching in `AbstractJmClient.request_with_retry`. The PHP service already has a compact equivalent in `JmApiClient::callJson()` and `JmApiClient::fetchScrambleId()`, but the failure semantics are still too coarse.

Recommended adoption:

- Introduce a small internal failure classifier for API calls.
- Distinguish network/timeout, HTTP retryable status, HTTP client status, business API error, envelope decode error, decrypt error, and payload decode error.
- Only mark a domain as hard-failed for network failures, timeouts, and HTTP 5xx.
- Do not punish domains for HTTP 400/401/403/404 or business `code != 200`.
- Preserve current public API responses unless a request is truly unrecoverable.

This keeps the useful Python retry idea while avoiding blind all-domain retry for non-domain failures.

### 2. Domain Strategy and Diagnostics

The Python reference keeps domain discovery and domain retry as first-class concerns. The PHP service already has `resolveDomains()` and `DomainHealth`; these are valuable and should be kept.

Recommended adoption:

- Extend diagnostics to show domain source: `cache`, `remote`, or `fallback`.
- Track last failure kind and last failure message per domain in APCu.
- Keep fallback domain order when all domains are cooling down.
- Preserve current domain update cache TTL.
- Do not add user-supplied arbitrary domains through query parameters.

This makes production failures easier to diagnose without changing the API contract.

### 3. Payload Model Tolerance

The Python reference model layer tolerates upstream field drift. `jmcomic-api-main` has model classes already, but a few fields still assume fixed upstream shapes.

Recommended adoption:

- Normalize scalar fields with helpers: string-or-number, int-or-string, optional array.
- Keep `JmAlbum::normalizeEpisodes()` behavior that removes duplicate `photo_id` and rewrites continuous reading order.
- Add defensive defaults for missing `series`, `name`, `author`, `tags`, `related_list`, `page_arr`, and image fields.
- Add contract tests with fixture-like payload snippets for missing fields, duplicate sort values, empty titles, and mixed scalar types.

This improves service survivability when JM changes payload details.

### 4. Cache Policy as Explicit Service Behavior

The Python reference has `JmOption.decide_*` policy methods. In this PHP service, the useful equivalent is not a broad option system, but explicit cache policy functions that already exist in `JmService`.

Recommended adoption:

- Keep APCu-only decoded page caching.
- Keep `JM_PAGE_CACHE_MAX_ITEM_BYTES` as per-item limit, not total cache limit.
- Expose cache policy in `health=1`, as already started.
- Add explicit diagnostics for `scramble_cache_hit`, `manifest_cache_hit`, and `decoded_page_cache_hit` only in debug/health contexts, not every response payload.
- Keep `prefetch=0` per-request override.

Do not adopt Python download file cache semantics.

### 5. Feature Simplicity, Not Plugin Mechanics

The Python `Feature` API is valuable as a user-friendly wrapper around complex behavior, but its plugin implementation is not appropriate for a public PHP API service.

Recommended adoption:

- Use fixed environment flags for built-in features only.
- Possible future flags:
  - `JM_ENABLE_DOMAIN_DIAGNOSTICS`
  - `JM_ENABLE_DEBUG_TRACE`
  - `JM_ENABLE_COVER_PROXY`
- Keep every feature implemented as local PHP code in `index.php` or a future explicitly included first-party file.

Do not adopt dynamic YAML plugins, monkey patching, shell execution, file deletion, ZIP/PDF export, or downloader workflows.

## Bug and Logic Review

### P0: API Failure Classification Is Too Coarse

Current code:

- `JmApiClient::callJson()` retries all domains for many non-domain failures.
- `$lastError` is assigned but never used in the final `JmException`.
- `code != 200`, JSON decode failure, decrypt failure, and payload decode failure all continue through retry/domain loops without preserving enough diagnostic context.

Risk:

- Real upstream business errors are reported as "API domains unavailable".
- Healthy domains can be deprioritized because the response payload was unexpected.
- Production debugging loses the concrete failing stage.

Recommended fix:

- Add internal `ApiFailure` or associative-array classifier.
- Preserve `lastError` in logs and internal diagnostics.
- Mark hard domain failure only for network/timeout and HTTP 5xx.
- Return or throw a more accurate `JmException` code when every attempt fails for non-domain reasons.

Public response can remain `上游服务不可用` for 5xx, but logs and health diagnostics should become precise.

### P0: Brute Force Guard Says "Distinct jmid" but Counts Requests

Current code:

```php
$key = "jmids:{$this->clientIp}";
$count = $this->store->incr($key, 600);
```

The comment says it counts distinct JM IDs, but the implementation increments on every checked request. A user repeatedly opening the same album can look like ID enumeration when Redis is enabled.

Recommended fix:

- If Redis is available, use a set-like key:
  - `SADD jmids:{ip} {jmid}`
  - `EXPIRE jmids:{ip} 600`
  - `SCARD jmids:{ip}`
- If keeping `incr`, rename the behavior to request burst guard and lower confidence in the ban.
- Add a contract/static test that checks the implementation uses distinct IDs or updates the documented behavior.

### P1: Direct Numeric Chapter Image Requests Bypass Album Membership Validation

Current behavior is intentional and documented:

- `?jmid=...&chapter=<numeric>&page=N` skips album metadata for performance.
- It validates numeric format but cannot verify `chapter` belongs to `jmid` without fetching album metadata.

Risk:

- `jmid` becomes informational in this fast path.
- The endpoint still does not proxy arbitrary URLs, so SSRF/open-proxy risk remains controlled.

Recommended stance:

- Keep this fast path because it is important for reader performance.
- Document that numeric direct image requests validate chapter ID format and page bounds, not album membership.
- Optionally add `strict=1` to force album validation for clients that need it.
- Do not make strict validation the default unless reader latency remains acceptable.

### P1: JSON Decode Checks Should Use `json_last_error()`

Current code checks:

```php
$json = json_decode($body, true);
if ($json === null) ...
```

For this API an envelope should be an array, so `null` is invalid in practice, but diagnostics should distinguish valid JSON scalar/null from malformed JSON.

Recommended fix:

- Decode with helper `decodeJsonObject($text, $stage)`.
- Require array for API envelopes and payloads.
- Include `json_last_error_msg()` in logs, not public response.

### P1: Scramble ID Fallback Can Hide Upstream Template Failures

Current code returns `SCRAMBLE_220980` when all template attempts fail.

Risk:

- Some chapters may decode incorrectly while the service reports success.

Recommended fix:

- Keep fallback for compatibility.
- Add a diagnostic flag such as `scramble_fallback: true` in reader manifest debug data or logs.
- Count fallback occurrences in health diagnostics if APCu is available.

### P2: `sendError()` Merges Extra Fields After Sanitizing Message

Current behavior:

```php
$payload = ['code' => $code, 'success' => false, 'error' => $safeMsg];
if ($extra) $payload = array_merge($payload, $extra);
```

Risk:

- Future callers could accidentally pass sensitive data in `$extra` for 5xx responses.

Recommended fix:

- For `code >= 500`, whitelist only safe extra keys.
- For 4xx, current behavior is acceptable.

### P2: Redis Connection Is Hardcoded

Current `RedisStore` connects to `127.0.0.1:6379`.

Risk:

- Docker deployments cannot easily use a Redis sidecar without editing code.

Recommended fix:

- Add optional `REDIS_HOST`, `REDIS_PORT`, and `REDIS_TIMEOUT_MS`.
- Keep Redis optional.
- Do not make Redis required.

## What Not To Adopt

Do not adopt these Python reference mechanisms into `jmcomic-api-main`:

- YAML dynamic plugin system.
- Python global mutable registries.
- Monkey patching option/client methods.
- User shell command execution.
- CLI workflow.
- Downloader write-to-disk directory rules.
- ZIP/PDF/long-image export.
- Delete-original or duplicate-file deletion plugins.
- Subscription/email notification plugins.
- `PhotoConcurrentFetcherProxy` behavior that guesses album context from `photo_id`.

These features are useful in a local Python downloader, but unsafe or off-target for a public PHP API service.

## Recommended Implementation Phases

### Phase 1: Request Failure Classifier

Goal: make upstream failures accurate without changing public API shape.

Tasks:

1. Add an internal classifier for API call outcomes.
2. Refactor `JmApiClient::callJson()` to use it.
3. Apply the same classification style to `fetchScrambleId()`.
4. Preserve current successful response payloads.
5. Log failure kind, domain, path, HTTP status, and retry count.
6. Update `health=1` domain diagnostics with last failure kind when APCu is available.

Acceptance:

- Network/timeout/HTTP 5xx can retry and mark hard domain failure.
- HTTP 400/401/403/404 does not mark hard domain failure.
- Business `code != 200` does not punish the domain.
- Final logs include the real last failure reason.
- Existing list/search/album/chapter/image contracts remain unchanged.

### Phase 2: Entity Tolerance and Payload Fixtures

Goal: prevent upstream field drift from causing 500 responses.

Tasks:

1. Add small normalization helpers in `index.php`.
2. Harden `JmAlbum::fromApiResponse()`.
3. Harden `JmChapter::fromApiResponse()`.
4. Harden `JmListItem::fromPayload()`.
5. Add contract tests or static fixture tests for mixed scalar types, missing fields, duplicated sort values, and empty titles.

Acceptance:

- Missing optional fields use safe defaults.
- Duplicate chapter `photo_id` is still deduped.
- Duplicate/missing upstream sort produces stable reading order.
- No public JSON field is removed or renamed.

### Phase 3: Security and Diagnostics Corrections

Goal: align comments, docs, behavior, and safe diagnostics.

Tasks:

1. Fix brute-force detection to count distinct `jmid`, or update docs/comments if intentionally counting requests.
2. Whitelist `$extra` fields for 5xx `sendError()`.
3. Add optional Redis host/port env configuration.
4. Document numeric direct image request validation boundary.
5. Add health diagnostics for relevant cache/fallback counters if APCu exists.

Acceptance:

- Repeated same `jmid` does not count as distinct ID enumeration when Redis is enabled.
- 5xx public errors do not leak future extra fields.
- Redis remains optional.
- `README.md` accurately describes the fast image path.

### Phase 4: Contract and Runtime Verification

Goal: make GitHub/Docker deployment confidence high.

Tasks:

1. Extend `tests/list-endpoint-contract.ps1`.
2. Extend `tests/page-endpoint-contract.ps1`.
3. Extend `tests/docker-runtime-contract.ps1` only for runtime-observable behavior.
4. Keep Docker build workflow under `jmcomic-api-main/.github/workflows/docker-build.yml`.
5. Run `scripts/runtime-verify.ps1` on a Docker-capable host.

Acceptance:

- Static contract tests pass locally where PowerShell is available.
- Docker runtime verifier passes on a Docker-capable host.
- GitHub Actions Docker build still publishes the image.
- `health=1` reflects the new diagnostics without breaking existing fields.

## Verification Commands

Static checks from `jmcomic-api-main`:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File .\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File .\tests\docker-runtime-contract.ps1
```

Runtime verification on a Docker-capable host:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
```

Manual smoke checks:

```bash
docker compose build
docker compose up -d --force-recreate
curl -i "http://localhost:8088/?health=1"
curl -i "http://localhost:8088/?jmid=350234&format=min"
curl -i "http://localhost:8088/?list=latest&page=1&format=min"
curl -i "http://localhost:8088/?search=董卓&page=1&format=min"
```

## Guardrails

- Do not edit the Tauri/Rust project for this adoption round.
- Do not move the service away from `jmcomic-api-main`.
- Do not change port `8088`.
- Do not require Redis.
- Do not add image file cache or `/app/cache`.
- Do not remove APCu cache behavior.
- Do not break Suwayomi-compatible JSON shapes.
- Do not add dynamic plugins, YAML plugin execution, monkey patching, or shell execution.
- Do not add delete-original, dedup-delete, ZIP, PDF, or downloader write-to-disk features.
- Do not mark complete without contract test and Docker runtime evidence, unless the environment limitation is explicitly documented.

## AI Continuation Prompt

Read `D:\jm\jm-boom-master\jmcomic-api-main\docs\jmcomic-python-reference-adoption-design.md` and autonomously implement it in `D:\jm\jm-boom-master\jmcomic-api-main` only. Do not touch Rust/Tauri code. Start with Phase 1 request failure classification, then Phase 2 entity tolerance, Phase 3 security/diagnostics corrections, and Phase 4 tests/verification. Preserve the existing PHP API contracts, Docker deployment, port 8088, APCu-only image cache, optional Redis behavior, and all guardrails. Add or update PowerShell contract tests, run the available static tests, and document any Docker/runtime verification that cannot be executed locally.
