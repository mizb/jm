# JM API 跨项目性能与正确性深化设计

日期：2026-07-13  
状态：已获用户授权，可据此编写实施计划并自主交付  
主项目：`D:\jm\jmcomic-api-main`  
扩展项目：`D:\jm\jmapi-extension`

## 1. 目标

在不破坏现有 Suwayomi/API 契约的前提下，降低重复上游请求、故障尾延迟、图片预取造成的 worker 占用和扩展端重复解析，同时修复审查中确认的逻辑错误。

交付必须同时覆盖：

1. 可复现的性能基线和故障注入测试。
2. API 请求总预算与域名发现的非阻塞化。
3. 列表源页、album 元数据和 weekly 默认值短 TTL 缓存。
4. 图片预取时间、页数、字节数、并发和窗口去重预算。
5. CDN、APCu 和图片解码的资源边界。
6. 扩展端中文化、Base URL、预取开关和章节选择逻辑修正。
7. 静态合同、Docker 运行验证、扩展构建和真实 Suwayomi 回归。

## 2. 当前基线与不可破坏契约

- API 当前版本：`2026.07.13.1`。
- 扩展当前版本：`1.4.10`，`versionCode = 10`。
- API 端口保持 `8088`。
- APK 只访问 PHP API，不直接访问 JM 上游 API。
- 不新增 decoded image 文件缓存，不新增 `/app/cache` 卷。
- 图片/页面缓存继续使用 APCu；Redis 仅保持现有可选安全功能，不作为图片缓存依赖。
- 空搜索：`list=popular`，排序映射 `new/mv/tf`。
- 标题搜索：`search=<query>`，排序映射 `mr/mv/tf`。
- JM ID 或 album URL：`jmid=<id>`，不得携带排序参数。
- Popular 固定为 `list=promote`；Latest 固定为 `list=weekly`。
- 筛选项保持：`最新 / 最多浏览 / 最多点赞`。
- API 返回 JSON 结构不变；如必须改变，API、扩展、合同测试和文档必须同批更新。
- 只有行为代码变化才升级对应版本；仅文档/测试变化不得升级版本。
- 不重置、覆盖或回滚用户无关改动。

## 3. 方案选择

### 3.1 采用方案

采用“先安全边界和测量，再缓存，再预取吞吐，最后扩展微优化”的渐进方案。

实施顺序：

1. Phase 0：基线、诊断和确定性测试。
2. Phase 1：域名发现非阻塞化 + 请求总 deadline。
3. Phase 2：上游列表源页缓存。
4. Phase 3：album/weekly 默认值缓存。
5. Phase 4：预取预算和窗口级去重。
6. Phase 5：CDN、APCu、运行时和扩展修正。
7. Phase 6：灰度、回滚、完整验证。

### 3.2 暂不采用

- 不立即迁移 PHP-FPM、FrankenPHP、Nginx 或 Caddy。
- 不立即引入持久消息队列、Redis 图片缓存或独立预取服务。
- 不在 APK 中缓存图片字节或直接解码图片。
- 不先做 JSON、MD5、数组循环等微优化。

只有指标证明 PHP 内置 server、APCu 或 shutdown 预取仍是主要瓶颈时，才进入架构升级。

## 4. 已确认问题

### 4.1 API：域名发现可绕过业务 deadline

`JmApiClient` 构造时执行域名解析。APCu miss 后会串行探测 3 个配置源，每个超时 10 秒；全部失败时没有失败负缓存，也没有刷新 single-flight。

影响：冷启动、缓存失效或配置源故障时，一个普通请求可先阻塞约 30 秒；多 worker 可能形成探测风暴。

目标行为：

- 请求立即使用有效缓存、陈旧缓存或内置域名。
- 动态域名刷新只能在响应后或独立维护路径执行。
- 单个源超时 1～2 秒，总刷新预算不超过配置值。
- 刷新使用 APCu lease，失败结果负缓存 30～300 秒。
- 域名 fresh 默认 86400 秒，`JM_DOMAIN_FRESH_TTL`；stale 默认 86400 秒，`JM_DOMAIN_STALE_TTL`，0 表示禁用 stale。条目保存 fresh_until/stale_until，物理 APCu TTL 为 fresh + stale；超过 stale 后只能使用内置 fallback，不能无限使用陈旧动态域名。
- health/liveness 不触发远程刷新。

