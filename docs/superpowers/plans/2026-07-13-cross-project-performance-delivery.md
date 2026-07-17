# JM API Cross-Project Performance Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在保持现有 API、筛选、章节顺序和图片契约的前提下，完成 API 尾延迟、重复上游请求、预取资源占用和扩展端确定性逻辑错误的修复，并交付可验证、可回滚的版本。

**Architecture:** 保持 PHP 单文件部署和 APCu 内存缓存基线，先增加请求预算、域名刷新隔离、可观测性和确定性测试，再增加规范化短 TTL 缓存与预取预算。扩展端仅修复已确认的 URL、中文化、预取状态和章节匹配问题；不在 APK 中复制服务端图片缓存。

**Tech Stack:** PHP 8.3、cURL、APCu、可选 Redis、Docker Compose、PowerShell 合同/运行测试、Kotlin、OkHttp、kotlinx.serialization、Keiyoushi Gradle/Android 构建。

**Authoritative design:** `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-13-cross-project-performance-design.md`

---

## 文件结构

### API 项目

- Modify: `D:\jm\jmcomic-api-main\index.php` — 请求策略、域名解析、缓存、预取、CDN、诊断。
- Modify: `D:\jm\jmcomic-api-main\Dockerfile` — 版本、运行依赖和可选 OPcache（仅有数据支持时）。
- Modify: `D:\jm\jmcomic-api-main\docker-compose.yml` — 新环境变量和版本。
- Modify: `D:\jm\jmcomic-api-main\docker-entrypoint.sh` — 版本和 PHP 运行参数。
- Modify: `D:\jm\jmcomic-api-main\README.md` — 新配置、指标、部署和回滚。
- Modify: `D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1` — 列表缓存合同。
- Modify: `D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1` — deadline、预取、CDN和资源合同。
- Modify: `D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1` — Docker 版本/配置合同。
- Modify: `D:\jm\jmcomic-api-main\tests\adoption-hardening-contract.ps1` — Redis 原子性和错误边界。
- Create: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1` — 本轮集中静态合同。
- Create: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1` — fixture 上游故障分类与 deadline 验证。
- Create: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php` — 注入 fake transport 的纯策略测试。
- Create: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php` — 可重复的加密成功/timeout/错误上游。
- Create: `D:\jm\jmcomic-api-main\docker-compose.test.yml` — 仅测试环境的 fixture service 和测试开关。
- Create: `D:\jm\jmcomic-api-main\scripts\performance-baseline.ps1` — 可重复基线和前后对比。
- Modify: `D:\jm\jmcomic-api-main\scripts\runtime-verify.ps1` — 避免 HEAD 污染并验证新行为。

### 扩展项目

- Modify: `D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\JmApi.kt` — 中文化、Base URL、预取双向同步、章节匹配、配置缓存。
- Modify: `D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\Dto.kt` — 受检 album/chapter ID 解析、移除无用参数。
- Modify: `D:\jm\jmapi-extension\src\zh\jmapi\build.gradle.kts` — versionCode。
- Modify: `D:\jm\jmapi-extension\tests\extension-contract.ps1` — 新合同。
- Modify: `D:\jm\jmapi-extension\README.md` — 中文设置、版本和反代子路径说明。

---

## Preflight: 双仓库用户改动保护

- [ ] 在 API 仓库运行 `git status --short`、`git diff --stat`、`git diff -- index.php Dockerfile docker-compose.yml docker-entrypoint.sh README.md tests scripts docs`；在扩展仓库运行 `git status --short`、`git diff --stat`、`git diff -- src tests scripts docs README.md .github`；保存输出到交付记录。
- [ ] 如果 Git 不可用，继续实现和测试，但最终报告明确说明无法检查/提交版本历史。
- [ ] 如果目标文件已有用户改动，先理解并保留；本轮修改与用户 hunk 重叠且无法安全合并时才请求用户决策，不得 checkout/reset/revert。
- [ ] 后续所有 `git add` 仅在目标文件无既有用户改动时使用；存在混合 hunk 时必须使用 `git add -p -- <paths>`，禁止把用户无关改动混入提交。

---

### Task 1: 建立失败基线、集中合同和测量脚本

**Files:**
- Create: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`
- Create: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1`
- Create: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php`
- Create: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php`
- Create: `D:\jm\jmcomic-api-main\docker-compose.test.yml`
- Create: `D:\jm\jmcomic-api-main\scripts\performance-baseline.ps1`
- Modify: `D:\jm\jmcomic-api-main\scripts\runtime-verify.ps1`

- [ ] **Step 1: 写入当前实现必然失败的新合同**

`performance-policy-contract.ps1` 必须读取 `index.php`、compose 和 runtime verifier，并断言以下标识存在：

脚本先定义：

```powershell
param(
    [ValidateSet('RequestBudget','Domain','Cache','Prefetch','Resources','All')]
    [string] $Area = 'All'
)
```

各断言按 Area 分组，`All` 执行全部；这样每个阶段能验证本阶段而不被后续尚未实现的合同阻塞。

```powershell
$required = @(
    'JM_REQUEST_BUDGET_MS',
    'JM_MAX_UPSTREAM_ATTEMPTS',
    'JM_LIST_CACHE_TTL',
    'JM_ALBUM_CACHE_TTL',
    'JM_WEEK_DEFAULTS_CACHE_TTL',
    'JM_DOMAIN_REFRESH_DEFERRED',
    'JM_PREFETCH_WALL_BUDGET_MS',
    'JM_PREFETCH_BYTE_BUDGET',
    'JM_PREFETCH_MAX_ACTIVE',
    'prefetch-page-lease',
    'prefetch-slot',
    'X-JM-Upstream-Attempts',
    'X-JM-Upstream-Ms',
    'X-JM-Source-Cache'
)
```

同时断言：

```powershell
if ($runtimeVerifier -match "-Method\s+'HEAD'") {
    throw 'Runtime verifier must not use HEAD for decoded image work.'
}
```

- [ ] **Step 2: 运行新合同，确认按预期失败**

