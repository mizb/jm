# JM Comic Viewer API

PHP 8 禁漫 API 客户端 — 专辑/章节详情 + 图片 URL + 乱序解密参数。

## 快速开始

单文件部署，放到任意 PHP 8 环境即可运行。

```bash
php -S 0.0.0.0:8088 index.php
```

### Docker 部署

本目录已经包含 `Dockerfile` 和 `docker-compose.yml`，适合直接作为独立 GitHub 仓库部署。

本地构建运行：

```bash
git clone <your-repo-url> jmcomic-api
cd jmcomic-api
docker compose up -d --build
```

推送到 GitHub 后，`.github/workflows/docker-build.yml` 会自动构建并发布 GHCR 镜像：

```text
ghcr.io/<你的GitHub用户名>/<仓库名>:latest
ghcr.io/<你的GitHub用户名>/<仓库名>:2026.07.13.2
```

如果要直接使用 GHCR 镜像，可以把 `docker-compose.yml` 中的 `build` 删除，并把 `image` 改成你的镜像名：

```yaml
services:
  jmcomic-api:
    image: ghcr.io/<你的GitHub用户名>/<仓库名>:latest
    container_name: jmcomic-api
    ports:
      - "8088:8088"
    environment:
      JM_API_VERSION: "2026.07.13.2"
      JM_REQUEST_BUDGET_MS: "12000"
      JM_MAX_UPSTREAM_ATTEMPTS: "6"
      JM_LIST_CACHE_TTL: "60"
      JM_SEARCH_CACHE_TTL: "30"
      JM_WEEKLY_LIST_CACHE_TTL: "60"
      JM_ALBUM_CACHE_TTL: "45"
      JM_WEEK_DEFAULTS_CACHE_TTL: "600"
      JM_WEEK_DEFAULTS_STALE_TTL: "3600"
      JM_CACHE_FILL_WAIT_MS: "750"
      JM_CACHE_FILL_LOCK_TTL: "15"
      JM_PREFETCH_PAGES: "10"
      JM_PREFETCH_HIGH_PRIORITY_PAGES: "2"
      JM_PREFETCH_WALL_BUDGET_MS: "5000"
      JM_PREFETCH_BYTE_BUDGET: "16777216"
      JM_PREFETCH_MAX_ACTIVE: "2"
      JM_PREFETCH_MIN_FREE_BYTES: "33554432"
      JM_PREFETCH_MIN_FREE_RATIO: "15"
      JM_PAGE_CACHE_TTL: "3600"
      JM_CHAPTER_CACHE_TTL: "21600"
      JM_PAGE_CACHE_MAX_ITEM_BYTES: "104857600"
      JM_PAGE_CACHE_MIN_FREE_BYTES: "16777216"
      JM_PAGE_CACHE_MIN_FREE_RATIO: "8"
      JM_SINGLEFLIGHT_LOCK_TTL: "30"
      JM_SINGLEFLIGHT_WAIT_MS: "5000"
      JM_NEXT_CHAPTER_PREFETCH: "1"
      JM_NEXT_CHAPTER_PREFETCH_PAGES: "2"
      JM_DOMAIN_COOLDOWN_SECONDS: "120"
      JM_DOMAIN_STATS_TTL: "21600"
      JM_DOMAIN_FRESH_TTL: "86400"
      JM_DOMAIN_STALE_TTL: "86400"
      JM_DOMAIN_REFRESH_DEFERRED: "1"
      JM_DOMAIN_SOURCE_TIMEOUT_MS: "1500"
      JM_DOMAIN_REFRESH_BUDGET_MS: "3000"
      JM_DOMAIN_REFRESH_FAILURE_TTL: "60"
      JM_DOMAIN_REFRESH_LEASE_TTL: "15"
      JM_IMAGE_MAX_COMPRESSED_BYTES: "33554432"
      JM_IMAGE_MAX_PIXELS: "80000000"
      JM_CDN_EPOCH: "1"
      JM_TRUSTED_PROXY_CIDRS: ""
      PHP_CLI_SERVER_WORKERS: "10"
    restart: unless-stopped
```

如果 GHCR 包默认是 private，需要在 GitHub 仓库的 Packages 页面把包可见性改为 public，或在部署机器上先执行 `docker login ghcr.io`。