### 4.2 API：重试存在 domains × retries 放大

当前默认域名 5 个，每个最多 3 次，请求超时 8 秒，最坏可达到 15 次上游调用和约 120 秒尾延迟。时间戳/token 只在方法入口生成，超长重试还可能造成令牌陈旧。

目标行为：

- 每个业务请求有总预算，默认 `JM_REQUEST_BUDGET_MS=12000`。
- 预算在路由入口创建一次，并由同一个 `RequestContext` 传给 `JmService`、`JmApiClient` 以及该路由内的所有 `callJson()`/`fetchScrambleId()`；weekly、promote 聚合和批量章节不得为每次上游调用重新获得完整预算。
- 每个上游调用有总尝试上限，默认 `JM_MAX_UPSTREAM_ATTEMPTS=6`。
- DNS、连接、TLS 错误快速切域，不在同一域连续等待三次。
- 首选域最多一次额外重试；备用域默认一次。
- 每次尝试重新生成时间戳/token。
- 非重试错误立即结束；429 尊重 `Retry-After`，但不得让同步请求睡眠超过总预算。
- `callJson()` 和 `fetchScrambleId()` 使用同一请求预算策略。
- HTTP transport 返回结构化结果：status、body、response headers、curl errno/error、DNS/connect/TLS/TTFB/total timing。`Retry-After` 同时支持秒值和 HTTP-date，非法值忽略，等待值按剩余预算截断。
- `fetchScrambleId()` 的现有安全 fallback 契约必须保留；新 transport 分类不能把非重试 template 错误变成长时间切域。

### 4.3 API：列表源页重复请求

本地分页 20 项，上游分页 80 项，导致本地第 1～4 页重复拉取同一个上游源页。Promote 的 27→20 聚合也会在相邻本地页重复源页；首页 promote 每页重新拉取并扁平化全部 sections。

目标行为：

- 缓存“已成功解密并规范化的上游纯数组”，不缓存最终 `JmListResult` 或 PHP 对象。
- 默认 TTL 60 秒，可用 `JM_LIST_CACHE_TTL=0` 完全关闭。
- 缓存 key 带 schema 版本，并包含 endpoint、source page、order、category、section、type；搜索还包含经过安全哈希的规范化 query。
- latest/popular/promote 使用 `JM_LIST_CACHE_TTL`；search 使用低基数隔离的 `JM_SEARCH_CACHE_TTL`，weekly filter 使用 `JM_WEEKLY_LIST_CACHE_TTL`。三个 TTL 均允许 0 禁用。
- 只缓存完整成功结果；异常、部分解析和空的错误响应不进入缓存。
- 并发 miss 使用短 lease/single-flight，避免 stampede。
- 页 1～4 只产生一次源页调用；页 5 使用下一源页。
- 多源页聚合时诊断为 `hit|miss|mixed|disabled`，并在 debug/日志记录 hit/miss 数；不能把一次 promote 聚合误报为单一 hit。

### 4.4 API：album 与 weekly 默认值重复上游请求

`fetchAlbum()` 当前无缓存。扩展的详情与章节请求 URL 完全相同，多个客户端或刷新操作会重复请求 album。weekly 未指定筛选时每次先请求 `/week`，再请求 `/week/filter`。

目标行为：

- API 端 album 缓存优先于 APK 缓存。
- album 默认 TTL 45 秒，配置 `JM_ALBUM_CACHE_TTL`，允许 0 禁用。
- weekly defaults 默认 TTL 600 秒，配置 `JM_WEEK_DEFAULTS_CACHE_TTL`。
- weekly defaults 允许 stale-if-error：`JM_WEEK_DEFAULTS_STALE_TTL=3600`，0 表示禁用 stale。物理缓存 TTL 为 fresh + stale，health 同时报告 fresh/stale 配置；超过 fresh 后只在刷新失败时使用 stale。
- 缓存规范化数组，不缓存 ResponseBody，不负缓存无法可靠区分的 404/上游故障。
- 缓存 key 至少包含 schema、album ID；并发相同 ID 使用 single-flight。

