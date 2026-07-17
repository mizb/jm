# JM API Advanced Reader Optimization Design

Date: 2026-07-07

This document deepens the current `optimization-design.md` and adopts the useful reader-side ideas from `D:\jm\jm-boom-master` into the PHP API + Suwayomi extension architecture. It is a design and delivery guide for the next implementation round; it does not change the current API contract by itself.

## Goal

Improve reader performance and stability while preserving the current deployment model:

- Keep API listen port `8088` and Docker mapping `8088:8088`.
- Keep decoded image cache in APCu memory only.
- Do not use Redis for page/image caching.
- Do not write decoded image files to disk.
- Do not restore `/app/cache` volume.
- Keep default prefetch behavior as page `N+1` through `N+10`, unless memory or upstream health rules deliberately skip and report work.
- Preserve Suwayomi JSON contracts unless API, extension, and tests are changed together.
- Do not recommend `0.0.0.0` as a client URL.
- Do not change APK `versionCode` unless Kotlin extension source changes.

The practical outcomes should be:

- Fewer duplicated image downloads and decodes under `PHP_CLI_SERVER_WORKERS=10`.
- Faster chapter/page transitions.
- Safer memory behavior when APCu is close to full.
- Better upstream domain resilience.
- Clear diagnostics for version, cache, prefetch, and domain state.

## Current Baseline

The current API now has:

- API app version `2026.07.07.7`.
- Current cross-project performance maintenance version: `2026.07.17.7`.
- Docker entrypoint that prints `JM API version ...`.
- Port `8088`.
- APCu page cache and APCu diagnostics in `health=1`.
- `X-JM-API-Version`, `X-JM-Cache`, and `X-JM-Image-Codec` headers.
- `X-JM-Singleflight`, `X-JM-Prefetch`, `X-JM-Cache-Store`, and optional `X-JM-APCu-Free` image response diagnostics.
- Direct numeric chapter image path before album lookup.
- APCu single-flight page materialization for decoded image misses.
- Lightweight reader manifest cache for chapter page metadata.
- WebP 85 preferred output for decoded scrambled still images, JPEG 85 fallback.
- GIF and non-scrambled image passthrough.
- Tiered post-response prefetch of `N+1` through `N+10`, with `N+1..N+2` treated as high priority.
- Optional next-chapter preheat when API-generated image URLs include `next_chapter`.
- APCu memory waterline checks for page cache writes and low-priority prefetch.
- APCu-backed upstream API domain health scoring with cooldown and EWMA latency.
- Suwayomi runtime baseUrl setting, default `http://127.0.0.1:8088`.
- Suwayomi default prefetch enabled; `prefetch=0` only when the user disables API prefetch.
- Chapter ordering that presents newest first in the list while preserving reading order through `chapter_number`.

Runtime Docker verification is still required on a host with Docker because this workspace does not expose `php` or `docker` on `PATH`.

## Original Project Ideas To Adopt

Reference code in `D:\jm\jm-boom-master` provides these useful patterns:

- `src-tauri\src\reader\page.rs`: one materialization lock per page cache target, so concurrent readers do not duplicate page work.
- `src\features\reader\use-reader-page-query.ts`: visible page is highest priority, adjacent pages are eager.
- `src\features\reader\use-reader-prefetch.ts`: wider prefetch window is asynchronous and deduplicated by query key.
- `src\features\reader\use-next-chapter-prefetch.ts`: next chapter is preheated only near the end of the current chapter.
- `src-tauri\src\reader\manifest.rs`: a lightweight reader manifest cache avoids rebuilding page metadata repeatedly.
- `src-tauri\src\api\setting.rs` and `src\features\settings\use-endpoint-options.ts`: endpoints are scored by availability and latency.
- `src-tauri\src\reader\cache.rs`: cache cleanup uses a waterline instead of waiting until memory or disk is exhausted.

