# JM API 域名逐轮重试设计

日期：2026-07-17  
范围：`D:\jm\jmcomic-api-main`  
目标版本：`2026.07.17.8`  
状态：方案及书面规范已获用户批准；代码、配置、当前态文档与本机聚焦验证已完成，Docker/真实 JM 网络与生产部署保留为外部验收门。

## 1. 目标

把五个健康排序后的 API 域名从当前：

```text
A → A → A → B → B → B → C → C → C → D → D → D → E → E → E
```

改为：

```text
A → B → C → D → E → A → B → C → D → E → A → B → C → D → E
```

优化部分域名发生 DNS、connect、TLS、timeout 或可重试 HTTP 故障时的恢复延迟，同时保留当前健康排序、最多 15 次 attempt、12 秒共享 wall budget、逐次 token 重建和非重试错误失败关闭。

## 2. 方案比较

### 方案 A：保留同域连续三次

优点是首选域发生极短瞬态抖动时，第二次可以在 300ms 后直接恢复；缺点是单域硬故障会消耗多个连接超时和大部分共享预算，后续可用域名可能得不到机会。

### 方案 B：所有失败都无条件立即切域

切换最快，但会把 429、客户端错误和协议错误也错误地扩散到其他域名，既可能规避服务端限流，也会增加无意义请求。

### 方案 C：失败分类驱动的逐轮轮询（采用）

网络类和可重试 HTTP 故障按 `A→B→C→D→E→下一轮`；429 必须先执行受剩余预算约束的等待；客户端、协议、解密和业务错误立即停止。该方案优化部分域名故障，同时保留现有安全边界。

## 3. 调度模型

### 3.1 顺序

1. 每次 `callJson()` 或 `fetchScrambleId()` 开始时，只调用一次 `DomainHealth::orderedDomains()`，冻结本次请求的域名顺序。
2. 外层循环为 `round=0..2`，内层循环遍历全部有序域名。
3. `round=0` 的普通网络/5xx 域名切换之间不等待。
4. `round=1` 和 `round=2` 开始前，生产环境最多等待 300ms；等待必须受 `UpstreamBudget::remainingMs()` 限制。测试模式不真实 sleep。
5. 每次真正发请求前继续调用 `beginUpstreamAttempt()`；15 次 attempt 或 12 秒 wall budget 任一先耗尽即停止。
6. 每次 attempt 继续重新计算 `ts`、`token` 和 `tokenparam`，不得复用首轮认证头。

若配置域名不是五个，仍执行最多三轮；总 attempt 继续由共享预算限制。生产默认五域时序列正好最多 15 次。

这里的 `retry=0/1/2` 是“轮次索引”，不是某个域名上的连续重试次数。`MAX_RETRIES=2` 表示首轮之外再执行两轮；README 和日志解释必须使用同一语义，避免后续维护者把循环重新改回 domain-major。

### 3.3 数量与停止不变量

设冻结后的有效域名数为 `D`、配置的 attempt 上限为 `L`，则实际发出的请求数满足：

```text
attempts <= min(3 × D, L)
```

同时还受 12 秒 wall budget 约束，因此可能在一轮中途停止。预算检查发生在每次真实请求之前；因预算被拒绝的“候选请求”不计为 attempt，也不生成 token。`break 2` 必须退出域名和轮次两层循环，不能继续进入下一轮。

健康排序只在调用开始时计算一次。当前调用产生的成功/失败统计可以影响后续调用，但不得在本次调用中途重排，否则会造成域名重复、遗漏，令顺序测试和故障诊断失去确定性。

### 3.2 延迟规则

- DNS、connect、TLS、timeout、其他 transport failure：本轮立即进入下一域。
- HTTP 408、5xx：本轮立即进入下一域。
- HTTP 429：先解析 `Retry-After`。合法值按当前逻辑等待且不超过剩余 wall budget；缺失或非法值最多等待 300ms，然后进入下一域。不得无等待地通过切域规避限流。
- 下一轮开始前的 300ms 是轮次退避；即使上一轮某个 429 已等待，也不取消该轮次边界。总 wall budget 会限制累计等待。