### 4.5 API：shutdown 预取仍占用 worker

一次图片请求可能触发当前页 + 后续 10 页 + 下一章 2 页。`register_shutdown_function` 不等于后台队列，回调结束前 PHP worker 仍被占用；相邻页面请求还会反复安排重叠窗口。

目标行为：

- 保持现有默认 N+10 兼容，但它不再是不可调整的永久产品契约。
- 增加 `JM_PREFETCH_WALL_BUDGET_MS`、`JM_PREFETCH_BYTE_BUDGET`、`JM_PREFETCH_MAX_ACTIVE`。
- 在 schedule 前对每个候选页获取 `prefetch-page-lease`；相邻请求的重叠页只能被一个请求认领。APCu token/TTL 只是可刷新镜像，不能作为权威 owner，因为内存压力下 APCu 可能整体 expunge。
- page lease 与固定数量的全局 slot（`prefetch-slot:0..N-1`）都以进程级 `flock` handle 为权威 ownership；handle 从 schedule 持有到 shutdown callback 的最外层 `finally`，进程退出由操作系统释放。rival 必须先取得同一 authority flock，拿不到即 fail-closed，不能因 APCu token 过期/消失而成为第二 owner。
- authority flock 只使用 `sys_get_temp_dir()/jmapi-prefetch-mutation-lock-v1` 一个稳定目录和 256 个惰性零字节 shard 文件；完整 APCu key 的 SHA-256 首字节映射 shard。运行时绝不 unlink，避免 inode ABA；同一请求内 shard 碰撞共享 handle/refcount，跨请求碰撞只产生安全的 false-busy，不保存图片或业务数据。
- APCu page/slot 镜像 TTL 仍按 `ceil((schedule delay + wall budget + 5000) / 1000)` 限制在 5～300 秒。callback 开始续租 slot 和全部 claims，每个 executor 前再按 `remaining wall + 5000` 续租 slot 与当前页；镜像缺失时权威 owner 可重建，foreign token、写失败或 authority 丢失则停止执行。callback `finally` 清理匹配镜像并按 refcount 释放所有 flock handle。
- 当前页 HIT 时，只有窗口未覆盖且预算允许才安排预取。
- 高优先级 N+1/N+2 先执行；低优先级受内存、时间、字节和并发预算限制。
- `prefetch=0` 必须同时禁用普通预取和下一章预热。
- 第一轮不引入持久队列；指标仍显示 worker 饥饿后再评估独立 worker。

### 4.6 API：CDN 与图片内存边界

- 章节构建时随机选择一个 CDN，并把完整 host 固化进 6 小时 chapter 缓存；该 CDN 失败时图片下载没有切换。
- Cover URL 随机 CDN 会让同一个 album 在不同响应中出现不同 URL，削弱客户端缓存和请求去重。
- APCu 总空间 128 MiB，而单项上限 100 MiB；单个大图可能挤占大部分缓存。
- GD 解码同时持有压缩字符串、源图、目标图和编码结果，峰值内存远大于文件大小。

目标行为：

- `JmChapter::fromApiResponse()` 的 `images` 只保存经过 filename/path 校验的相对 media path；JmChapter cache 和 reader manifest 同时升级 v2，不把一个随机坏域作为唯一真相。
- 对外 JSON 的 `source_url` 和 decoded page URL 结构保持绝对 URL；在 materialize/序列化边界从 allowlist 候选 CDN 生成。候选 host 不得来自上游任意字符串，filename 禁止控制字符、斜杠、反斜杠和 `..` 路径穿越。
- 图片仅在网络/5xx 下，在总预算内快速切换一次 CDN。
- Cover 使用 albumId 一致性哈希选择稳定 CDN；提供配置 epoch 以便运维切换。
- cURL 下载阶段通过 write callback 强制 compressed-byte 上限，不能只相信 Content-Length，也不能先把超大 chunked body 全量读入内存。
- 完整压缩数据进入 GD 前使用 `getimagesizefromstring()` 校验像素和尺寸，再记录输入字节、像素、decode/encode 时间和 peak memory。
- 按实际 p99 调整单项缓存上限；在没有数据前不得直接写死激进小值。
- 解码前检查像素和 Content-Length 上限，当前请求失败时返回清晰 502，不得导致 worker OOM。