Run:

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1 -Area All
```

Expected: FAIL，首个缺失项为 `JM_REQUEST_BUDGET_MS` 或等价的新合同。

- [ ] **Step 3: 创建性能基线脚本**

脚本参数必须包含：

```powershell
param(
    [string] $BaseUrl = 'http://localhost:8088',
    [string] $AlbumId = '350234',
    [string] $ChapterId = '350234',
    [int] $WarmupIterations = 10,
    [int] $Iterations = 120,
    [int] $Concurrency = 10,
    [string] $OutputPath = '.\performance-baseline.json',
    [string] $ComparePath = ''
)
```

脚本兼容 Windows PowerShell 5.1（并发使用 `Start-Job`），或启动时明确拒绝并要求 pwsh 7。脚本至少测量：health、latest 页 1～4、popular 页 1～4、album 重复请求、图片 MISS/HIT、`prefetch=0` 和并发图片请求。区分 cold/warm，warm-up 不计入分位数；p99 只在有效样本不少于 100 时输出。每个样本保存 status、elapsed_ms、`api_calls`、缓存/上游诊断头，不保存完整搜索词。提供 ComparePath 时输出同条件差异。

- [ ] **Step 4: 在任何行为代码修改前生成 BEFORE 基线**

```powershell
Set-Location D:\jm\jmcomic-api-main
docker compose build
docker compose up -d --force-recreate
powershell -ExecutionPolicy Bypass -File .\scripts\performance-baseline.ps1 -OutputPath .\performance-before.json
```

Expected: `performance-before.json` 存在并包含 latest/popular 页 1～4、album 和图片样本。若 Docker 或真实上游不可用，保留失败输出并明确记录；不得在实现后补做并冒充 BEFORE。

- [ ] **Step 5: 修正 runtime verifier 的图片探测语义**

将 `Try-HeadImage` 改为真实 GET，并使用临时输出或 `Invoke-WebRequest` 接收响应；所有仅检查缓存的请求添加 `prefetch=0`。函数命名改为 `Try-GetImage`，不得保留误导性的 HEAD 名称。

- [ ] **Step 6: 创建确定性 fixture upstream**

`upstream-router.php` 一次定义本计划全部场景：`valid-album`、`valid-list-80`、`valid-empty-list`、`malformed-200`、`valid-week-defaults`、`valid-weekly-list`、`valid-chapter-image`、`valid-image-bytes`、`timeout`、`502`、`429-seconds`、`429-date`、`429-invalid`、`bad-json`、`bad-encrypted`、`scramble-valid`、`scramble-template-missing`、`cdn-502`。valid JSON 必须从 `tokenparam` 读取 ts，按生产端相同 AES/PKCS7 规则加密 payload，不能用未加密假响应绕过真实解析。

Fixture 额外提供仅测试网络可访问的 `/__reset?run_id={guid}` 和 `/__stats?run_id={guid}`；PowerShell 为每个 test 生成 GUID run_id，并通过 `X-JM-Test-Run-Id` 传递。按 run_id/host/endpoint/page/scenario 记录带文件锁的计数；每个 test 先 reset、执行请求、再读取 stats 做精确次数断言，避免并行测试互相污染。计数状态使用 fixture 自己的临时目录，不写入 API `/app`。

`docker-compose.test.yml` 只在 `JM_TEST_MODE=1` 时设置带 scheme 的测试专用多 host 配置：`JM_TEST_API_BASE_URLS` 至少包含 `http://api-good:8090`、`http://api-timeout:8090`、`http://api-502:8090`；`JM_TEST_DOMAIN_SOURCE_URLS` 包含 good/timeout config source；`JM_TEST_CDN_BASE_URLS` 包含 good/failing CDN。全部 host 必须由 compose network aliases 提供，生产 compose 不包含测试变量，生产模式忽略测试配置，生产 URL 继续强制 HTTPS。

测试 compose 将 `./tests` 只读挂载到 API 容器 `/app/tests`，从而可以执行纯策略 PHP 测试；不得给生产 compose 增加该挂载。

为 `index.php` 的 entry point 增加 `JM_API_LIBRARY_ONLY` guard。`upstream-policy-runtime.php` 定义该常量后 require `index.php`，注入 fake `UpstreamTransport` 序列，精确覆盖 DNS/connect/TLS curl errno、Retry-After 秒值/HTTP-date/非法值/预算截断和 scramble fallback，不启动 Web 路由。

- [ ] **Step 7: 创建故障注入运行测试**

`fault-injection-runtime.ps1` 使用：

```powershell
docker compose -f docker-compose.yml -f docker-compose.test.yml up -d --build --force-recreate
```

逐场景设置测试 header/fixture 状态，断言 HTTP 状态、`X-JM-Upstream-Attempts`、总 wall time、deadline、是否切域和缓存写入。Expected: 测试不访问真实 JM 网络且可重复运行。

- [ ] **Step 8: 运行现有静态合同，记录基线**

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\adoption-hardening-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1
```

Expected: 原有合同全部 PASS；新性能合同仍 FAIL。

- [ ] **Step 9: 如 Git 可用，提交测试基线**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add tests/performance-policy-contract.ps1 tests/fault-injection-runtime.ps1 tests/upstream-policy-runtime.php tests/fixtures/upstream-router.php docker-compose.test.yml scripts/performance-baseline.ps1 scripts/runtime-verify.ps1
git commit -m "test: add performance policy baseline"
```

---