如果启动日志没有显示版本号，通常是还在运行旧容器或旧镜像。先强制重建/重拉，再看日志：

```powershell
docker compose down
docker compose build --no-cache
docker compose up -d --force-recreate
docker logs jmcomic-api
```

使用 GHCR 镜像时：

```powershell
docker pull ghcr.io/<你的GitHub用户名>/<仓库名>:latest
docker compose up -d --force-recreate
docker logs jmcomic-api
docker image inspect ghcr.io/<你的GitHub用户名>/<仓库名>:latest --format '{{ index .Config.Labels "org.opencontainers.image.version" }}'
```

如果想完全避免 `latest` 缓存误判，可以把 compose 里的镜像固定为：

```text
ghcr.io/<你的GitHub用户名>/<仓库名>:2026.07.13.2
```

启动后检查服务：

```bash
curl "http://localhost:8088/?health=1"
curl "http://localhost:8088/?jmid=350234&format=min"
curl "http://localhost:8088/?list=promote&page=1&format=min"
curl "http://localhost:8088/?list=weekly&page=1&format=min"
curl "http://localhost:8088/?list=latest&page=1&format=min"
curl "http://localhost:8088/?search=董卓&page=1&format=min"
```

Docker 启动日志会显示 API 构建版本：

```powershell
docker logs jmcomic-api
```

看到类似下面这行，就能判断容器加载的是哪一版：

```text
JM API version 2026.07.13.2
```

`health=1` 会返回顶层 `version` 和 `diagnostics.app_version`。所有响应也会带 `X-JM-API-Version` 头，所以直接 `php -S` 和 Docker 启动都能通过接口确认当前运行版本。

如果 Suwayomi 和本服务在同一个 Docker Compose/network 中，后续 Suwayomi 扩展应访问：

```text
http://jmcomic-api:8088
```

如果 Suwayomi 不在 Docker 中，通常访问：

```text
http://127.0.0.1:8088
```

Docker-capable hosts can run the full runtime verifier after deployment (`scripts/runtime-verify.ps1`):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
```

The verifier builds and recreates the container, checks `health=1`, album metadata, image cache HIT behavior, `X-JM-Image-Codec`, `X-JM-Singleflight`, `X-JM-Prefetch`, `X-JM-Cache-Store`, default prefetch, `prefetch=0`, the absence of `/app/cache`, and that no decoded image files are written under `/app`.

```bash
# 拿目录
curl "http://localhost:8088/?jmid=350234"

# 取第 1 章
curl "http://localhost:8088/?jmid=350234&chapter=@1"

# 取单页解密图片
curl -o page.webp "http://localhost:8088/?jmid=350234&chapter=@1&page=1"

# 批量取
curl "http://localhost:8088/?jmid=350234&chapter=413446,413447,413448"

# 按序号
curl "http://localhost:8088/?jmid=350234&chapter=@5"

# 全部章节
curl "http://localhost:8088/?jmid=350234&chapter=all"

# 最新更新
curl "http://localhost:8088/?list=latest&page=1"

# 热门列表
curl "http://localhost:8088/?list=popular&page=1"

# 原版首页推荐
curl "http://localhost:8088/?list=promote&page=1"

# 原版每周推荐/每周必看
curl "http://localhost:8088/?list=weekly&category_id=1&type_id=1&page=1"