### 4.7 API：运行验证脚本的 HEAD 不是轻量探测

当前路由不区分 HEAD 和 GET。验证脚本使用 HEAD 请求图片时，服务端仍会下载、解码、缓存并可能触发预取，导致探针污染性能结果。

目标行为：

- 运行验证改为 GET 并丢弃 body，真实测量完整路径；检查缓存状态时明确加 `prefetch=0`。
- 如以后实现 HEAD，必须定义为不会下载/解码/预取的独立契约，不能假装等价于 GET。

### 4.8 API：可选 Redis 限流存在竞争与故障开销

- sliding-window 的 remove/count/add 是多次独立命令，并发时可能超放行。
- 安装 Redis 扩展但 Redis 不可达时，普通请求可能重复同步 connect。

目标行为：

- 用 Lua 或事务把 sliding-window 检查变为原子操作。
- Redis 懒连接，短失败熔断，避免每请求重复等待连接超时。
- 默认不信任 `X-Forwarded-For`、`X-Real-IP`、`CF-Connecting-IP`。仅当 `REMOTE_ADDR` 命中 `JM_TRUSTED_PROXY_CIDRS` 时读取代理头；`realIp()` 与 `requestBaseUrl()` 共用同一可信代理策略。
- Redis 修正不得成为本轮图片缓存依赖。

### 4.9 扩展：中文化仍不完整

排序选项已中文，但筛选器标题仍为 `Sort`；设置页标题和说明也为英文。

目标行为：

- 筛选标题改为“排序”。
- 设置页标题、说明和校验提示统一中文。
- DTO 用户可见文本统一中文：`Views/Likes/Comments/Chapters/Chapter` 分别替换为“浏览/点赞/评论/章节/第…章”或语义等价文本。
- 不改变 `new/mr`、`mv/mv`、`tf/tf` 映射。
- Kotlin 行为变化时升级 versionCode、README APK 名称和合同测试。

### 4.10 扩展：预取开关状态不对称

`pageListParse()` 会把 `prefetch=0` 固化进 `Page.imageUrl`，`imageRequest()` 只能再次添加，用户重新启用预取后无法从已加载章节 URL 中移除该参数。

目标行为：

- 最终以 `imageRequest()` 时的当前设置为准。
- 对同源 API 图片 URL 双向归一：禁用时设置 `prefetch=0`，启用时移除 `prefetch`。
- 外部 CDN URL不修改。
- 修改前确认支持的宿主均经过 `imageRequest()`；否则保留 pageList 阶段兼容并实现双向同步。

### 4.11 扩展：Base URL 和同源判断不完整

- 同源判断只比较 scheme/host/port，不比较反代 base path。
- Base URL 当前允许 query、fragment、userinfo；展示 URL 使用字符串拼接，可能生成错误 URL。
- `0.0.0.0` 已拒绝，但 `::` 未指定地址语义相同。

目标行为：

- 允许根路径和反向代理子路径。
- 拒绝 query、fragment、userinfo、`0.0.0.0` 和未指定 IPv6 地址。
- 缓存规范化 `HttpUrl`，偏好变化后立即失效。
- 同源 API decoded-page URL 必须比较 scheme、host、port 和规范化 path segments 的精确等价；`/api` 只匹配尾斜杠等价的 `/api`，不得匹配 `/api2` 或 `/api/other`。随后再检查 jmid/chapter/page 参数。编码路径、尾斜杠和空 path 必须规范化后比较。
- `getMangaUrl()`、`getChapterUrl()` 全部使用 `HttpUrl.Builder`，禁止字符串拼接。