### Task 2: 实现统一请求预算和低基数诊断

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`

- [ ] **Step 1: 为预算策略写失败合同**

合同必须断言 `callJson()` 和 `fetchScrambleId()` 都使用统一预算对象/方法，并断言每次尝试重新生成 token；禁止继续出现无上限的完整 domains × retries 双循环。

- [ ] **Step 2: 在路由入口创建共享 RequestContext**

实现 `RequestContext`，内部只创建一个 `UpstreamBudget`。list/search 路由和 jmid/chapter/image 路由各自在进入业务处理时创建一次，并传入 `new JmService($context)`；`JmService` 再把同一实例传给 `JmApiClient`。weekly 的 `/week` + `/week/filter`、promote 多源页和批量章节必须共享该实例。

测试模式下，`RequestContext` 只在 `JM_TEST_MODE=1` 时读取白名单 `test_scenario`，并让上游请求添加 `X-JM-Test-Scenario`。测试 base URL host 必须同时命中 `JM_TEST_ALLOWED_HOSTS=api-good,api-timeout,api-502,cdn-good,cdn-fail,domain-config,127.0.0.1,localhost`；只有这些显式 URL 可使用 HTTP。生产模式忽略所有测试参数/变量并继续强制 HTTPS。

- [ ] **Step 3: 增加请求策略数据结构**

在 `index.php` 中加入等价结构：

```php
final class UpstreamBudget
{
    private int $startedNs;
    private int $attempts = 0;

    public function __construct(
        private int $budgetMs,
        private int $maxAttempts,
    ) {
        $this->startedNs = hrtime(true);
    }

    public function remainingMs(): int
    {
        $elapsed = (int) floor((hrtime(true) - $this->startedNs) / 1_000_000);
        return max(0, $this->budgetMs - $elapsed);
    }

    public function beginAttempt(): bool
    {
        if ($this->attempts >= $this->maxAttempts || $this->remainingMs() <= 0) return false;
        $this->attempts++;
        return true;
    }

    public function attempts(): int { return $this->attempts; }
}
```

环境变量默认：12 秒、6 次；允许 1～60 秒和 1～20 次的安全范围。

- [ ] **Step 4: 让 HTTP client 接收剩余预算**

定义结构化 transport 结果和可注入接口：

```php
final readonly class HttpResult
{
    public function __construct(
        public bool $ok,
        public string $body,
        public int $status,
        public array $headers,
        public int $curlErrno,
        public string $curlError,
        public array $timings,
    ) {}
}

interface UpstreamTransport
{
    public function get(string $url, array $headers, int $timeoutMs): HttpResult;
}
```

`JmHttpClient` 实现接口，使用 header callback 收集小写响应头，返回 curl errno 和 namelookup/connect/appconnect/starttransfer/total timings。使用 `CURLOPT_TIMEOUT_MS` 和不大于剩余预算的 `CURLOPT_CONNECTTIMEOUT_MS`；每次调用都重设 timeout、header callback 和 write target，避免 handle 复用旧状态。

- [ ] **Step 5: 改造 callJson/fetchScrambleId**

规则必须精确实现：

- 网络连接类错误立即切域。
- 首选域对 408/5xx 最多额外一次。
- 备用域一次。
- 总尝试和总 wall time 均受 `UpstreamBudget` 限制。
- 每次 attempt 内重新计算 `$ts/$token/$tokenparam`。
- JSON/decrypt/business 错误不轮询所有域。
- curl errno 明确分类 DNS、connect、TLS、timeout；未知 errno 记录后按网络错误切域。
- 429 从 `HttpResult.headers['retry-after']` 解析秒值或 HTTP-date；非法值不等待，合法值按剩余总预算截断。
- `fetchScrambleId()` 保留 template 缺失/非重试错误立即使用现有 scramble fallback 的契约。

- [ ] **Step 6: 增加诊断聚合**

`JmApiClient` 保存但不暴露域名内容：

```php
[
    'attempts' => 0,
    'upstream_ms' => 0,
    'domains_tried_count' => 0,
    'deadline_exhausted' => false,
]
```

`sendJson()`、`sendError()`、`sendBinaryImage()` 统一从 RequestContext 增加低基数头：`X-JM-Upstream-Attempts`、`X-JM-Upstream-Ms`、`X-JM-Request-Id`、deadline 状态。上游全故障的错误响应也必须包含；错误日志携带 request_id，不返回 query/token/domain 列表。

- [ ] **Step 7: 运行合同和 fixture 测试**

Run Task 1 的五个合同，然后执行：

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\performance-policy-contract.ps1 -Area RequestBudget
docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T jmcomic-api php /app/tests/upstream-policy-runtime.php
powershell -ExecutionPolicy Bypass -File .\tests\fault-injection-runtime.ps1
```

Expected: 旧合同不回归；预算相关新断言 PASS；Retry-After 三种格式、DNS/connect/TLS/timeout 分类和 scramble fallback PASS；weekly/promote 整个路由而非单个 call 不超过总预算容差。

- [ ] **Step 8: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php tests/page-endpoint-contract.ps1 tests/performance-policy-contract.ps1 tests/fault-injection-runtime.ps1
git commit -m "perf: bound upstream request attempts"
```

---

### Task 3: 域名发现改为立即 fallback、响应后受限刷新

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\README.md`

- [ ] **Step 1: 添加失败合同**

断言存在域名缓存 source/age、刷新 lease、失败负缓存和 `JM_DOMAIN_REFRESH_DEFERRED`；断言业务构造路径不再同步串行执行三个 `file_get_contents(... timeout 10)`。

- [ ] **Step 2: 分离读取与刷新**

实现等价接口：

```php
final class DomainResolver
{
    public function resolveForRequest(): array;
    public function scheduleRefreshIfNeeded(): void;
    private function refreshWithinBudget(int $budgetMs): void;
}
```

`resolveForRequest()` 顺序：fresh 缓存 → stale 缓存 → 内置域名，必须立即返回。配置 `JM_DOMAIN_FRESH_TTL=86400`、`JM_DOMAIN_STALE_TTL=86400`；stale=0 禁用。缓存保存 `domains/fetched_at/fresh_until/stale_until`，物理 APCu TTL=fresh+stale。超过 stale_until 后丢弃动态域名并使用内置 fallback，health 报告 source、age、fresh/stale TTL 和过期状态。

名称必须使用 deferred 而不是 async：shutdown callback 仍占用 PHP worker，而且 PHP 内置 server 下客户端是否已完整收到 body 必须实测，不能承诺“客户端无感”或“已解耦”。文档和指标同时测 TTFB、完整响应时间、refresh wall time 和 worker 占用；若仍影响客户端或造成 worker 饥饿，改为独立维护命令/sidecar 定时刷新。