The PHP API cannot copy the Tauri implementation directly because it is stateless HTTP running across PHP workers. The correct translation is APCu-backed coordination, bounded waits, and explicit response diagnostics.

## Proposed Architecture

Add a small set of focused helpers inside `index.php` first. Split into separate PHP files only if the repository is intentionally moved away from the current single-file deployment style.

Recommended internal units:

- `MemoryCache`: extend with atomic `add`, `delete`, `sma`, lock token helpers, and waterline helpers.
- `DecodedPageSingleFlight`: small helper or methods in `JmService` that coordinate page materialization using APCu lock keys.
- `ReaderManifest`: lightweight normalized metadata for one chapter: `photo_id`, `scramble_id`, `page_count`, image filenames, URLs, MIME hints, and decode segments.
- `PrefetchPlanner`: computes high-priority pages, low-priority pages, and optional next-chapter pages.
- `DomainHealth`: APCu-backed stats and cooldown for upstream API domains.
- `RuntimeDiagnostics`: health payload fields and response headers for cache/prefetch decisions.

Keep the public endpoint shapes stable:

- `GET /?health=1`
- `GET /?list=promote&page=1&format=min`
- `GET /?list=weekly&page=1&format=min`
- `GET /?list=latest&page=1&format=min`
- `GET /?list=popular&page=1&format=min`
- `GET /?search=<title>&page=1&order=mr&format=min`
- `GET /?jmid=<albumId>&format=min`
- `GET /?jmid=<albumId>&chapter=<photoId>&format=min`
- `GET /?jmid=<albumId>&chapter=<photoId>&page=<N>`

Optional query parameters for reader optimization may be added to image URLs generated by the API:

- `prefetch=0`: already supported, disables prefetch for that request.
- `next_chapter=<photoId>`: optional hint generated only when the API already knows the next chapter from album context.

Adding `next_chapter` to generated image URLs does not change the JSON response shape; it only enriches existing `images[].url`.

## Feature 1: APCu Single-Flight Page Materialization

### Problem

With `PHP_CLI_SERVER_WORKERS=10`, multiple Suwayomi image requests can miss the same decoded page at the same time. The current code can download and decode the same page multiple times before any worker stores it in APCu.

### Design

Use an APCu lock key derived from the decoded page cache key:

- Page cache key: current `page:<md5(...)>`.
- Lock key: `lock:page:<same hash>`.
- Lock value: random token such as `bin2hex(random_bytes(8)) . ':' . getmypid()`.
- Lock TTL: default `30` seconds, configurable with `JM_SINGLEFLIGHT_LOCK_TTL`.

Algorithm:

1. Build chapter manifest and page cache key.
2. Check page cache. If present, return `cache_hit=true` and `singleflight=hit`.
3. Try `apcu_add(lockKey, token, ttl)`.
4. If acquired:
   - Re-check page cache immediately.
   - Download, decode, and cache the page.
   - Release only if the stored token still matches this worker's token.
   - Return `singleflight=owner`.
5. If not acquired:
   - Poll page cache with bounded sleeps.
   - Sleep `50` to `150` ms with jitter.
   - Stop after `JM_SINGLEFLIGHT_WAIT_MS`, default `5000`.
   - If cache appears, return it as `cache_hit=true` and `singleflight=hit-after-wait`.
   - If timed out, compute independently but do not delete another worker's lock. Return `singleflight=timeout`.

If APCu is unavailable, skip single-flight and preserve the current behavior.

### Diagnostics

Add non-breaking headers on image responses:

- `X-JM-Singleflight: hit|owner|hit-after-wait|timeout|disabled`
- `X-JM-Cache-Store: stored|skipped-too-large|skipped-low-memory|disabled`

Add counters to `health=1` when cheap to expose:

- `diagnostics.singleflight.enabled`
- `diagnostics.singleflight.lock_ttl_seconds`
- `diagnostics.singleflight.wait_ms`

