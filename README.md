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
ghcr.io/<你的GitHub用户名>/<仓库名>:2026.07.07.7
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
      JM_API_VERSION: "2026.07.07.7"
      JM_PREFETCH_PAGES: "10"
      JM_PREFETCH_HIGH_PRIORITY_PAGES: "2"
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
ghcr.io/<你的GitHub用户名>/<仓库名>:2026.07.07.7
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
JM API version 2026.07.07.7
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
| `section` | `0` | 推荐分区 ID，默认 `0` |
| `category_id` | `1` | 每周推荐分类 ID；不传则自动读取原版默认分类 |
| `type_id` | `1` | 每周推荐类型 ID；不传则自动读取原版默认类型 |

```
GET /?list=latest&page=1
```

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

`order`/`o` 可选，默认 `mr`，可传 `mv`、`mp`、`tf`。

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
  "version": "2026.07.07.7",
  "diagnostics": {
    "app_version": "2026.07.07.7",
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
X-JM-API-Version: 2026.07.07.7
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

API 域名每 24 小时自动从禁漫域名服务器更新，无需手动维护。

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