- [ ] **Step 3: 增加刷新 single-flight 与负缓存**

使用 APCu `domain-refresh-lease:v1`，刷新失败写入 `domain-refresh-failed:v1`，TTL 默认 60 秒。远程源单次超时默认 1500ms，总刷新预算默认 3000ms。

- [ ] **Step 4: 确保 health 不触发刷新**

health 只读取 cache/fallback 状态，报告 source、age、refresh_suppressed，不执行远程 I/O。

- [ ] **Step 5: 运行合同并用黑洞配置源做 Docker 故障测试**

Run `powershell -ExecutionPolicy Bypass -File .\tests\performance-policy-contract.ps1 -Area Domain`。Expected: 配置源失败时 list 请求仍在业务 budget 内返回或快速进入正常上游错误；不会先等待约 30 秒。

- [ ] **Step 6: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php tests/performance-policy-contract.ps1 README.md
git commit -m "perf: decouple domain discovery from requests"
```

---

### Task 4: 增加列表源页短 TTL 缓存

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`

- [ ] **Step 1: 写失败合同**

断言 source cache key 包含 schema、endpoint、source_page 和各模式参数；覆盖 `JM_LIST_CACHE_TTL`、`JM_SEARCH_CACHE_TTL`、`JM_WEEKLY_LIST_CACHE_TTL`；断言缓存位置在结果对象构造之前，缓存的是数组而不是 `JmListResult`。

- [ ] **Step 2: 添加通用数组 cache-through helper**

实现等价签名：

```php
private function cachedArray(
    string $class,
    array $keyFields,
    int $ttl,
    callable $validator,
    callable $producer,
): array
```

要求：TTL=0 直接 producer。Canonicalizer 对关联数组按 key 递归排序、list 保序、标量保留类型，再做 JSON + SHA-256。每个 route validator 区分合法空列表与 malformed 200；缺少关键字段、部分解密、异常结果不缓存。

Single-flight 精确流程：cache miss → 随机 token + `apcu_add` 获取 owner lease → owner 再次读 cache → producer/validate/store → `finally compareAndDelete`。loser 在 `JM_CACHE_FILL_WAIT_MS` 内带抖动轮询并二次读 cache；超时后仅在 RequestContext 剩余预算足够时自行 producer，否则返回明确上游超时。lease TTL 使用 `JM_CACHE_FILL_LOCK_TTL`，必须大于单 producer 最大预算且有上限。

- [ ] **Step 3: 接入全部列表类上游 payload**

Key 字段必须分别包含：

```text
latest: endpoint, source_page
popular: endpoint, source_page, category=latest, order
promote-list: endpoint, source_page, section_id
promote-home: endpoint
search: endpoint, upstream_page, order, sha256(normalized query)
weekly-filter: endpoint, page, category_id, type_id
```

latest/popular/promote 使用 `JM_LIST_CACHE_TTL`，search 使用 `JM_SEARCH_CACHE_TTL`，weekly filter 使用 `JM_WEEKLY_LIST_CACHE_TTL`。缓存原始规范化 payload；仍按客户端 page 执行切片，保证分页逻辑不变。query 只以哈希进入 key/日志，不输出原文。

- [ ] **Step 4: 增加诊断**

列表响应增加 `X-JM-Source-Cache: hit|miss|mixed|disabled`；多源 promote 同时发生 hit/miss 时必须是 mixed。debug 日志/JSON 诊断记录 `source_cache_hits/source_cache_misses`，`api_calls` 保持真实上游调用数。

- [ ] **Step 5: Docker 验收**

使用 fixture 的 `valid-list-80` 场景执行精确断言：

```powershell
1..4 | ForEach-Object { Invoke-WebRequest "http://localhost:8088/?list=latest&page=$_&format=min&test_scenario=valid-list-80" }
```

Expected: fixture 计数器显示 source page 0 调用 1 次；页 5 后 source page 1 调用 1 次。再测 popular 不同 order、search 不同 query/order、weekly 不同 category/type，Expected: key 完全隔离。`malformed-200` 不入缓存；`valid-empty-list` 可缓存。Promote 跨两个源页时 Expected: `X-JM-Source-Cache=mixed` 且 hit/miss 计数正确。

同时运行 `powershell -ExecutionPolicy Bypass -File .\tests\performance-policy-contract.ps1 -Area Cache`。

- [ ] **Step 6: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php tests/list-endpoint-contract.ps1 tests/performance-policy-contract.ps1
git commit -m "perf: cache normalized list source pages"
```

---

### Task 5: 增加 album 和 weekly defaults 缓存

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php`

- [ ] **Step 1: 写失败合同**

断言 `fetchAlbum()` 使用 `JM_ALBUM_CACHE_TTL`，`fetchWeekDefaults()` 使用 `JM_WEEK_DEFAULTS_CACHE_TTL` 和 `JM_WEEK_DEFAULTS_STALE_TTL`；缓存值为规范化数组，禁止缓存失败和 ResponseBody。

- [ ] **Step 2: 接入 album cache**

流程必须是：读取规范化 payload cache → miss 时 callJson → 验证/规范化数组 → 写 cache → 每次从数组构造 `JmAlbum`。默认 TTL 45 秒，key `album:v1:<hash(id)>`。

- [ ] **Step 3: 接入 weekly defaults cache**

fresh 默认 600 秒，stale 默认 3600 秒；0 stale 表示完全禁用 stale-if-error。key 使用 `week-defaults:v1:<sha256(canonical endpoint/schema)>`。缓存条目保存 `value/fetched_at/fresh_until/stale_until`，物理 APCu TTL 为 fresh + stale。fresh 过期后先刷新，只有刷新失败且未超过 stale_until 时才返回旧 `{category_id,type_id}`。health 报告 fresh/stale TTL 和 stale fallback 计数。

- [ ] **Step 4: 并发验证**