# 搜索书名
curl "http://localhost:8088/?search=董卓&page=1"
```

## 系统要求

| 依赖 | 说明 |
|------|------|
| PHP ≥ 8.0 | 8.5 已测试 |
| ext-curl | HTTP 请求 |
| ext-openssl | AES-256 解密 |
| ext-json | JSON 编解码 |
| ext-mbstring | 多字节字符串 |
| ext-redis (可选) | 限流 / 封禁状态 |
| ext-gd (可选) | 图片乱序解码 |

## API 接口

### GET /?list={mode}&page={page}

**返回 Suwayomi 扩展首页/列表数据。**

| 参数 | 示例 | 说明 |
|------|------|------|
| `list` | `popular` | 热门列表 |
| `list` | `latest` | 最新更新 |
| `list` | `promote` | 原版首页推荐；可选 `section`/`id` |
| `list` | `weekly` | 原版每周必看/每周推荐；可选 `category_id` 和 `type_id` |
| `page` | `1` | 1-based 分页 |
| `order` | `new` | 仅用于 `popular`：`new`、`mv` 或 `tf`；非法值回退为 `new` |
| `o` | `mv` | `order` 的兼容别名；同时传入时 `order` 优先 |
| `section` | `0` | 推荐分区 ID，默认 `0` |
| `category_id` | `1` | 每周推荐分类 ID；不传则自动读取原版默认分类 |
| `type_id` | `1` | 每周推荐类型 ID；不传则自动读取原版默认类型 |

```
GET /?list=latest&page=1
```

热门目录排序示例：

```text
GET /?list=popular&page=1&order=new|mv|tf&format=min
GET /?list=popular&page=1&order=mv&format=min
```

目录排序与标题搜索排序是独立契约。目录只接受 `new`、`mv`、`tf`；标题搜索保留 `mr`、`mv`、`mp`、`tf`、`new`。

响应：

```json
{
  "code": 200,
  "success": true,
  "data": {
    "mode": "latest",
    "page": 1,
    "total": 0,
    "has_next_page": true,
    "items": [
      {
        "id": "350234",
        "name": "董卓 上+下",
        "author": "",
        "description": "",
        "image": "https://cdn-msp.jmapiproxy1.cc/media/albums/350234_3x4.jpg",
        "tags": [],
        "likes": 0,
        "total_views": 0,
        "updated_at": null
      }
    ]
  }
}
```

### GET /?search={keyword}&page={page}

**按书名搜索。**

```
GET /?search=董卓&page=1
```

`order`/`o` 可选，默认 `mr`，可传 `mr`、`mv`、`mp`、`tf`、`new`。

### GET /?jmid={id}

**返回专辑元数据 + 全部章节目录**（不含图片 URL）。单次 API 调用，< 2 秒。

```
GET /?jmid=350234
```

响应：

```json
{
  "code": 200,
  "success": true,
  "data": {
    "album": {
      "album_id": "350234",
      "image": "https://cdn-msp.jmapiproxy1.cc/media/albums/350234_3x4.jpg",
      "name": "サンプル",
      "author": ["作者A"],
      "description": "简介文本",
      "total_views": "41314",
      "likes": "918",
      "comments": "5",
      "tags": ["全彩", "中文"],
      "works": [],
      "actors": [],
      "related": [],
      "chapters": 52
    },
    "chapters": [
      { "photo_id": "413446", "title": "第1話", "sort": "1" },
      { "photo_id": "413447", "title": "第2話", "sort": "2" }
    ],
    "chapters_total": 52,
    "elapsed_ms": 1234,
    "api_calls": 1
  }
}
```

### GET /?jmid={id}&chapter={chapter}

**返回章节详情 + 所有图片 URL + 解密参数**。3 次 API 调用，< 4 秒。

`{chapter}` 支持以下格式：

| 格式 | 示例 | 说明 |
|------|------|------|
| `photo_id` | `?chapter=413446` | 精确 ID |
| `id1,id2` | `?chapter=413446,413447` | 批量（上限 50） |
| `@N` | `?chapter=@5` | 按序号（1-based） |
| `all` | `?chapter=all` | 全部章节（上限 50） |

```
GET /?jmid=350234&chapter=413446
```

响应：

```json
{
  "code": 200,
  "success": true,
  "data": {
    "album": { "album_id": "350234", "name": "...", "chapters": 52 },
    "chapters": [
      {
        "photo_id": "413446",
        "title": "第1話",
        "sort": "1",
        "page_count": 32,
        "images": [
          {
            "index": 1,
            "filename": "00047.webp",
            "url": "http://localhost:8088/?jmid=350234&chapter=413446&page=1",
            "source_url": "https://cdn-msp.jmapiproxy1.cc/media/photos/413446/00047.webp",
            "mime": "image/webp",
            "scramble_id": "220980",
            "decode_segments": 10
          }
        ]
      }
    ],
    "chapters_total": 52,
    "chapters_fetched": 1,
    "elapsed_ms": 2345,
    "api_calls": 3
  }
}
```

字段说明：

| 字段 | 类型 | 说明 |
|------|------|------|
| `url` | string | 可直接显示的 API 解码图片地址 |
| `source_url` | string | 原始 CDN 图片直链，可能是乱序图 |
| `mime` | string | `url` 返回的图片 MIME |
| `scramble_id` | string | 图片解密密钥 |
| `decode_segments` | int | 行分割段数。`0` = 未加密，`>0` = 需解密 |

### GET /?health=1

环境诊断。

```json
{
  "code": 200,
  "success": true,
  "version": "2026.07.13.2",
  "diagnostics": {
    "app_version": "2026.07.13.2",
    "php": "8.5.7",
    "apcu": true,
    "apcu_details": {
      "enabled": true,
      "total_memory_bytes": 134217728,
      "free_memory_bytes": 120000000,
      "used_memory_bytes": 14217728,
      "entries": 12,
      "hits": 30,
      "misses": 4
    },
    "redis": false,
    "memory": 2097152
  }
}
```

### GET /?jmid={id}&format=min

紧凑 JSON（无缩进），适合生产环境节省带宽。

## 图片解密

JM CDN 上的图片经过**行乱序加密**。直接打开 URL 显示花图。

### 解密参数

每个图片返回 `scramble_id` 和 `decode_segments`：

- `decode_segments = 0` → 图片未加密，URL 直链可用
- `decode_segments > 0` → 需要解密，数字为行段数

### GET /?jmid={id}&chapter={chapter}&page={page}

**返回单页解密后的图片二进制**，适合 Suwayomi/Tachiyomi 扩展直接作为图片地址使用。

```
GET /?jmid=350234&chapter=413446&page=1
```

When `chapter` is a numeric photo ID and `page` is present, the API takes a direct image path and skips the album metadata request. Numeric direct image requests validate chapter id format and page bounds, not album membership. `chapter=@N`, `chapter=all`, and comma-separated chapter lists still use album validation.

Decoded scrambled still images prefer WebP quality 85 when GD supports WebP, and fall back to JPEG quality 85. GIF and non-scrambled images are returned without re-encoding. The image endpoint returns `X-JM-Cache: HIT/MISS` and `X-JM-Image-Codec: webp/jpeg/gif/png/original`.

By default, a request for page `N` schedules a post-response prefetch of `N+1` through `N+10`. The API now treats `N+1` and `N+2` as high-priority pages, then attempts `N+3` through `N+10` as low-priority work. Use `prefetch=0` to disable prefetch for one image request. Prefetch skips pages already in APCu memory cache, stops at the first out-of-range page or upstream failure, and skips low-priority work when APCu free memory falls below `JM_PREFETCH_MIN_FREE_BYTES` or `JM_PREFETCH_MIN_FREE_RATIO`.

Concurrent requests for the same decoded page use an APCu single-flight lock. One worker downloads/decodes the page while other workers wait briefly for the cache entry. Image responses expose `X-JM-Singleflight: hit|owner|hit-after-wait|timeout|disabled` and `X-JM-Cache-Store: stored|skipped-too-large|skipped-low-memory|disabled|hit`.

When a chapter response is generated with album context, image URLs include a `next_chapter` hint when the next reading chapter is known. Near the end of a chapter, the API can preheat the next chapter's first pages without making direct image requests fetch album metadata.

Upstream API domains are scored in APCu with success/failure counts, failure streak, short cooldown, and EWMA latency. `JM_DOMAIN_COOLDOWN_SECONDS` controls the base cooldown. If every domain is cooling down, the API falls back to the original domain order and still tries the request.

Domain discovery no longer performs remote configuration probes while constructing a business request. A request immediately uses a fresh dynamic entry, a bounded stale entry, or the built-in HTTPS fallback list. `JM_DOMAIN_FRESH_TTL` defaults to 86400 seconds; `JM_DOMAIN_STALE_TTL` defaults to another 86400 seconds and `0` disables stale use. The health response reports the selected source, age, fresh/stale deadlines, and whether refresh is suppressed.

When `JM_DOMAIN_REFRESH_DEFERRED=1` (default), a stale/fallback request may register one APCu-leased refresh in a PHP shutdown callback. This is deferred work, not an asynchronous queue: the PHP worker remains occupied until the callback finishes, and the effect on client TTFB/full-response time must be measured in the deployment environment. Each source is capped by `JM_DOMAIN_SOURCE_TIMEOUT_MS=1500`, the whole refresh by `JM_DOMAIN_REFRESH_BUDGET_MS=3000`, and failures are suppressed for `JM_DOMAIN_REFRESH_FAILURE_TTL=60` seconds. Set `JM_DOMAIN_REFRESH_DEFERRED=0` and recreate the container to disable remote refresh immediately while retaining cached/built-in domains.

`chapter=@N`、`chapter=all` 和逗号分隔章节列表会先校验章节属于指定 `jmid`。数字 `chapter` + `page` 快路径为了减少 album 元数据请求，只校验章节 ID 格式和页码范围，不校验 album membership。图片接口不会接受任意外部图片 URL，因此不会作为开放代理使用。

### PHP 解密

```php
require 'index.php'; // 或直接调用 ScrambleDecoder