不引入并行请求、hedged request、指数退避或随机域名顺序。

## 4. 错误分类与结果

| 结果 | 是否继续下一个域名 | 最终行为 |
|---|---:|---|
| DNS/connect/TLS/timeout/transport | 是 | 记录 hard domain failure；有预算则继续 |
| HTTP 408、5xx | 是 | 记录 retryable failure；有预算则继续 |
| HTTP 429 | 等待后继续 | 不标记 hard domain failure；尊重 `Retry-After` |
| HTTP 4xx（408/429 除外） | 否 | `callJson` 立即抛 502 包装；scramble 立即使用安全 fallback |
| envelope JSON/shape | 否 | 立即失败，不切域 |
| business code 非 200 | 否 | 立即失败，不切域 |
| decrypt/payload JSON/shape | 否 | 立即失败，不切域 |
| scramble 模板缺少 ID | 否 | 记录原因并使用安全 fallback |

若至少发生过一个真实上游失败，随后预算耗尽时继续保留该真实失败，不把它覆盖成纯预算异常。只有在没有真实失败时，才返回类型化的 wall/attempt budget exhaustion。

## 5. 代码边界

修改：

- `index.php`
  - 将 `JmApiClient::callJson()` 和 `fetchScrambleId()` 改为 round-major 调度。
  - 两条路径使用显式、同构的 round-major 循环壳；不强行抽取会混合 JSON 抛错语义与 scramble fallback 语义的通用执行器。静态合同同时锁定两处外层 round、内层 domain 的结构，控制重复漂移风险。
  - 保持 `downloadImage()` 的主 CDN + 一个 secondary CDN 策略不变。
- `tests/upstream-policy-runtime.php`
  - 将确定性主序列改为逐轮域名顺序。
  - 保留 token 重建、15 次硬上限、wall budget、429 和所有非重试错误用例。
- `tests/fault-injection-runtime.ps1`、`tests/performance-policy-contract.ps1`、`tests/docker-runtime-contract.ps1`
  - 更新绑定旧 `AAA→BBB` 顺序的合同与 fixture 计数描述。
- Docker、README、当前设计/交付报告和 AI 提示
  - 统一版本为 `2026.07.17.8`，记录新顺序及部署验收条件。

扩展源码、APK 版本、筛选映射、API JSON、图片 CDN 和缓存 schema 不变。

## 6. 测试设计

必须先修改运行测试并观察 RED：

1. 两域首个网络错误、第二次成功：主机序列必须是 `A,B`，修复前实际为 `A,A`。
2. 五域第六次恢复：序列必须是 `A,B,C,D,E,A`。
3. 十五次窗口：必须是 `A,B,C,D,E` 重复三轮，第 15 次仍可恢复。
4. scramble 第六次恢复：与 JSON 路径相同。
5. 两域连续 502 后第三次成功：序列必须是 `A,B,A`。
6. 429 合法/日期/非法 `Retry-After` 保留现有预算边界；非重试 HTTP、坏 JSON、解密、业务和 payload 错误仍只发一次请求。
7. 默认最大 attempt 仍为 15；响应诊断与日志的 `retry=0/1/2` 解释为 round index。

GREEN 后只运行相关门：上游策略、RequestBudget/Domain 静态合同、列表/资源相邻合同、PHP lint 和报告哈希。没有扩展代码变化，不重建 APK；只运行轻量扩展版本引用合同。

## 7. 成功标准

- 健康首域一次成功仍只发一个请求。
- 五域 retryable failure 顺序严格按 round-major 执行。
- 网络/5xx 的域间切换不增加 300ms 人工等待；仅轮次边界和 429 执行等待。
- attempt=15、wall=12s、token-per-attempt、DomainHealth、失败优先级均不回归。
- API 版本与容器配置统一为 `.8`，所有聚焦测试 exit 0。
- 生产最终验收需确认 `X-JM-API-Version: 2026.07.17.8`，并用同一 request-id 日志观察域名按轮次切换。