### 4.12 扩展：ID、章节选择和对象初始化风险

- `JM(\d+)` 没有 20 位上限，与其他 ID/API 规则不一致。
- `pageListParse()` 直接取第一个 chapter，没有验证它等于请求 chapter。
- `initialized=true` 可能让列表简化 DTO 被宿主当作完整详情，但直接改为 false 又会增加请求。
- 旧库 URL 尾斜杠可能导致 `substringAfterLast('/')` 返回空 ID。

目标行为：

- 所有 JM ID 统一为完整匹配的 1～20 位；`JM` 前缀正则必须带尾部数字边界，禁止把 21 位 ID 截成前 20 位。
- 单章响应按 requested chapter 查找；不匹配时明确报错。
- 为旧 URL 提供受检 album ID 解析。
- `initialized` 先通过真实 Suwayomi 请求计数和字段完整度验证；不得把它当成盲目减少请求的性能开关。

## 5. 目标架构

### 5.1 请求上下文与可观测性

每个请求创建低开销上下文，至少记录：

- `request_id`
- `route`
- `total_ms`
- `upstream_attempts`
- `upstream_ms`
- `domains_tried_count`
- `domain_discovery_source`：cache/stale/fallback/refresh
- `deadline_exhausted`
- `cache_class` 与 `cache_status`，不记录完整查询词
- `decrypt_ms`、`parse_ms`
- 预取 scheduled/completed/skipped reason、attempted/stored/hit/bytes/wall_ms

普通响应头保持低基数，可使用：

- `X-JM-Request-Id`
- `X-JM-Upstream-Attempts`
- `X-JM-Upstream-Ms`
- `X-JM-Source-Cache`
- `Server-Timing`

域名、完整 query、token、上游 body 不得进入普通响应。

`sendJson()`、`sendError()`、`sendBinaryImage()` 必须从同一 `RequestContext` 注入 request_id、attempts、upstream_ms 和 deadline 状态。错误响应尤其必须保留这些安全诊断头，否则全故障场景无法验收；不得把 query、token、域名列表或内部异常正文放入响应。

同一个路由的所有同步上游工作共享该上下文和同一个 deadline。响应后的 deferred 域名刷新和预取使用各自更短的后台预算，不得重置或延长客户端请求预算。

### 5.2 确定性故障注入

真实 JM 网络只能作为最终冒烟测试，不能作为 deadline、重试和 single-flight 的唯一证据。实现时增加仅测试环境可启用的 fixture upstream：

- `JM_TEST_MODE=1` 时才允许带 scheme 的 `JM_TEST_API_BASE_URLS`、`JM_TEST_DOMAIN_SOURCE_URLS` 和 `JM_TEST_CDN_BASE_URLS`，从而让 Docker fixture 使用 HTTP 而不修改生产 HTTPS 规则。
- 测试 host 必须同时命中 `JM_TEST_ALLOWED_HOSTS`（Docker fixture aliases 或 loopback）；生产模式忽略全部测试变量。生产 API/CDN 继续强制 HTTPS，只有 test mode 的显式白名单 base URL 可使用 HTTP。
- `docker-compose.test.yml` 为同一 fixture 配置多个 API/CDN Host alias，并提供独立 domain-config fixture，以确定性验证切域、配置源黑洞和单 CDN 故障。
- fixture 可返回 valid encrypted JSON、timeout、502、429、bad JSON、bad encrypted payload 和 scramble template。
- PowerShell runtime test 通过 `docker-compose.test.yml` 启动 fixture，精确断言尝试次数、总耗时、缓存和 single-flight。
- 测试 header/场景变量不得进入正常部署 compose，也不得允许用户提供任意外部代理地址。

### 5.3 缓存键

统一格式：

```text
<class>:v1:<sha256(canonical fields)>
```

类包括：

- `list-source`
- `album`
- `week-defaults`
- `domain-refresh-lease`
- `prefetch-page-lease`
- `prefetch-slot`