$json = json_decode(file_get_contents('http://host/?jmid=350234&chapter=413446'), true);
foreach ($json['data']['chapters'][0]['images'] as $img) {
    $raw = file_get_contents($img['url']);
    file_put_contents("page_{$img['index']}.tmp", $raw);
    ScrambleDecoder::decodeFile(
        "page_{$img['index']}.tmp",
        $img['decode_segments'],
        "page_{$img['index']}.jpg"
    );
}
```

### JS 解密（思路）

```js
// 1. 下载图片 → Image
// 2. Canvas drawImage
// 3. 按 decode_segments 段数重排行
// 4. Canvas.toBlob() → 正常图片
```

## 安全机制

### Redis 限流（CC Flood 防护）

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `RATE_WINDOW` | 60s | 滑动窗口 |
| `RATE_MAX_REQUESTS` | 30 | 每窗口最大请求 |
| `RATE_PENALTY` | 300s | 超额封禁时长 |
| 连续违规 3 次 | — | 自动封禁 |

Redis 不可用时优雅降级 — 不限流。

### ID 爆破防护

同 IP 10 分钟内访问超过 100 个不同 jmid → 自动封禁。

### 输入校验

- jmid：严格数字匹配，长度 ≤ 20
- chapter：白名单（仅允许 album 中已存在的 photo_id）
- 批量上限：单次最多 50 章
- cURL：强制 HTTPS，禁止 `file://` 协议

