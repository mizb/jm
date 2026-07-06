# JM Comic Viewer API

PHP 8 禁漫 API 客户端 — 专辑/章节详情 + 图片 URL + 乱序解密参数。

## 快速开始

单文件部署，放到任意 PHP 8 环境即可运行。

```bash
php -S 0.0.0.0:8080 index.php
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
```

如果要直接使用 GHCR 镜像，可以把 `docker-compose.yml` 中的 `build` 删除，并把 `image` 改成你的镜像名：

```yaml
services:
  jmcomic-api:
    image: ghcr.io/<你的GitHub用户名>/<仓库名>:latest
    container_name: jmcomic-api
    ports:
      - "8080:8080"
    volumes:
      - jmcomic-api-cache:/app/cache
    restart: unless-stopped

volumes:
  jmcomic-api-cache:
```

如果 GHCR 包默认是 private，需要在 GitHub 仓库的 Packages 页面把包可见性改为 public，或在部署机器上先执行 `docker login ghcr.io`。

启动后检查服务：

```bash
curl "http://localhost:8080/?health=1"
curl "http://localhost:8080/?jmid=350234&format=min"
```

如果 Suwayomi 和本服务在同一个 Docker Compose/network 中，后续 Suwayomi 扩展应访问：

```text
http://jmcomic-api:8080
```

如果 Suwayomi 不在 Docker 中，通常访问：

```text
http://127.0.0.1:8080
```

```bash
# 拿目录
curl "http://localhost:8080/?jmid=350234"

# 取单章
curl "http://localhost:8080/?jmid=350234&chapter=413446"

# 取单页解密图片
curl -o page.webp "http://localhost:8080/?jmid=350234&chapter=413446&page=1"

# 批量取
curl "http://localhost:8080/?jmid=350234&chapter=413446,413447,413448"

# 按序号
curl "http://localhost:8080/?jmid=350234&chapter=@5"

# 全部章节
curl "http://localhost:8080/?jmid=350234&chapter=all"
```

## 系统要求

| 依赖 | 说明 |
|------|------|
| PHP ≥ 8.0 | 8.5 已测试 |
| ext-curl | HTTP 请求 |
| ext-openssl | AES-256 解密 |
| ext-json | JSON 编解码 |
| ext-mbstring | 多字节字符串 |
| ext-redis (可选) | 限流 / 缓存加速 |
| ext-gd (可选) | 图片乱序解码 |

## API 接口

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
            "url": "https://cdn-msp.jmapiproxy1.cc/media/photos/413446/00047.webp",
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
| `url` | string | CDN 图片直链 |
| `scramble_id` | string | 图片解密密钥 |
| `decode_segments` | int | 行分割段数。`0` = 未加密，`>0` = 需解密 |

### GET /?health=1

环境诊断。

```json
{
  "code": 200,
  "success": true,
  "diagnostics": {
    "php": "8.5.7",
    "redis": true,
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

接口会先校验 `chapter` 是否属于指定 `jmid`，再从章节图片列表中按 1-based `page` 取图、下载原图、按 `decode_segments` 解乱序并输出图片。该接口不会接受任意外部图片 URL，因此不会作为开放代理使用。

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

- jmid：严格数字匹配，长度 ≤ 200
- chapter：白名单（仅允许 album 中已存在的 photo_id）
- 批量上限：单次最多 50 章
- cURL：强制 HTTPS，禁止 `file://` 协议

### 错误脱敏

生产模式下（默认），HTTP 500 返回 `"服务器内部错误"` 而非异常详情。

### 响应头加固

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
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

## 错误码

| HTTP | 含义 |
|------|------|
| 200 | 成功 |
| 400 | 参数错误（无效 jmid / chapter） |
| 429 | 请求过频 / 异常访问 |
| 500 | 服务器内部错误 |
| 502 | 上游 API 全部不可用 |

## 许可

 MIT license | 基于 [JMComic-Crawler-Python](https://github.com/hect0x7/JMComic-Crawler-Python) 的 PHP 移植。

_感谢 [JMComic-Crawler-Python](https://github.com/hect0x7/JMComic-Crawler-Python)_
