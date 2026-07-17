# Advanced Reader Optimization AI Prompt

This prompt was used for the 2026-07-07 advanced reader optimization implementation. A later homepage/weekly-list follow-up brought the current implemented API version to `2026.07.07.7`. Reuse it only for audit, regression repair, or follow-up maintenance, and first compare it against the current code so you do not re-implement completed work.

Current cross-project performance maintenance version: `2026.07.17.1`.

```text
You are an autonomous senior coding agent working on the user's Windows machine. Your job is to fully implement, test, document, and prepare deployment for the advanced JM API reader optimizations. Do not stop at analysis or a plan. Continue until complete delivery, unless a required external tool is missing.

Current date: 2026-07-07.

Project paths:
- API project: D:\jm\jmcomic-api-main
- API main file: D:\jm\jmcomic-api-main\index.php
- API Dockerfile: D:\jm\jmcomic-api-main\Dockerfile
- API compose: D:\jm\jmcomic-api-main\docker-compose.yml
- API entrypoint: D:\jm\jmcomic-api-main\docker-entrypoint.sh
- API README: D:\jm\jmcomic-api-main\README.md
- Advanced design: D:\jm\jmcomic-api-main\docs\advanced-reader-optimization-design.md
- Existing API design: D:\jm\jmcomic-api-main\docs\optimization-design.md
- Extension project: D:\jm\jmapi-extension
- Extension main source: D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\JmApi.kt
- Extension DTO source: D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\Dto.kt
- Extension build file: D:\jm\jmapi-extension\src\zh\jmapi\build.gradle.kts

Must read first:
1. D:\jm\jmcomic-api-main\docs\advanced-reader-optimization-design.md
2. D:\jm\jmcomic-api-main\docs\optimization-design.md
3. D:\jm\jmcomic-api-main\index.php
4. D:\jm\jmcomic-api-main\Dockerfile
5. D:\jm\jmcomic-api-main\docker-compose.yml
6. D:\jm\jmcomic-api-main\docker-entrypoint.sh
7. D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\JmApi.kt
8. D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\Dto.kt
9. D:\jm\jm-boom-master\src-tauri\src\reader\page.rs
10. D:\jm\jm-boom-master\src-tauri\src\reader\manifest.rs
11. D:\jm\jm-boom-master\src\features\reader\use-reader-page-query.ts
12. D:\jm\jm-boom-master\src\features\reader\use-reader-prefetch.ts
13. D:\jm\jm-boom-master\src\features\reader\use-next-chapter-prefetch.ts
14. D:\jm\jm-boom-master\src-tauri\src\api\setting.rs

Hard constraints:
- Do not use Redis for page/image/cache optimization.
- Do not write decoded image files to disk.
- Do not add or restore a /app/cache Docker volume.
- API actual listen port remains 8088.
- Docker mapping remains 8088:8088.
- Default prefetch remains: after page N, attempt N+1 through N+10.
- Keep direct numeric chapter image requests before album lookup.
- Do not make the direct image hot path fetch album metadata just to find next chapter.
- Do not recommend 0.0.0.0 as a client access URL; it is only a bind/listen address.
- Keep Suwayomi JSON contracts stable unless you update API, extension, and tests together.
- Do not change APK versionCode unless Kotlin extension source changes.
- Do not reset, revert, or overwrite user changes.
- Do not claim completion without fresh test or runtime evidence.

Current baseline to preserve:
- Historical baseline before the advanced reader round was 2026.07.07.2; current implemented API version is 2026.07.07.7.
- Docker startup log prints JM API version.
- health=1 returns top-level version and diagnostics.app_version.
- All responses include X-JM-API-Version.
- APCu memory cache is used.
- First/repeated image requests can show X-JM-Cache MISS/HIT.
- Decoded scrambled still images prefer WebP quality 85 and fallback to JPEG 85.
- GIF and non-scrambled images are not re-encoded.
- Suwayomi extension default API baseUrl is http://127.0.0.1:8088.
- Suwayomi default API prefetch is enabled; it adds prefetch=0 only when the user disables API prefetch.
- Chapter ordering must allow new books to start from the first reading chapter and continue to the next chapter.

Implementation requirements:

1. Version discipline
- Because behavior will change, bump API version beyond the current 2026.07.07.7 in:
  - JmConfig::APP_VERSION
  - Dockerfile JM_API_VERSION
  - docker-compose.yml JM_API_VERSION
  - docker-entrypoint.sh fallback
  - tests\docker-runtime-contract.ps1 expected version
  - README examples
- Do not change APK versionCode unless Kotlin source changes.

2. Test-first static coverage
- Before implementation, update PowerShell contract tests to check the new behavior snippets.
- Expected first run should fail because implementation is missing.
- Then implement until tests pass.
- Extend tests to cover:
  - apcu_add or equivalent atomic single-flight lock.
  - token-safe lock release.
  - bounded wait and lock TTL.
  - cache recheck after acquiring lock.
  - X-JM-Singleflight header.
  - manifest cache key and TTL.
  - high priority N+1/N+2 and low priority N+3..N+10 prefetch planning.
  - prefetch=0 disables normal and next-chapter prefetch.
  - APCu waterline env vars and diagnostics.
  - domain health stats and cooldown.
  - direct numeric image branch remains before fetchAlbum.
  - no /app/cache volume and no stale 8080.

3. APCu single-flight page materialization
- Add atomic lock support around decoded page MISS work.
- Use APCu lock keys derived from the decoded page cache key.
- Use small random token values and TTL.
- Waiters poll for a bounded time and return cached data if the owner finishes.
- If timeout occurs, compute independently but do not delete another worker's lock.
- Re-check page cache immediately after acquiring the lock.
- Expose X-JM-Singleflight: hit|owner|hit-after-wait|timeout|disabled.
- If APCu is unavailable, preserve current behavior and report disabled.

4. Lightweight reader manifest cache
- Add a normalized manifest for one chapter: photo_id, scramble_id, page_count, images, filename, source URL, MIME, decode_segments, cache_key.
- Store it as arrays, not PHP objects.
- Key by photoId + scrambleId.
- TTL uses JM_CHAPTER_CACHE_TTL.
- Route fetchDecodedPage() and isDecodedPageCached() through the manifest.
- Do not store image bytes in the manifest.

5. Tiered prefetch
- Keep default JM_PREFETCH_PAGES=10.
- After serving page N, schedule high priority pages N+1 and N+2 first.
- Then schedule low priority N+3 through N+10.
- Skip already cached pages.
- Stop on out-of-range or upstream/decode failure.
- Do not recursively trigger prefetch.
- prefetch=0 disables all prefetch for that request.
- Add X-JM-Prefetch diagnostics.

6. Optional next-chapter preheat
- Implement only without breaking the direct image hot path.
- When the API is already resolving a chapter with album context, compute the next reading chapter from normalized episode order.
- Add next_chapter=<photoId> to generated decoded image URLs when a next chapter exists.
- The direct image endpoint may read this optional next_chapter hint.
- Trigger preheat only when current chapter progress is >=80% or remaining pages <=6.
- Preheat next chapter pages 1 and 2 by default.
- Do not preheat if prefetch=0, next_chapter is absent, APCu is unavailable, APCu is low, or next_chapter is invalid.
- Add env vars:
  - JM_NEXT_CHAPTER_PREFETCH=1
  - JM_NEXT_CHAPTER_PREFETCH_PAGES=2
  - JM_NEXT_CHAPTER_PREFETCH_PROGRESS=80
  - JM_NEXT_CHAPTER_PREFETCH_REMAINING=6

7. APCu memory waterline
- Add free memory and free ratio helpers using apcu_sma_info(true).
- Add env vars:
  - JM_PREFETCH_MIN_FREE_BYTES=33554432
  - JM_PREFETCH_MIN_FREE_RATIO=15
  - JM_PAGE_CACHE_MIN_FREE_BYTES=16777216
  - JM_PAGE_CACHE_MIN_FREE_RATIO=8
- Current page must still be served even if not cached.
- Skip cache store if one item is too large or APCu is below page cache waterline.
- Skip low-priority prefetch first when memory is low; skip all prefetch when critically low.
- Add health diagnostics and X-JM-Cache-Store.

8. Upstream domain health scoring
- Add APCu-backed stats per upstream API domain: success_count, failure_count, failure_streak, last_failure_at, cooldown_until, ewma_latency_ms.
- Sort domains by cooldown state, failure streak, latency, then original order.
- On success, reset failure streak and update EWMA latency.
- On network/5xx/empty response, mark failure and short cooldown.
- On payload/decrypt/API-code errors, mark soft failure.
- If all domains are cooled down, try original order anyway.
- If APCu stats are invalid or unavailable, preserve current behavior.
- Add env vars:
  - JM_DOMAIN_COOLDOWN_SECONDS=120
  - JM_DOMAIN_STATS_TTL=21600
- Add health diagnostics without exposing secrets or long bodies.

9. README and docs
- Update README with:
  - New API version beyond 2026.07.07.7.
  - Single-flight behavior.
  - APCu waterline behavior.
  - Tiered prefetch and next-chapter preheat.
  - Domain health scoring.
  - Exact Docker redeploy commands.
  - Warning that 0.0.0.0 is not a client URL.
- Update docs\optimization-design.md or add a pointer to docs\advanced-reader-optimization-design.md if not already present.
- Keep docs\advanced-reader-optimization-design.md accurate if implementation differs.

Required static checks:
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1

Required Docker runtime verification when Docker is available:
cd D:\jm\jmcomic-api-main
docker compose build
docker compose up -d --force-recreate
curl -i "http://localhost:8088/?health=1"
curl -i "http://localhost:8088/?jmid=350234&format=min"
curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"
curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"

Expected runtime evidence:
- Startup logs show the current JM API version.
- health=1 shows the current version and APCu diagnostics.
- First image can be MISS.
- Second identical image is HIT.
- Image responses include X-JM-Singleflight.
- Prefetch warms N+1 through N+10 when available.
- prefetch=0 disables prefetch.
- Near chapter end, next_chapter hint can warm next chapter first pages.
- No decoded image files are written under /app.
- Compose has no /app/cache volume.

Bug audit loop:
- After implementation, run up to 50 bug-audit rounds.
- Each round must either find a new bug with evidence and fix/verify it, or prove the suspected issue is not a bug with evidence.
- Stop early when a round finds no new actionable bug.
- On stop, output: "停止：已满足停止条件/或缺少信息", remaining risks, and any missing information/tools.

Final response requirements:
- Files changed.
- Optimizations implemented.
- API version and whether APK versionCode changed.
- Tests passed, with command names.
- Runtime checks passed or could not run, with exact reason.
- Docker redeploy commands:
  cd D:\jm\jmcomic-api-main
  docker compose up -d --build --force-recreate
  docker logs jmcomic-api
  curl "http://localhost:8088/?health=1"
- Whether Suwayomi APK needs updating.
- Remaining risks and next suggestions.

Do not declare completion if any required verification was skipped without explaining why.
```
