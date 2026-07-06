# JM API Optimization Design

## Goal

Build a reliable, fast, Suwayomi-compatible JM API service that keeps the current external contract stable while reducing repeated upstream calls, image decode cost, and reader latency.

The service must keep:

- API listen port: `8088`
- Docker port mapping: `8088:8088`
- No Redis requirement
- No image file cache and no `/app/cache` volume
- Default prefetch behavior: after page `N`, cache pages `N+1` through `N+10`
- Suwayomi extension compatibility with the existing JSON shapes

## Current State

The current implementation is PHP 8.3 in `index.php`, served by PHP's built-in server. Docker installs `apcu`, enables CLI APCu, exposes `8088`, and sets:

```yaml
JM_PREFETCH_PAGES: "10"
JM_PAGE_CACHE_TTL: "3600"
JM_CHAPTER_CACHE_TTL: "21600"
JM_PAGE_CACHE_MAX_ITEM_BYTES: "104857600"
PHP_CLI_SERVER_WORKERS: "4"
```

The API now has:

- `MemoryCache` using APCu.
- In-memory cache for API domains, scramble IDs, chapter metadata, and decoded page bytes.
- `X-JM-Cache: HIT/MISS` image response header.
- `X-JM-Image-Codec` image response header.
- `prefetch=0` query parameter to disable prefetch for one image request.
- Correct scramble segment calculation using the page name without extension.
- Direct numeric chapter image requests that skip the album metadata request.
- APCu health diagnostics with total/free/used memory and hit/miss counters where APCu exposes them.
- Decoded scrambled still images encoded as WebP quality 85 when GD supports WebP, otherwise JPEG quality 85.
- GIF and non-scrambled images returned without re-encoding.
- Prefetch that skips already cached pages and stops on out-of-range/upstream failures.
- Album metadata includes an `image` cover URL for detail thumbnails.
- Album chapter metadata preserves every distinct `photo_id`; duplicated or missing upstream `sort` values are normalized into a continuous reading order so Suwayomi can keep stable start/resume/next-chapter behavior.

The Suwayomi extension currently uses:

```kotlin
baseUrl = "http://127.0.0.1:8088"
```

Real deployments should use a reachable loopback address, LAN IP, host name, Docker service name, or reverse proxy URL. `0.0.0.0` remains valid only as a server listen address.

## Risk And Bug Review

### 1. Single-page image requests still fetch album metadata first

Status: implemented for numeric chapter IDs.

The image flow now detects `?jmid=...&chapter=<numeric>&page=N` before album lookup, validates the numeric chapter/page parameters, fetches chapter metadata directly by `chapter`, and sends the image response. Album validation remains for `chapter=@N`, `chapter=all`, and comma-separated chapter requests.

Risk:

- Every image request can still cost one album upstream call before chapter/image cache is consulted.
- Prefetch pages amplify this if the caller has not warmed album/chapter cache.

Runtime verification still needs a Docker host to compare request timings and upstream call behavior.

### 2. Prefetch can occupy PHP workers

Status: implemented with bounded behavior.

Current prefetch runs in a shutdown callback after output is emitted. This helps current-page latency but still occupies the PHP worker until prefetch completes.

Risk:

- With `JM_PREFETCH_PAGES=10`, each image miss can trigger up to 10 extra upstream image downloads/decodes.
- On weak hosts, PHP workers can be saturated.

Implemented behavior:

- Prefetch is only registered by the public image endpoint.
- Prefetch checks the decoded page cache first and skips already cached pages.
- Prefetch stops at the first page-out-of-range or upstream failure.
- `prefetch=0` disables prefetch for that image request.
- Weak hosts can lower `JM_PREFETCH_PAGES` to `3` or `5`.

### 3. Cache size semantics are easy to misunderstand

`JM_PAGE_CACHE_MAX_ITEM_BYTES` limits one cached page item, not total cache memory. Total cache capacity is controlled by `apc.shm_size=128M`. The legacy `JM_PAGE_CACHE_MAX_BYTES` name is still accepted as a fallback for compatibility.

Risk:

- Users may think the cache has a 100 MB total cap, while actual total is APCu shared memory size.

Status: implemented.

Health diagnostics now include APCu enabled state plus total/free/used memory, entries, hits, and misses when APCu exposes those values.