### 错误脱敏

生产模式下（默认），HTTP 500 返回 `"服务器内部错误"` 而非异常详情。

### 响应头加固

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-JM-API-Version: 2026.07.13.2
X-JM-Cache: HIT|MISS
X-JM-Image-Codec: webp|jpeg|gif|png|original
X-JM-Singleflight: hit|owner|hit-after-wait|timeout|disabled
X-JM-Prefetch: scheduled|disabled|skipped-no-apcu|skipped-low-memory|none
X-JM-Cache-Store: stored|skipped-too-large|skipped-low-memory|disabled|hit
Retry-After: 60         (限流时)
X-Powered-By: (已移除)
```

## 架构

```
index.php                   单文件入口
├── JmConfig                常量配置
├── JmHttpClient            HTTP 层（持久 cURL 句柄）
├── JmApiClient             API 层（认证 / 重试 / 解密 / 域名自更新）
├── JmAlbum / JmChapter    数据模型
├── ScrambleDecoder         图片乱序解密
├── JmService              业务编排
├── RedisStore              Redis 封装（可选）
├── SecurityManager         限流 / 爆破检测
└── InputValidator          输入校验
```

## 配置

修改 `JmConfig` 类中的常量即可：

```php
final class JmConfig
{
    // 限流
    public const RATE_MAX_REQUESTS = 30;    // 调大放宽
    public const RATE_PENALTY      = 300;   // 封禁时间(秒)

    // 批量限制
    public const MAX_CHAPTERS      = 50;    // 单次最多章节数