使用 fixture 对相同 album 发 10 个并发请求。Expected: fixture `/album` 计数严格等于 1，所有客户端成功；不同 album ID 各自严格等于 1 且不串缓存。真实上游冒烟数据可以单独报告，但不能放宽确定性 single-flight 验收。

fixture weekly 测试：fresh hit 不调用 `/week`；fresh 过期 + refresh 成功更新；fresh 过期 + refresh 502 在 stale 窗口返回旧值并增加计数；超过 stale 或 stale TTL=0 时返回上游错误，不静默使用无限陈旧数据。

- [ ] **Step 5: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php tests/list-endpoint-contract.ps1 tests/performance-policy-contract.ps1
git commit -m "perf: cache album and weekly defaults"
```

---

### Task 6: 为预取增加逐页 lease、全局 slot 和资源预算

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\docker-compose.yml`
- Modify: `D:\jm\jmcomic-api-main\docker-compose.test.yml`
- Modify: `D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php`
- Modify: `D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1`
- Create: `D:\jm\jmcomic-api-main\tests\prefetch-policy-runtime.php`
- Modify: `D:\jm\jmcomic-api-main\scripts\runtime-verify.ps1`

- [ ] **Step 1: 写失败合同**

断言存在 wall、byte、active 三类预算、`prefetch-page-lease` 和 `prefetch-slot`；断言 `prefetch=0` 在 schedule 之前返回；断言当前页 HIT 不会无条件重复安排重叠候选页。

- [ ] **Step 2: 建立预取运行状态**

预取统计结构：

```php
[
    'scheduled' => false,
    'attempted' => 0,
    'cache_hits' => 0,
    'stored' => 0,
    'bytes' => 0,
    'wall_ms' => 0,
    'skip_reason' => null,
]
```

- [ ] **Step 3: 对候选页逐页认领 lease**

先生成当前章节和下一章节候选页集合。每个 `{photoId,page,schema}` 必须先取得固定 256-shard 中对应的进程级 authority flock，再写 `prefetch-page-lease` APCu token/TTL 镜像；只把两步都成功的页放入 callback。flock handle 从 schedule 保留到 callback `finally`，APCu expunge/TTL 到期不能让 rival 成为第二 owner。lease TTL 计算为 `ceil((scheduleDelayMs + wallBudgetMs + 5000) / 1000)` 并限制在 5～300 秒；callback 开始和每页 executor 前续租，镜像缺失时由仍持 flock 的 owner 重建。若没有认领任何页，返回 `skipped-pages-covered`，不注册 callback。

- [ ] **Step 4: 原子获取全局 active slot**

不要使用 read-modify-write 普通计数器。按 `0..JM_PREFETCH_MAX_ACTIVE-1` 为 `prefetch-slot:<n>` 取得同样的 authority flock，再写 APCu token/TTL 镜像；slot handle 同样持有到 callback 最外层 `finally`。authority 目录固定为系统临时目录下 `jmapi-prefetch-mutation-lock-v1`，最多 256 个惰性零字节文件且运行时不 unlink；同批 shard 碰撞共享 handle/refcount。`finally` 清理匹配镜像并释放 slot 与全部 page authority；没有 slot 时立即释放已认领 page leases，返回 `skipped-busy`。

- [ ] **Step 5: 增加预算检查**

每次预取前检查：APCu 水位、wall time、累计下载字节、全局 active slot。预算耗尽时停止低优先级；当前页永远不受影响。

- [ ] **Step 6: 保留可回滚默认**

compose 增加环境变量但暂保持 `JM_PREFETCH_PAGES=10`、high priority=2；active/wall/byte 使用保守默认。所有值为 0 时行为定义清楚：pages=0 禁用；max_active=0 禁用预取；byte/wall=0 表示不允许后台工作，而不是无限。

- [ ] **Step 7: 并发运行验证**

10 个客户端同时请求相邻页。Expected: 当前页响应正常；上游下载数有明确上限；相同窗口不会被安排 10 次；`prefetch=0` 不产生任何后续热页。

同时运行 `powershell -ExecutionPolicy Bypass -File .\tests\performance-policy-contract.ps1 -Area Prefetch`。

- [ ] **Step 8: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php docker-compose.yml tests/page-endpoint-contract.ps1 scripts/runtime-verify.ps1
git commit -m "perf: budget and deduplicate image prefetch"
```

---

### Task 7: 修正 CDN、图片资源边界和 Redis 竞争

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\adoption-hardening-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php`

- [ ] **Step 1: 兼容 chapter images 协议并从 JmChapter 源头移除唯一随机 CDN**

fixture 必须覆盖 `images` 的纯字符串、`{"image":"..."}` 对象、混合顺序、合法空数组和 malformed object。增加独立规范化/校验函数：只接受非空字符串或 `image` 为非空字符串的对象；缺 key、空值、null、number/boolean、array 或嵌套 object 必须让整个 malformed 200 安全失败，不能静默丢页、收缩页码或伪装成零页章节。字符串与对象输入必须产生相同的规范化相对 path、页数和 scramble segment。

`JmChapter::fromApiResponse().images` 只保存受检相对路径，例如 `/media/photos/<photoId>/<filename>`，不保存随机完整 URL。filename 必须 trim，拒绝控制字符、`/`、`\`、`..`；photoId 仍按数字规则验证。JmChapter cache 和 reader manifest 都使用该相对 path。materialize 时只从 `JmConfig::CDN_DOMAINS` 或 test-mode allowlist 生成稳定首选 + 健康候选 URL；仅网络/5xx 且总预算允许时切换一次。对外 `source_url` 仍序列化为绝对 URL，decoded API URL 契约不变。

Java 参考实现的 `/comic_read?id=...` 只记录为待 A/B 的实验设计。本 Task 不替换默认生产路径，不新增默认启用的适配器；现有 `/chapter + /chapter_view_template` 始终保留。只有 Task 10 的离线与真实 A/B 证明字段等价、预算和 fallback 正确后，才允许另行评估 disabled-by-default canary。

- [ ] **Step 2: Cover 使用一致性哈希**

选择函数必须等价于：

```php
function configString(string $name, string $default): string
{
    $raw = getenv($name);
    return $raw === false || trim((string) $raw) === '' ? $default : trim((string) $raw);
}