### 4. Decoded images are still JPEG 95

Status: implemented.

Decoded scrambled still images now prefer WebP quality 85 when GD WebP support exists, and fall back to JPEG quality 85. GIFs are returned as original GIF bytes instead of being decoded/re-encoded.

Risk:

- Large long-strip images consume more network and memory than needed.
- APCu fills faster.

Runtime verification still needs a Docker host and Suwayomi/client test to confirm image display with returned MIME types.

### 5. Thumbnail currently can use page 1

Status: implemented.

The API exposes album cover URL as `album.image`, and the extension detail mapping uses that URL as `thumbnail_url`.

Risk:

- Opening a details page can decode a full comic page just for a thumbnail.

The extension `versionCode` was bumped because source behavior changed.

### 6. Client base URL must not be `0.0.0.0` in real use

`0.0.0.0` is a bind address, not a reliable client destination.

Required improvement:

- Keep source default only if the user specifically wants it.
- Document replacement examples:
  - Same Docker network: `http://jmcomic-api:8088`
  - Same host: `http://127.0.0.1:8088`
  - LAN: `http://192.168.x.x:8088`
  - Reverse proxy: `https://your-domain.example`

### 7. PHP syntax and runtime are not fully verified locally

This environment does not currently have `php`, `docker`, or `git` available.

Required improvement:

- Any future AI or engineer must verify in a real Docker-capable environment:
  - `docker compose build`
  - `docker compose up -d`
  - `curl http://localhost:8088/?health=1`
  - one metadata request
  - one image request twice, expecting second request to show `X-JM-Cache: HIT`

## Implementation Status

Static contract coverage is in place for:

- Direct numeric chapter image path before album lookup.
- APCu health diagnostics.
- In-memory decoded page cache with `X-JM-Cache`.
- `X-JM-Image-Codec`.
- WebP 85 preferred output, JPEG 85 fallback.
- GIF non-reencode behavior.
- Prefetch skip-cache/stop-on-error behavior.
- Docker `8088:8088` mapping and no `/app/cache` volume.
- Extension cover thumbnails and versionCode bump.

Runtime verification still must be run on a Docker-capable host.

## Acceptance Criteria

The work is complete only when all are true:

- `docker compose build` succeeds.
- `docker compose up -d` starts the service on `8088`.
- `GET /?health=1` returns `success: true`, `diagnostics.apcu: true`, and `diagnostics.apcu_details.free_memory_bytes`.
- `GET /?jmid=350234&format=min` returns album metadata.
- `GET /?list=latest&page=1&format=min` returns non-empty or valid empty list without server error.
- `GET /?search=<known title>&page=1&format=min` returns valid JSON.
- First image request returns `X-JM-Cache: MISS`.
- Repeating the same image request returns `X-JM-Cache: HIT`.
- Image requests return `X-JM-Image-Codec`.
- Requesting page `N` warms pages `N+1` through `N+10` when available.
- `prefetch=0` disables prefetch for that request.
- No image bytes are written to disk.
- Suwayomi can install the extension and read a chapter.

## Guardrails

Do not:

- Reintroduce Redis.
- Reintroduce image file cache or `/app/cache` volume.
- Change the external API JSON contract without updating the extension and tests.
- Change the API port away from `8088`.
- Use `0.0.0.0` as a recommended client access URL.
- Treat `JM_PAGE_CACHE_MAX_ITEM_BYTES` as a total cache limit; APCu `apc.shm_size` controls total memory.
- Mark the task complete without Docker runtime verification.

## Current Verification Commands

Static checks currently available on this Windows workspace:

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\tests\docker-runtime-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jm-boom-master\jmcomic-api-main\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1
```

Runtime checks required on a Docker host:

```bash
docker compose build
docker compose up -d
curl -i "http://localhost:8088/?health=1"
curl -i "http://localhost:8088/?jmid=350234&format=min"
curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"
curl -I "http://localhost:8088/?jmid=350234&chapter=350234&page=1"
```

Or run the bundled PowerShell verifier from the API project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
```

The verifier covers Docker build/up, health diagnostics, metadata, first image `MISS`, repeated image `HIT`, `X-JM-Image-Codec`, observed prefetch hits, `prefetch=0`, no `/app/cache` volume, and no decoded image files under `/app`.
