# AI Delivery Prompt

The advanced reader optimization round plus homepage/weekly-list follow-up have been implemented in API version `2026.07.07.7`. For future audit or follow-up maintenance, prefer the newer prompt at:

`D:\jm\jm-boom-master\jmcomic-api-main\docs\advanced-reader-optimization-ai-prompt.md`

It includes APCu single-flight, tiered prefetch, optional next-chapter preheat, APCu waterline behavior, and upstream domain health scoring. The prompt below remains useful for maintaining the already implemented baseline.

Use this prompt when handing the project to another AI coding agent.

```text
You are an autonomous senior coding agent working in the same repository as the user.

Objective:
Finish the JM API performance and reliability optimization end-to-end. Do not stop at analysis. Implement, test, document, and provide deployment instructions. Continue until the work is genuinely ready for the user to deploy, or until an external blocker prevents runtime verification.

Repository context:
- API project: D:\jm\jm-boom-master\jmcomic-api-main
- Suwayomi extension project: D:\jm\jmapi-extension
- Main API file: D:\jm\jm-boom-master\jmcomic-api-main\index.php
- Docker files: D:\jm\jm-boom-master\jmcomic-api-main\Dockerfile and docker-compose.yml
- Current API port must remain 8088.
- Current design document: D:\jm\jm-boom-master\jmcomic-api-main\docs\optimization-design.md

Non-negotiable constraints:
- Do not use Redis for image/page/cache optimization.
- Do not write decoded images to disk.
- Do not add or restore a /app/cache Docker volume for image caching.
- Keep Docker/API port 8088 as the actual listening port and published port.
- Keep Suwayomi API JSON compatibility unless you update the extension and tests in the same delivery.
- Do not recommend 0.0.0.0 as a client access address. It is only a bind address.
- Do not change APK/extension versionCode unless extension source changes.
- Do not mark complete without verification evidence.
- Preserve user changes. Do not reset, checkout, or revert unrelated files.

Required investigation before edits:
1. Read:
   - D:\jm\jm-boom-master\jmcomic-api-main\docs\optimization-design.md
   - D:\jm\jm-boom-master\jmcomic-api-main\index.php
   - D:\jm\jm-boom-master\jmcomic-api-main\Dockerfile
   - D:\jm\jm-boom-master\jmcomic-api-main\docker-compose.yml
   - D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\JmApi.kt
   - D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\Dto.kt
2. Confirm current behavior and identify risks:
   - Numeric chapter + page image requests use the direct path before album lookup.
   - Prefetch is N+1 through N+10.
   - Cache is APCu memory cache, not file cache.
   - Decoded scrambled still images prefer WebP 85 and fall back to JPEG 85.
   - GIF and non-scrambled images are not re-encoded.
   - Extension detail thumbnails use the album cover URL, not decoded page 1.

Maintenance goals:
1. Preserve the direct numeric-chapter image path:
   - For requests with jmid, numeric chapter, and page, skip album lookup.
   - Validate jmid/chapter/page formats.
   - Fetch chapter metadata directly by chapter ID.
   - Return the same image response contract.
   - Preserve existing album validation for chapter=@N, chapter=all, and comma chapter lists.
2. Preserve APCu diagnostics:
   - health=1 must report APCu enabled state and useful APCu memory/cache stats when available.
   - `JM_PAGE_CACHE_MAX_ITEM_BYTES` limits a single decoded page item; APCu `apc.shm_size` controls total memory.
3. Preserve safer prefetch:
   - No recursive prefetch.
   - Skip pages already cached.
   - Stop at first out-of-range page.
   - Stop after upstream/decode failure.
   - Keep default N+10 behavior via JM_PREFETCH_PAGES=10.
4. Preserve image output behavior:
   - Prefer WebP output at quality 85 if GD supports WebP.
   - Fallback to JPEG 85.
   - Never re-encode GIF.
   - Keep X-JM-Image-Codec response header.
5. Extension changes:
   - Keep album cover URL for thumbnail instead of page 1 decoded image.
   - If extension source changes again, bump versionCode and update extension contract tests.

Required tests:
- Update or add PowerShell contract tests under D:\jm\jm-boom-master\jmcomic-api-main\tests.
- Existing tests must continue to pass:
  powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\tests\page-endpoint-contract.ps1
  powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\tests\docker-runtime-contract.ps1
  powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\tests\list-endpoint-contract.ps1
  powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1

Required runtime verification on a machine with Docker:
0. Prefer the bundled verifier when PowerShell is available:
   powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\scripts\runtime-verify.ps1
1. docker compose build
2. docker compose up -d
3. curl -i "http://localhost:8088/?health=1"
4. curl -i "http://localhost:8088/?jmid=350234&format=min"
5. curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"
6. Repeat the same image request and verify X-JM-Cache changes to HIT.
7. Verify prefetch by requesting page N, then checking N+1 through N+10 for cache hits when pages exist.
8. Verify no decoded image files are written to disk.

Delivery rules:
- Use small, focused edits.
- Use tests before or alongside behavior changes.
- Explain any runtime verification that cannot be performed in the current environment.
- If Docker, PHP, Gradle, or Git is unavailable, state that clearly and provide exact commands for the user's deployment host.
- Do not stop after writing a plan. Implement the changes unless blocked by missing external tooling or user instruction.

Final response must include:
- Files changed.
- What was optimized.
- Which checks passed.
- Which runtime checks could not be run and why.
- Exact deployment commands.
- Any remaining risks.
```