$epoch = configString('JM_CDN_EPOCH', '1');
$index = ((int) sprintf('%u', crc32($albumId . ':' . $epoch))) % count(JmConfig::CDN_DOMAINS);
```

绝不能每次 `array_rand()`。保留绝对上游 cover URL 的现有兼容行为。

- [ ] **Step 3: 在下载阶段强制压缩字节上限**

为图片下载使用 cURL write callback，累计接收字节；超过 `JM_IMAGE_MAX_COMPRESSED_BYTES` 时 callback 返回 0 中止下载。Content-Length 只用于提前拒绝，不能作为唯一保护；chunked 或伪造长度同样必须被 callback 限制。

- [ ] **Step 4: 在 GD 解码前校验像素**

完整压缩 bytes 未超限后先调用 `getimagesizefromstring()`，计算 width × height 并检查 `JM_IMAGE_MAX_PIXELS`，再进入 `imagecreatefromstring()`。记录 bytes、width、height、decode_ms、encode_ms、peak memory。边界失败转换为安全 502 并记录 request_id。

- [ ] **Step 5: 原子化 Redis sliding window**

用 Lua 一次完成 remove expired、count、conditional add、expire、retry-after；Redis 不可达时使用 APCu 短熔断，避免每请求重复 connect timeout。

同时增加 `JM_TRUSTED_PROXY_CIDRS`，默认空表示不信任任何转发头。只有 `REMOTE_ADDR` 命中可信 CIDR 时，`realIp()` 才读取 X-Forwarded-For/X-Real-IP/CF-Connecting-IP；`requestBaseUrl()` 的 scheme/host 转发信息复用同一策略。合同测试伪造 XFF 且无可信代理时，Expected: 仍使用 REMOTE_ADDR；配置可信 loopback 代理后才采用第一跳合法客户端 IP。

- [ ] **Step 6: 升级 chapter/manifest cache schema**

章节 URL/manifest 从唯一完整 CDN URL 改为相对路径时，将 key 升级为 `chapter:v2`、`manifest:v2`。新代码不得读取旧格式对象；不依赖手工 APCu clear 或容器重启才能正确运行。

- [ ] **Step 7: 运行合同和并发测试**

使用 test CDN aliases：primary 返回 502、secondary 返回合法图片。Expected: 同一 album cover URL稳定；章节 `source_url` host 来自 allowlist；图片只快速切换一次；非法 filename 被拒绝；Redis 并发不超放行；伪造 XFF 不绕过限流；禁用 Redis 时现有行为不回归。

同时运行 `powershell -ExecutionPolicy Bypass -File .\tests\performance-policy-contract.ps1 -Area Resources`。

- [ ] **Step 8: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php tests/page-endpoint-contract.ps1 tests/adoption-hardening-contract.ps1
git commit -m "fix: harden cdn image and rate limit paths"
```

---

### Task 8: 修复扩展中文化、Base URL、预取和章节逻辑

**Files:**
- Modify: `D:\jm\jmapi-extension\tests\extension-contract.ps1`
- Modify: `D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\JmApi.kt`
- Modify: `D:\jm\jmapi-extension\src\zh\jmapi\src\eu\kanade\tachiyomi\extension\zh\jmapi\Dto.kt`
- Modify: `D:\jm\jmapi-extension\src\zh\jmapi\build.gradle.kts`
- Modify: `D:\jm\jmapi-extension\README.md`
- Modify: `D:\jm\jmapi-extension\docs\apk-optimization-design.md`
- Modify: `D:\jm\jmapi-extension\docs\ai-delivery-prompt.md`
- Create: `D:\jm\jmapi-extension\scripts\build-with-keiyoushi.ps1`
- Create: `D:\jm\jmapi-extension\scripts\generate-repo-metadata.ps1`

- [ ] **Step 1: 先扩展失败合同**

新增断言：筛选标题为“排序”；设置标题/说明中文；JM regex 为 `{1,20}` 且带尾部数字边界；Base URL 拒绝 query/fragment/userinfo/未指定地址；同源判断按 path segments 比较；URL 构造不使用字符串拼接；预取参数可添加也可移除；按 requested chapter 选择响应；versionCode 为 11（若执行时当前仍为 10，否则递增 1）。

- [ ] **Step 2: 引入规范化配置快照**

使用不可变值：

```kotlin
private data class ApiEndpoint(
    val rawPreference: String,
    val baseUrl: HttpUrl,
    val basePath: String,
)
```

每次读取时若 raw preference 与缓存一致则复用，否则重新验证并替换；不得永久 lazy 缓存。允许反代 path，拒绝 query、fragment、userinfo、0.0.0.0 和 IPv6 未指定地址。

- [ ] **Step 3: 所有 URL 使用 HttpUrl.Builder**

`popular/latest/search/details/chapter/page/getMangaUrl/getChapterUrl` 统一从规范化 `HttpUrl` builder 构建。禁止 `"${apiBaseUrl()}/?..."`。

- [ ] **Step 4: 双向同步 prefetch 参数**

实现等价函数：

```kotlin
private fun applyApiPrefetchPreference(imageUrl: String): String {
    val parsed = imageUrl.toHttpUrlOrNull() ?: return imageUrl
    if (!isSameApiEndpoint(parsed)) return imageUrl
    if (!isDecodedPageUrl(parsed)) return imageUrl
    return parsed.newBuilder().apply {
        if (isApiPrefetchDisabled()) setQueryParameter("prefetch", "0")
        else removeAllQueryParameters("prefetch")
    }.build().toString()
}
```

同源比较必须按 decoded path segments 精确等价；`/api` 只匹配尾斜杠等价的 `/api`，不能匹配 `/api2` 或 `/api/other`。精确 path 后再检查 jmid/chapter/page。尾斜杠归一，编码路径使用 OkHttp pathSegments 语义。外部 CDN 和同 host 其他应用路径不改。