Canonical key 规则：关联数组按 key 递归排序，list 保持顺序，标量保留类型，最终 canonical JSON 再 SHA-256。缓存类必须提供自己的 payload validator；合法空列表可以缓存，malformed 200 或缺少关键字段不能以“空数组”掩盖并缓存。

Cache-through single-flight 规则：owner 使用随机 token + `apcu_add` 获取 lease，写入前再次检查 cache；loser 有界等待、再次读取 cache，等待超时后按总请求预算决定直接 producer 或失败；owner 在 `finally` 中 compare-and-delete，lease TTL 覆盖最长允许 producer 时间但保持有界。

部署改变缓存结构时升级 key schema，无需全量清 APCu。

### 5.4 故障分类

| 故障 | 同域重试 | 切域 | 缓存 | 域名惩罚 |
|---|---:|---:|---:|---:|
| DNS/连接/TLS/timeout | 否 | 是 | 否 | 是 |
| HTTP 408/5xx | 首选域最多一次 | 是 | 否 | 是 |
| HTTP 429 | 不同步长睡眠 | 视预算 | 否 | 否 |
| HTTP 4xx | 否 | 否 | 否 | 否 |
| business code 非 200 | 否 | 否 | 否 | 否 |
| JSON/decrypt/payload shape | 否 | 默认否 | 否 | 软记录 |

### 5.5 发布与回滚开关

- `JM_LIST_CACHE_TTL=0`
- `JM_SEARCH_CACHE_TTL=0`
- `JM_WEEKLY_LIST_CACHE_TTL=0`
- `JM_ALBUM_CACHE_TTL=0`
- `JM_WEEK_DEFAULTS_CACHE_TTL=0`
- `JM_WEEK_DEFAULTS_STALE_TTL=0`
- `JM_REQUEST_BUDGET_MS`
- `JM_MAX_UPSTREAM_ATTEMPTS`
- `JM_DOMAIN_FRESH_TTL`
- `JM_DOMAIN_STALE_TTL`
- `JM_DOMAIN_REFRESH_DEFERRED=0|1`
- `JM_PREFETCH_PAGES=0`
- `JM_PREFETCH_MAX_ACTIVE=0`
- `JM_PREFETCH_WALL_BUDGET_MS`
- `JM_PREFETCH_BYTE_BUDGET`

以上环境变量保证服务器性能策略可即时回滚。扩展正确性修复、Redis Lua 原子性和 CDN/manifest 数据结构不承诺用环境变量恢复旧 bug；它们必须通过独立提交、版本回退和兼容 key schema 回滚。

chapter、manifest、CDN 结构变化必须升级缓存 key（例如 `chapter:v2`、`manifest:v2`），新代码不得读取旧 6 小时对象格式；部署不依赖手工清空全部 APCu。

## 6. 测试矩阵

### 6.1 API 确定性测试

- 使用测试 fixture 覆盖 valid、timeout、502、429、bad JSON、bad encrypted payload 和 scramble fallback；测试必须能在不访问真实 JM 网络时稳定重复。
- 域名缓存命中、stale、全失败、并发冷启动只刷新一次。
- 连接超时、502、429、坏 JSON、解密失败、business error 的重试分类。
- 总耗时不超过预算加允许的调度误差。
- 列表页 1～4 只调用一个 80 项源页；页 5 切换源页。
- 不同 order、section、category、query 不串缓存。
- 失败响应不入缓存；TTL 到期重新请求。
- album 同 ID 并发 single-flight；不同 ID 隔离。
- weekly 无筛选常态只调用 `/week/filter`，默认值缓存过期后刷新。
- 10 个并发重叠预取窗口，上游下载数有上限。
- `prefetch=0` 完全禁止普通和下一章预取。
- APCu 低水位、单项过大、预取时间/字节预算均不影响当前页成功返回。
- Redis 限流并发原子性和不可达熔断。

### 6.2 扩展合同与运行测试