    // HTTP
    public const HTTP_TIMEOUT      = 8;     // 请求超时(秒)
    public const CONNECT_TIMEOUT   = 5;     // 连接超时(秒)
    public const MAX_RETRIES       = 2;     // 每域名重试次数
}
```

API 域名请求路径只读取 APCu 中的 fresh/stale 条目或内置 HTTPS fallback，不会同步等待远程配置源。默认在响应结束阶段以 lease 单飞刷新；该 deferred shutdown 回调仍占用 PHP worker，并不等于独立后台任务。若实测影响完整响应或 worker 饥饿，可设置 `JM_DOMAIN_REFRESH_DEFERRED=0` 后重建容器关闭刷新。

### 性能与安全环境变量

`docker-compose.yml` 使用 `${变量:-默认值}`，因此可通过宿主环境变量覆盖，而不需要修改 PHP 代码。变更后必须重建容器；deferred 域名刷新仍在 shutdown 阶段占用 worker，是否影响 TTFB 和完整响应时间必须以本机测量为准。

| 变量 | 默认值 | `0` / 空值语义与风险 |
|---|---:|---|
| `JM_REQUEST_BUDGET_MS` | `12000` | 不接受 0；单个业务请求共享的上游总预算 |
| `JM_MAX_UPSTREAM_ATTEMPTS` | `6` | 不接受 0；同一业务请求的总上游尝试上限 |
| `JM_LIST_CACHE_TTL` | `60` | `0` 关闭 latest/popular/promote 源页缓存，上游调用会增加 |
| `JM_SEARCH_CACHE_TTL` | `30` | `0` 关闭搜索源页缓存 |
| `JM_WEEKLY_LIST_CACHE_TTL` | `60` | `0` 关闭 weekly filter 源页缓存 |
| `JM_ALBUM_CACHE_TTL` | `45` | `0` 关闭 album 元数据缓存 |
| `JM_WEEK_DEFAULTS_CACHE_TTL` | `600` | `0` 关闭 weekly 默认值 fresh cache |
| `JM_WEEK_DEFAULTS_STALE_TTL` | `3600` | `0` 禁止 weekly 默认值 stale-if-error |
| `JM_CACHE_FILL_WAIT_MS` | `750` | `0` 让并发 loser 不等待 owner，可能增加重复上游请求 |
| `JM_CACHE_FILL_LOCK_TTL` | `15` | lease 秒数；不是禁用开关，必须覆盖 producer 时间 |
| `JM_DOMAIN_FRESH_TTL` | `86400` | 最小 60 秒；动态域名 fresh 窗口 |
| `JM_DOMAIN_STALE_TTL` | `86400` | `0` 禁止使用陈旧动态域名 |
| `JM_DOMAIN_REFRESH_DEFERRED` | `1` | `0` 关闭远程域名刷新，仍可使用现有缓存和内置 fallback |
| `JM_DOMAIN_SOURCE_TIMEOUT_MS` | `1500` | 单个配置源超时，不接受 0 |
| `JM_DOMAIN_REFRESH_BUDGET_MS` | `3000` | 整轮 deferred 刷新预算，不接受 0 |
| `JM_DOMAIN_REFRESH_FAILURE_TTL` | `60` | 失败负缓存秒数，避免每请求重新探测 |
| `JM_PREFETCH_PAGES` | `10` | `0` 关闭普通预取 |
| `JM_PREFETCH_WALL_BUDGET_MS` | `5000` | `0` 不允许后台预取工作 |
| `JM_PREFETCH_BYTE_BUDGET` | `16777216` | `0` 不允许后台预取下载 |
| `JM_PREFETCH_MAX_ACTIVE` | `2` | `0` 关闭全局预取 slot |
| `JM_IMAGE_MAX_COMPRESSED_BYTES` | `33554432` | 安全上限；`0` 会回退默认值，不会取消保护 |
| `JM_IMAGE_MAX_PIXELS` | `80000000` | 安全上限；`0` 会回退默认值，不会取消保护 |
| `JM_CDN_EPOCH` | `1` | 改值会确定性重排 cover CDN，可用于运维切换 |
| `JM_TRUSTED_PROXY_CIDRS` | 空 | 空表示不信任任何转发头；只填写实际可信代理 CIDR |

### 即时性能策略回滚

下面是 `.env` 等价值，保存后执行 `docker compose up -d --force-recreate`：

```dotenv
JM_LIST_CACHE_TTL=0
JM_SEARCH_CACHE_TTL=0
JM_WEEKLY_LIST_CACHE_TTL=0
JM_ALBUM_CACHE_TTL=0
JM_WEEK_DEFAULTS_CACHE_TTL=0
JM_WEEK_DEFAULTS_STALE_TTL=0
JM_DOMAIN_REFRESH_DEFERRED=0
JM_PREFETCH_PAGES=0
JM_PREFETCH_MAX_ACTIVE=0
JM_PREFETCH_WALL_BUDGET_MS=0
JM_PREFETCH_BYTE_BUDGET=0
```

Windows PowerShell 可直接在当前会话设置并重建：

```powershell
$env:JM_LIST_CACHE_TTL = '0'
$env:JM_SEARCH_CACHE_TTL = '0'
$env:JM_WEEKLY_LIST_CACHE_TTL = '0'
$env:JM_ALBUM_CACHE_TTL = '0'
$env:JM_WEEK_DEFAULTS_CACHE_TTL = '0'
$env:JM_WEEK_DEFAULTS_STALE_TTL = '0'
$env:JM_DOMAIN_REFRESH_DEFERRED = '0'
$env:JM_PREFETCH_PAGES = '0'
$env:JM_PREFETCH_MAX_ACTIVE = '0'
$env:JM_PREFETCH_WALL_BUDGET_MS = '0'
$env:JM_PREFETCH_BYTE_BUDGET = '0'
docker compose up -d --force-recreate
curl.exe "http://127.0.0.1:8088/?health=1"
```

恢复 compose 默认值时删除这些会话变量并重建。环境变量只回滚服务器性能策略；扩展端正确性修复、Redis Lua 原子限流、`chapter:v2` / `manifest:v2` CDN 数据结构必须通过版本回退或 commit rollback 恢复，不能声称环境变量会重新启用旧 bug。

部署前后使用同一输入、worker、warm-up 和样本数测量：

```powershell
docker compose build --no-cache
docker compose up -d --force-recreate
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\performance-baseline.ps1 `
  -RuntimeKind docker -RuntimeSourceBinding docker-image `
  -RuntimeImageDigest 'sha256:<本次镜像的64位SHA-256>' `
  -ActualWorkerCount 10 `
  -NetworkConditionId 'same-network-profile-v1' `
  -ResourceProfileId 'cpu2-memory512m-apcu128m-v1' `
  -OutputPath .\performance-after.json -ComparePath .\performance-before.json