### Bug And Logic Checks

- Always re-check cache after acquiring the lock.
- Lock TTL is mandatory; otherwise a crashed owner can block all followers.
- Waiters must not wait forever.
- A waiter must not delete a lock owned by another token.
- Lock values must stay tiny; never store image bytes or manifest data inside the lock key.
- A timeout fallback may duplicate one decode, but it prevents a user-visible hang.

## Feature 2: Lightweight Reader Manifest Cache

### Problem

`fetchDecodedPage()` currently obtains scramble ID, chapter data, image list, and cache key inputs per page. Existing chapter cache helps, but the hot path still mixes API model objects, page-cache-key construction, and decode decisions.

### Design

Introduce a normalized reader manifest structure for one chapter:

```json
{
  "photo_id": "350234",
  "scramble_id": "220980",
  "page_count": 32,
  "images": [
    {
      "index": 1,
      "filename": "00001.webp",
      "url": "https://...",
      "source_url": "https://...",
      "mime": "image/webp",
      "decode_segments": 10,
      "cache_key": "page:..."
    }
  ]
}
```

Cache key:

- `manifest:<md5(photoId + ':' + scrambleId)>`

TTL:

- Use `JM_CHAPTER_CACHE_TTL`, default `21600`.

Implementation path:

1. Add `fetchReaderManifest(photoId)` in `JmService`.
2. Internally call `fetchScrambleId(photoId)` and `fetchChapter(photoId, scrambleId)`.
3. Normalize and cache the lightweight array.
4. Make `fetchDecodedPage()` and `isDecodedPageCached()` use the manifest.
5. Preserve existing chapter JSON mapping for non-image endpoints.

### Bug And Logic Checks

- The manifest cache must not include decoded image bytes.
- The cache key must change when image URL, filename, or decode segments change.
- Page numbers remain 1-based externally and 0-based only inside arrays.
- If the manifest says page is out of range, stop prefetch at that point.
- Do not fetch album metadata in the direct numeric image hot path.

## Feature 3: Tiered Prefetch N+1 Through N+10

### Problem

Current prefetch is serial and treats all future pages equally. It can also occupy a worker for a long time after the response is emitted.

### Design

Keep the default contract: request page `N`, then attempt `N+1` through `N+10`.

Change the planner:

- High priority: `N+1` and `N+2`.
- Low priority: `N+3` through `N+10`.
- Already cached pages are skipped.
- Out-of-range or upstream failure stops that tier.
- Prefetch never schedules another prefetch.

Execution:

- Keep `register_shutdown_function` so current page latency stays low.
- Process high-priority pages first.
- Before each low-priority page, check APCu waterline.
- Do not add internal `curl_multi` image decoding in the first pass. PHP workers already provide request-level concurrency; adding in-process decode concurrency raises peak memory. The 10-worker setting plus single-flight is the safer first implementation.

Optional later setting:

- `JM_PREFETCH_LOW_PRIORITY_LIMIT`, default `8`.
- `JM_PREFETCH_HIGH_PRIORITY_PAGES`, default `2`.

### Diagnostics

Add image response header:

- `X-JM-Prefetch: scheduled|disabled|skipped-no-apcu|skipped-low-memory|none`

Add health fields:

- `diagnostics.prefetch.default_pages`
- `diagnostics.prefetch.high_priority_pages`
- `diagnostics.prefetch.max_pages`
- `diagnostics.prefetch.low_memory_policy`

### Bug And Logic Checks

- Prefetch must call only the page materialization function, not the public endpoint dispatcher.
- `prefetch=0` must still disable all prefetch for that request.
- Static/runtime tests must verify that probing prefetched pages does not trigger cascading prefetch; use `prefetch=0` while probing.
- Current page response must not wait for low-priority prefetch.

## Feature 4: Optional Next-Chapter Preheat

### Problem