## 8. Bug、逻辑错误与合理性审计

| 检查点 | 容易出现的错误 | 设计结论与验证 |
|---|---|---|
| 循环嵌套 | 仍以 domain 为外层，实际继续产生 `AAA→BBB` | 两条路径都要求 `for round` 在外、`foreach ordered domains` 在内；运行时断言完整主机序列，静态合同拒绝反向嵌套 |
| 等待位置 | 每个域之间都 sleep，抵消快速切域收益 | 普通瞬态等待只位于外层轮次边界；第一轮域间没有人工等待 |
| 429 | 无等待切域，变相绕过限流 | 每个 429 响应后先执行受剩余预算约束的等待；无效值使用最多 300ms fallback。若 429 恰在轮末，随后轮次边界仍可再次等待，这是两个独立约束且共同受 wall budget 限制 |
| 非重试错误 | 坏 JSON、解密失败或业务错误扩散到全部域 | `callJson()` 立即抛出；scramble 按既有安全 fallback 返回；两者都不切域 |
| 预算耗尽 | 最后的预算异常覆盖真实网络/HTTP 根因 | 已有真实失败时保留真实失败；仅在从未发出或从未得到真实失败时报告类型化预算耗尽 |
| token 时效 | 跨域/跨轮复用首个 `ts/token` | 每次 `beginUpstreamAttempt()` 成功后重新读取时间并构造认证头；运行时测试检查 token 变化 |
| 健康排序漂移 | 本次失败改变同一调用的剩余顺序 | 排序在调用入口冻结一次；健康统计只影响下一次业务调用 |
| attempt 上限 | 三轮与 15 次被误当成两个可叠加上限 | 取 `3×D`、配置 attempt cap 和 wall budget 三者中最先到达者；五个默认域最多正好 15 次 |
| 日志含义 | `retry=1` 被误解为同域第二次 | 文档固定其含义为 round index；生产验收按 request-id 和域名顺序联合判断 |
| scramble 差异 | 为复用代码而把安全 fallback 改成抛错 | 仅共享调度形状，不合并结果处理；原 fallback 契约保持不变 |
| CDN 误改 | API 域名策略意外扩大到图片下载 | `downloadImage()` 明确不在本轮范围，仍只执行既有主/备 CDN failover |

合理性结论：在五域、15 次全部为快速网络/5xx 失败的理想化边界下，旧策略最多包含 10 个 300ms 同域等待，新策略只包含 2 个 300ms 轮次等待，人工退避上限从 3.0 秒降为 0.6 秒；真实耗时仍取决于 DNS/TCP/TLS/上游延迟并受 12 秒硬预算限制。这只是调度模型推导，不是生产性能百分比，也不能替代相同条件 A/B。

## 9. 风险与回滚

- 若 A 仅短暂抖动而 B～E 均较慢，第二次访问 A 会晚于旧策略；这是换取部分域名硬故障更快恢复的明确取舍。
- 多域共享同一限流时，429 可能消耗更多 wall budget；通过强制等待和 12 秒硬上限控制。
- 本机无 Docker，不能把策略测试冒充生产网络验证。
- 两项目无 Git 元数据，无法提交设计或创建 worktree。回滚必须使用部署端保留的 `.7` 镜像/文件，不使用破坏性覆盖命令。

## 10. 自主交付约束

后续 AI 必须先读取本设计、配套实施计划和 `docs/ai-delivery-prompt.md`，然后持续执行到所有本机可完成项具有新鲜验证证据。测试失败不是停止条件；必须复现、定位、最小修复并重跑相关门。只有缺少 Docker、真实生产网络或发布凭据等外部条件时才可停止，并须记录原始错误、剩余风险和可复制命令。不得修改历史 `.1～.7` 审计事实，不得重建未变化的 APK，不得伪造 Git、容器验证或性能百分比。