```

`ActualWorkerCount` 必须填写该次运行的真实 worker 数，不能照抄 compose 文本。`NetworkConditionId` 与 `ResourceProfileId` 必须由执行者明确命名同一网络、CPU/内存限制配置；`unverified` 不能用于 BEFORE/AFTER 比较。Docker 运行还必须记录实际镜像 digest。比较门会校验两侧 PowerShell/PHP、worker、APCu 容量、静态缓存/预取策略指纹、网络与资源配置完全一致，并要求每个路由至少 100 个成功样本以及非空 p95/p99；伪哈希、缺失字段、矛盾计数或样本不足都会 fail-closed。

报告同时保存声明/实际 worker、`index.php`/Dockerfile/compose/entrypoint SHA-256、APCu hit/miss/expunge/碎片率、并发 client-occupancy ratio，以及一次启用预取后的 wall time、后续命中利用率和本次探针内未利用率。client occupancy 是 `sum(client elapsed)/(batch wall × actual workers)` 的有界客户端估算，不是服务端 CPU 或 worker busy 采样；预取浪费率也只覆盖探针随后读取的第 2～3 页。没有真实 BEFORE 时不得输出百分比提升。

## 生产部署

### Nginx

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/jm;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### PHP-FPM

```ini
; 建议配置
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 1000
request_terminate_timeout = 60s
```

### Redis

```bash
# 安装 PHP Redis 扩展
pecl install redis
echo "extension=redis.so" >> /etc/php/8.5/cli/conf.d/redis.ini

# 确认连接
redis-cli -h 127.0.0.1 -p 6379 PING
```

默认连接 `127.0.0.1:6379`。Docker sidecar 或远程 Redis 可通过环境变量配置，Redis 仍然是可选依赖：

```yaml
environment:
  REDIS_HOST: "redis"
  REDIS_PORT: "6379"
  REDIS_TIMEOUT_MS: "500"
```

## 错误码

| HTTP | 含义 |
|------|------|
| 200 | 成功 |
| 400 | 参数错误（无效 jmid / chapter） |
| 429 | 请求过频 / 异常访问 |
| 500 | 服务器内部错误 |
| 502 | 上游 API 不可用 / 响应异常 |

## 许可

 MIT license | 基于 [JMComic-Crawler-Python](https://github.com/hect0x7/JMComic-Crawler-Python) 的 PHP 移植。

_感谢 [JMComic-Crawler-Python](https://github.com/hect0x7/JMComic-Crawler-Python)_