When the user reaches the end of one chapter, the next chapter often has a visible delay because its manifest and first image are cold.

### Design

Adopt the original threshold:

- Trigger if progress is at least `80%`, or remaining pages are `<= 6`.
- Preheat first `2` pages of the next chapter.

Avoid the main trap: do not make direct image requests fetch album metadata on every page just to find the next chapter.

Safe implementation:

1. When the API is already resolving a chapter through album context, compute the next chapter from the normalized album episode order.
2. Add `next_chapter=<photoId>` to generated decoded image URLs for the current chapter when a next chapter exists.
3. The direct image endpoint reads the optional `next_chapter` hint.
4. After serving page `N`, if threshold is met, schedule next-chapter preheat in the same shutdown callback.
5. Preheat only `next_chapter` page `1` and page `2`, using the same single-flight and waterline rules.
6. If `next_chapter` is absent, skip. Do not call `fetchAlbum()` from the direct image hot path.

Configuration:

- `JM_NEXT_CHAPTER_PREFETCH=1`, default `1`.
- `JM_NEXT_CHAPTER_PREFETCH_PAGES=2`, min `0`, max `5`.
- `JM_NEXT_CHAPTER_PREFETCH_PROGRESS=80`.
- `JM_NEXT_CHAPTER_PREFETCH_REMAINING=6`.

### Chapter Order Rule

Keep chapter semantics consistent with current Suwayomi behavior:

- Album/API normalized reading order is first chapter to last chapter.
- Extension may return the list reversed for display, but `chapter_number` must encode reading order.
- The next chapter for reading is the chapter after the current one in normalized reading order.
- New books should start at the first reading chapter unless Suwayomi has previous progress.

### Bug And Logic Checks

- If adding `next_chapter` to API-generated image URLs, ensure URL building still includes `jmid`, numeric `chapter`, and `page`.
- If extension code changes to add or consume next-chapter hints, bump `versionCode` and update `extension-contract.ps1`.
- Do not preheat next chapter if current chapter has no valid page count.
- Do not preheat next chapter when `prefetch=0`.
- Do not preheat next chapter if APCu is low on memory.

## Feature 5: APCu Memory Waterline

### Problem

`JM_PAGE_CACHE_MAX_ITEM_BYTES` limits a single cached item, not total memory. APCu total memory is controlled by `apc.shm_size`. Under many long-strip pages, APCu can fill quickly.

### Design

Use `apcu_sma_info(true)` to compute:

- `total_memory_bytes`
- `free_memory_bytes`
- `free_ratio`

Add configuration:

- `JM_PREFETCH_MIN_FREE_BYTES=33554432` (32 MiB)
- `JM_PREFETCH_MIN_FREE_RATIO=15` (15 percent)
- `JM_PAGE_CACHE_MIN_FREE_BYTES=16777216` (16 MiB)
- `JM_PAGE_CACHE_MIN_FREE_RATIO=8` (8 percent)
- Keep `JM_PAGE_CACHE_MAX_ITEM_BYTES=104857600`.

Policy:

- Current requested page is always served if upstream succeeds.
- If current page is too large or APCu is too low, return the page but skip storing it.
- If memory is below prefetch waterline, skip low-priority prefetch first.
- If memory is critically low, skip all prefetch.
- If APCu diagnostics are unavailable, use conservative behavior: allow current page caching by item-size limit, but skip low-priority prefetch.

### Diagnostics

Add to `health=1`:

- `diagnostics.apcu_details.free_ratio`
- `diagnostics.cache_policy.page_cache_min_free_bytes`
- `diagnostics.cache_policy.page_cache_min_free_ratio`
- `diagnostics.cache_policy.prefetch_min_free_bytes`
- `diagnostics.cache_policy.prefetch_min_free_ratio`
- `diagnostics.cache_policy.max_item_bytes`

Image response headers:

- `X-JM-Cache-Store: stored|skipped-too-large|skipped-low-memory|disabled`
- `X-JM-APCu-Free: <bytes>` if available.