- 空 query、标题、JM ID/URL 三路请求保持原映射。
- Popular=promote、Latest=weekly 保持不变。
- 筛选显示“排序 / 最新 / 最多浏览 / 最多点赞”。
- Base URL：根路径、反代子路径、尾斜杠、非法 query/fragment/userinfo、0.0.0.0、IPv6 未指定地址。
- 展示 URL 与实际请求 URL 使用同一 builder 规则。
- 预取开关 enabled→disabled→enabled 对当前已加载章节双向生效。
- 外部 CDN 和同 host 非 basePath URL 不被改写；`/api`、`/api/`、`/api2`、编码 path 分别验证 segment 边界。
- requested chapter 与响应 chapter 不匹配时报错。
- JM ID 大小写、1 位、20 位、21 位边界；21 位必须整体拒绝，不能截断匹配。
- 旧库尾斜杠 album URL 能安全解析或给出明确错误。
- 真实 Suwayomi 中 browse/search/direct ID 首次打开、刷新、并发打开的 `?jmid` 数量有记录。

### 6.3 性能验收指标

必须先记录基线，不能没有数据就宣称百分比提升。

Before/after 必须使用同一镜像资源限制、worker 数、输入 ID、网络条件和脚本版本。分别记录 cold（重建容器或使用测试专用 cache namespace）与 warm 阶段；warm-up 样本不计入分位数。p95 至少 40 个有效样本，p99 至少 100 个有效样本；样本不足时只报告原始值、median/max，不得伪报 p99。脚本兼容 Windows PowerShell 5.1，或在开头明确检查并要求 pwsh 7。

最低验收：

- 连续请求 latest/popular 页 1～4：同一源页的上游调用从 4 次降到 1 次。
- 上游完全不可用：业务请求在配置总预算附近结束，不再出现约 120 秒等待。
- 域名配置源全部不可用：业务请求仍立即使用 fallback，刷新失败被短期抑制。
- album 短时间重复请求：上游调用降为 1 次。
- 并发预取：worker busy ratio、上游下载数和 shutdown wall time 有界。
- 不降低成功率，不破坏分页、章节顺序、图片解码和筛选逻辑。

记录：p50/p95/p99、每客户端请求上游调用数、worker busy ratio、APCu hit/eviction/fragmentation、预取利用率和浪费率。

## 7. 版本、文档与交付规则

- API 行为代码变化：统一升级 `JmConfig::APP_VERSION`、Dockerfile、compose、entrypoint、合同测试和 README。
- Kotlin 行为代码变化：`versionCode` 从 10 增加，更新 README APK 名称、合同测试和发布产物验证。
- 新设计中的真实 API 路径固定为 `D:\jm\jmcomic-api-main`；旧文档中的 `D:\jm\jm-boom-master\jmcomic-api-main` 是过期路径，不得复制。
- 每个阶段测试先行，小步提交；不得一次大改后再补测试。
- 任何无法运行的 Docker、PHP、Gradle、Android SDK、GitHub Actions 验证必须明确列出，不得伪称通过。

## 8. 自主执行停止条件

AI 只有在以下情况才可停止：

1. 计划内代码、测试、文档、版本和部署说明全部完成，并有新鲜验证证据。
2. 外部工具缺失或上游不可用，且已经完成所有不依赖该工具的工作，给出精确阻塞证据和用户可执行命令。
3. 发现会改变用户产品目标或公共契约的重大冲突，需要用户决策。

AI 不得因为工作量大、一次测试失败、上下文变长或已写完方案而停止。必须诊断、修复、复测并持续到完整交付。

## 9. 完成定义

- 所有新旧合同测试通过。
- Docker runtime verifier 通过，且不再使用会污染测量的 HEAD 路径。
- API 健康、列表、album、章节、图片 MISS/HIT、prefetch=0、低内存策略均有验证。
- 扩展合同测试、Spotless、assembleRelease 和仓库索引生成通过。
- 真实 Suwayomi 中文筛选、搜索、详情、章节和阅读回归完成；无法执行时明确交由用户验证。
- 版本号、README、Docker 标签、APK 名称和测试期望一致。
- 最终交付报告包含文件清单、测试证据、性能前后数据、部署/回滚命令和剩余风险。