- [ ] **Step 5: 修复 ID 和章节选择**

`JM_PREFIX_REGEX` 使用完整数字边界，例如 `(?i)JM(\d{1,20})(?!\d)`，并确保整体输入/提取规则不会接受 21 位 ID 的前 20 位。`pageListParse()` 按请求 chapter ID 查找 `photoId`，找不到时抛出带 requested ID 的 IOException。旧 album URL 解析先 trim trailing slash，再验证 1～20 位。

- [ ] **Step 6: 中文化**

至少使用：`排序`、`JM API 地址`、`禁用 API 预取` 及对应中文说明。`Dto.kt` 中用户可见的 `Views/Likes/Comments/Chapters/Chapter` 改为“浏览/点赞/评论/章节/第…章”或语义等价中文。筛选值和映射不变。

- [ ] **Step 7: 移除明确无效工作**

删除 `JmAlbumEnvelope.toSManga(baseUrl: String)` 的无用参数，调用方不再为此额外读取/解析 base URL。不要在本任务中实现 APK 图片缓存。

- [ ] **Step 8: 关于 initialized 和 album cache 的决策门槛**

先记录真实 Suwayomi browse/search/direct ID 打开详情时的 `?jmid` 次数和字段完整度。没有证据不得修改 `initialized`。优先使用 Task 5 的 API album cache；只有仍有明显客户端重复且框架允许时，才增加 60 秒、20 项、base endpoint + album ID key 的内存 single-flight。

- [ ] **Step 9: 升级扩展版本并运行合同**

如果当前 versionCode 仍为 10，改为 11，APK 名称为 `tachiyomi-zh.jmapi-v1.4.11.apk`；若已高于 10，则递增 1 并同步所有引用。

同步更新当前合同明确读取的 `docs\apk-optimization-design.md` 和 `docs\ai-delivery-prompt.md`，包括版本、真实 API 路径和中文行为；否则 extension-contract 会失败。

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1
```

Expected: PASS。

- [ ] **Step 10: 使用 Keiyoushi 构建路径验证**

创建 `build-with-keiyoushi.ps1`，固定工作树 `D:\jm\keiyoushi`：不存在时 clone `https://github.com/keiyoushi/extensions-source.git`；删除/复制前用 `Resolve-Path` 确认目标严格位于 `D:\jm\keiyoushi\src\zh\jmapi`；按 workflow 修改 settings 只加载 jmapi；依次运行 Spotless 和 assembleRelease，任一退出码非0立即失败。

```powershell
Set-Location D:\jm\jmapi-extension
powershell -ExecutionPolicy Bypass -File .\scripts\build-with-keiyoushi.ps1
```

- [ ] **Step 11: 本地生成并校验仓库元数据**

`generate-repo-metadata.ps1` 参数：`-ApkPath`、`-OutputDir`；读取 build.gradle 的 versionCode/libVersion，使用 Android build-tools `apksigner`（找不到时明确失败）取得 fingerprint，生成与 workflow 相同的 `index.min.json/index.json/repo.json`，并断言 apk/version/code/baseUrl/package/fingerprint 非空。

```powershell
Set-Location D:\jm\jmapi-extension
$apk = Get-ChildItem -Recurse -File D:\jm\keiyoushi\src\zh\jmapi\build\outputs\apk\release\*.apk | Select-Object -First 1
if ($null -eq $apk) { throw 'assembleRelease APK not found under D:\jm\keiyoushi' }
powershell -ExecutionPolicy Bypass -File .\scripts\generate-repo-metadata.ps1 -ApkPath $apk.FullName -OutputDir .\dist-local
Get-Content -Raw .\dist-local\index.min.json | ConvertFrom-Json | Out-Null
```

若本机没有 Android SDK/apksigner，则触发 `.github\workflows\build-extension.yml`，记录 GitHub Actions run URL、结论和下载 artifact；无 GitHub 凭据时这是明确外部阻塞，不能声称索引已验证。

- [ ] **Step 12: 提交**

```powershell
Set-Location D:\jm\jmapi-extension
git add src/zh/jmapi tests/extension-contract.ps1 README.md docs/apk-optimization-design.md docs/ai-delivery-prompt.md scripts/build-with-keiyoushi.ps1 scripts/generate-repo-metadata.ps1
git commit -m "fix: harden jm api extension requests"
```

---

### Task 9: 统一版本、Docker 配置、文档和回滚说明

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\Dockerfile`
- Modify: `D:\jm\jmcomic-api-main\docker-compose.yml`
- Modify: `D:\jm\jmcomic-api-main\docker-entrypoint.sh`
- Modify: `D:\jm\jmcomic-api-main\README.md`
- Modify: `D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1`
- Modify: `D:\jm\jmapi-extension\README.md`

- [ ] **Step 1: 升级 API 版本**

如果当前 API 仍为 `2026.07.13.1`，目标为 `2026.07.13.2`；若已变化，使用比当前更高且在所有文件一致的新版本。

- [ ] **Step 2: compose 写入全部新开关**

compose/README 至少列出 `JM_REQUEST_BUDGET_MS`、`JM_MAX_UPSTREAM_ATTEMPTS`、`JM_LIST_CACHE_TTL`、`JM_SEARCH_CACHE_TTL`、`JM_WEEKLY_LIST_CACHE_TTL`、`JM_ALBUM_CACHE_TTL`、`JM_WEEK_DEFAULTS_CACHE_TTL`、`JM_WEEK_DEFAULTS_STALE_TTL`、`JM_DOMAIN_FRESH_TTL`、`JM_DOMAIN_STALE_TTL`、`JM_DOMAIN_REFRESH_DEFERRED`、预取三类预算、图片 byte/pixel cap、`JM_TRUSTED_PROXY_CIDRS`。说明默认值、0 的含义、风险和即时回滚命令。deferred shutdown 刷新仍占 worker，是否影响客户端 TTFB/完整响应以实测为准，不得承诺无感。不得把 `0.0.0.0` 写成客户端地址。

- [ ] **Step 3: 修正旧路径引用**

所有新文档和提示词使用：

```text
D:\jm\jmcomic-api-main
D:\jm\jmapi-extension
```

不得复制过期的 `D:\jm\jm-boom-master\jmcomic-api-main`。

- [ ] **Step 4: 更新部署/回滚示例**

至少包含：构建、重建容器、health、基线脚本、关闭 list/album cache、关闭 deferred 域名刷新、关闭预取。说明环境变量只回滚服务器性能策略；扩展正确性、Redis Lua 和 CDN schema 通过版本/提交回退，不能宣称环境变量可恢复旧实现。

- [ ] **Step 5: 运行 Docker 合同**

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1
```