### Bug And Logic Checks

- Waterline must never prevent serving the requested page.
- Waterline should not throw if `apcu_sma_info` returns unexpected fields.
- Use integer percentage env vars to avoid float parsing surprises in PHP.
- Document clearly that APCu `apc.shm_size` is the total memory cap.

## Feature 6: Upstream Domain Health Scoring

### Problem

`JmApiClient` currently loops domains in order. If an early domain is slow or failing, every request can pay that penalty before reaching a good domain.

### Design

Track domain health in APCu:

```json
{
  "domain": "www.example",
  "success_count": 12,
  "failure_count": 2,
  "failure_streak": 0,
  "last_failure_at": 0,
  "cooldown_until": 0,
  "ewma_latency_ms": 230
}
```

Cache key:

- `domain-health:<md5(domain)>`

TTL:

- `JM_DOMAIN_STATS_TTL=21600`.

Cooldown:

- `JM_DOMAIN_COOLDOWN_SECONDS=120`.
- Increase cooldown modestly with failure streak, capped at `10` minutes.

Sorting:

1. Domains not in cooldown.
2. Lower failure streak.
3. Lower EWMA latency.
4. Original domain order as tie-breaker.

Failure categories:

- Network error: failure.
- HTTP 5xx: failure.
- Empty response: failure.
- JSON/decrypt/payload error: soft failure because it may be upstream or token related.
- API code not `200`: soft failure.

Success:

- Reset failure streak.
- Update EWMA latency with a low-cost formula, for example `new = old * 0.7 + latest * 0.3`.

Fallback:

- If all domains are in cooldown, try original order anyway.
- If APCu is unavailable or stats are invalid, use current behavior.

### Diagnostics

Add to `health=1`:

- `diagnostics.domains.order`
- `diagnostics.domains.stats_available`
- `diagnostics.domains.cooldown_seconds`

Do not expose secrets, tokens, or long upstream bodies.

### Bug And Logic Checks

- Never permanently ban all domains.
- Do not let corrupted APCu stats break requests.
- Preserve domain auto-update from `DOMAIN_SERVER_URLS`.
- Apply health scoring to API domains. CDN image URLs are separate and should not be rewritten in this round.

## Feature 7: Version And Runtime Observability

### Design

Every implementation round that changes API behavior must bump all of these together:

- `JmConfig::APP_VERSION`
- `Dockerfile` `JM_API_VERSION`
- `docker-compose.yml` `JM_API_VERSION`
- `docker-entrypoint.sh` fallback version
- `tests\docker-runtime-contract.ps1` expected version
- README version examples

Implemented API version for this design:

- `2026.07.07.7`

Keep:

- Startup log: `JM API version <version>`.
- Health: top-level `version` and `diagnostics.app_version`.
- Response header: `X-JM-API-Version`.

If only docs or AI prompts change, do not bump API or APK versions.

## API And Extension Contract Rules

No extension change is required for:

- APCu single-flight.
- Manifest cache.
- Tiered prefetch inside API.
- Waterline behavior.
- Domain health scoring.
- Adding optional `next_chapter` query parameters to API-generated image URLs.

Extension source change is required only if:

- Extension starts generating `next_chapter` itself.
- Extension changes chapter order, image URL rules, filters, settings, or DTOs.
- API JSON shape changes.

If Kotlin source changes:

- Bump `src\zh\jmapi\build.gradle.kts` `versionCode`.
- Update README APK example.
- Update `D:\jm\jmapi-extension\tests\extension-contract.ps1`.
- Rebuild APK when Gradle/Android SDK is available.

## Test Plan

Use test-first changes for implementation. Update static tests before code for each new behavior.

Required static checks:

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1
```

Add or extend tests to check:

- `apcu_add` or equivalent atomic lock exists.
- Lock TTL and bounded wait exist.
- Cache is rechecked after lock acquisition.
- Headers include `X-JM-Singleflight`.
- Manifest cache key and TTL exist.
- Prefetch has high and low priority ranges.
- `prefetch=0` disables normal and next-chapter prefetch.
- Waterline env vars are present.
- Low-memory policy does not block current page.
- Domain health stats and cooldown exist.
- Direct numeric image branch still appears before `fetchAlbum`.
- No `/app/cache` volume is present.
- No stale `8080` remains in Docker/runtime docs.
- Chapter order tests remain intact.

Required Docker runtime verification:

```powershell
cd D:\jm\jmcomic-api-main
docker compose build
docker compose up -d --force-recreate
curl -i "http://localhost:8088/?health=1"
curl -i "http://localhost:8088/?jmid=350234&format=min"
curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"
curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"
```

Expected:

- Health returns the current version and APCu diagnostics.
- First image request can be `MISS`.
- Repeated image request returns `X-JM-Cache: HIT`.
- Image response includes `X-JM-Singleflight`.
- Page `N+1` through `N+10` are warmed when available.
- `prefetch=0` disables warming for that request.
- Near the end of a chapter, next chapter page `1` or `2` can be warmed when `next_chapter` is present.
- No decoded image files are written under `/app`.
- Compose has no `/app/cache` volume.

For concurrency verification, run a best-effort parallel probe:

```powershell
1..10 | ForEach-Object {
    Start-Job -ScriptBlock {
        curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1&prefetch=0"
    }
} | Receive-Job -Wait -AutoRemoveJob
```

This cannot prove perfect single-flight deterministically, but the logs and headers should show wait/owner/hit-after-wait behavior instead of ten independent full decodes.

## Implementation Order

1. Add failing static tests for version bump and new snippets.
2. Bump API version beyond `2026.07.07.7` if behavior code changes.
3. Extend `MemoryCache` with atomic add/delete/token-safe lock helpers and APCu free-ratio helpers.
4. Add reader manifest cache and route `fetchDecodedPage()` through it.
5. Add single-flight materialization around page MISS work.
6. Add `X-JM-Singleflight`, `X-JM-Cache-Store`, and relevant health diagnostics.
7. Add APCu waterline checks for cache store and prefetch.
8. Change prefetch planner to high-priority `N+1..N+2` and low-priority `N+3..N+10`.
9. Add optional next-chapter hint generation and preheat without album lookup in the direct image hot path.
10. Add domain health scoring and health diagnostics.
11. Update README deployment and diagnostics sections.
12. Run static checks.
13. Run Docker runtime verification where Docker is available.
14. Run a bug audit loop until no new actionable bug is found or a missing external tool blocks verification.

## Stop Conditions For The Next AI Agent

The next agent may stop only when one of these is true:

- All implementation, docs, and tests are complete and verified.
- A required external tool is missing, such as Docker, PHP, Gradle, Android SDK, or GitHub Actions access; the agent must list exact commands for the user to run.
- A runtime upstream site failure prevents verification; the agent must distinguish this from local code failure.
- A bug audit round finds no new bug and outputs the remaining risks.

It must not stop after only analysis or only writing a plan.

## Remaining Risks

- APCu is shared per PHP process group, but behavior can differ under PHP-FPM versus PHP built-in server. Verify in the actual Docker image.
- Single-flight wait behavior can hide slow upstream if timeout is too high. Keep default bounded and document tuning.
- Next-chapter preheat depends on a correct next-chapter hint. If the hint is absent, skip rather than fetching album metadata from the direct image path.
- Domain scoring can make a transient failure look worse than it is. Keep cooldown short and always provide fallback.
- APCu object serialization should be avoided for new manifest data; arrays are safer for future PHP versions.
- Long strip images can still exceed memory during decode even if they are not cached. The current page must return a clear `502` rather than crashing the worker if GD fails.