Expected: PASS，版本和环境变量一致。

- [ ] **Step 6: 提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add index.php Dockerfile docker-compose.yml docker-entrypoint.sh README.md tests/docker-runtime-contract.ps1
git commit -m "docs: publish performance tuning controls"

Set-Location D:\jm\jmapi-extension
git add README.md
git commit -m "docs: document extension performance fixes"
```

---

### Task 10: 完整验证、性能对比和最终 bug 审计

**Files:**
- Modify when needed: 本计划涉及的测试、README 和实现文件
- Create: `D:\jm\jmcomic-api-main\docs\performance-delivery-report.md`

- [ ] **Step 1: 运行全部静态合同**

```powershell
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\list-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\page-endpoint-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\adoption-hardening-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1
powershell -ExecutionPolicy Bypass -File D:\jm\jmapi-extension\tests\extension-contract.ps1
```

Expected: 全部 PASS，无警告被忽略。

- [ ] **Step 2: Docker runtime verification**

```powershell
Set-Location D:\jm\jmcomic-api-main
docker compose build --no-cache
docker compose up -d --force-recreate
powershell -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
```

Expected: health、列表、album、图片 MISS→HIT、prefetch、`prefetch=0`、无图片文件落盘全部通过。

- [ ] **Step 3: 运行性能前后对比**

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\performance-baseline.ps1 -OutputPath .\performance-after.json -ComparePath .\performance-before.json
```

必须报告而非猜测：页 1～4上游调用数、album 重复调用数、p50/p95/p99（样本门槛满足时）、图片并发、预取 wall time、APCu 状态。确认 before/after 使用相同 worker、资源、输入、warm-up 和脚本版本。

- [ ] **Step 4: 故障注入**

运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\fault-injection-runtime.ps1
docker compose -f docker-compose.yml -f docker-compose.test.yml logs --no-color jmcomic-api jm-upstream-fixture
```

脚本必须执行并精确断言：

| 场景 | 输入序列 | Expected |
|---|---|---|
| retry-connect | fake curl connect errno → valid | attempts=2，切域，budget 未耗尽 |
| retry-502 | primary 502 → primary 502 → secondary valid | attempts=3，首选仅一次额外重试 |
| retry-429-seconds | 429 Retry-After: 1 → valid | 等待不超过 1 秒和剩余 budget |
| retry-429-date | HTTP-date → valid | 正确换算并按 budget clamp |
| retry-429-invalid | 非法 Retry-After | 不长睡眠 |
| bad-json | 200 非 JSON | 不缓存，不轮询全部域 |
| bad-encrypted | 200 无法解密 | 不缓存，软失败诊断 |
| domain-sources-down | 所有配置源 timeout | 请求立即用 stale/fallback，刷新单飞/负缓存 |
| list-cache | 页1～4同源页 | fixture source_page=0 计数=1 |
| album-singleflight | 10并发相同 ID | fixture `/album` 计数严格=1，10 个客户端全部成功 |
| prefetch-overlap | 10并发相邻页 | 每个候选页 lease owner<=1，active slots不超配置 |
| cdn-failover | primary 502, secondary image | 只切换一次，host均在allowlist |
| proxy-spoof | 非可信 REMOTE_ADDR +伪造XFF | realIp仍为REMOTE_ADDR |

错误场景响应必须包含 request_id、attempts、upstream_ms、deadline 状态。任何计数不符都算测试失败，不得只检查字符串存在。

- [ ] **Step 5: 扩展构建与真实回归**

确认 Spotless、assembleRelease、APK 名称、index.min.json。真实 Suwayomi 检查中文筛选、Popular、Latest、标题搜索、ID 搜索、详情、章节、阅读、预取开关双向切换和反代子路径。

- [ ] **Step 6: 最终 bug 审计循环**

重新阅读 diff 和设计，至少检查：缓存 key 污染、TTL=0、deadline 边界、重复 token、错误缓存、预取递归、当前页受水位影响、base path 越界改写、版本漂移、HEAD 测量污染。发现问题立即补测试、修复、重跑全部相关验证。

- [ ] **Step 7: 写交付报告**

`performance-delivery-report.md` 必须包含：文件清单、行为变化、测试完整输出摘要、性能前后数据、部署命令、回滚命令、无法执行的验证和剩余风险。

- [ ] **Step 8: 最终提交**

```powershell
Set-Location D:\jm\jmcomic-api-main
git add docs/performance-delivery-report.md
git commit -m "docs: record performance delivery evidence"
```

---

## AI 执行纪律

1. 先完整阅读 authoritative design 和本计划。
2. 每个 Task 严格执行“失败测试 → 最小实现 → 通过测试 → 审查 → 提交”。
3. 不得跳过 Phase 0，不能用缓存掩盖未受控的 30～120 秒故障路径。
4. 不得更改现有筛选路由、Popular/Latest 映射、章节顺序和 API JSON，除非计划明确要求并同步更新双方测试。
5. 不得因为某个工具缺失就停止所有工作；先完成其余可验证内容，再列出精确阻塞。
6. 未运行的新鲜测试不能写“已通过”或“已完成”。
7. 完整交付前持续自主诊断和修复，不等待用户逐步确认普通实现细节。
