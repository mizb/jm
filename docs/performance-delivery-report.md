# JM API 跨项目性能与正确性最终交付报告

日期：2026-07-17  
范围：`D:\jm\jmcomic-api-main`、`D:\jm\jmapi-extension`  
状态：**API `2026.07.17.7` 在 `.6` 的全局 ID 类型门之上，修复单张图片可突破整批预取字节预算，以及列表 `total` 对浮点、负数和溢出值的静默截断；每域三次/最多 15 次及 12 秒总预算不变。两项均完成 RED→GREEN，v1.4.15 APK 无需变更。`.2 / 7A2AC07A…70484` 的性能结果只作为历史证据保留；Docker 多 worker/fault 实跑和 `.7` 真实现场仍待外部环境。**

## 1. 结论与边界

- API 交付版本：`2026.07.17.7`，`index.php=1A3FF904…CD7C`。
- 扩展交付版本：`1.4.15`，`versionCode = 15`。
- 筛选显示已固定为中文：`排序 / 最新 / 最多浏览 / 最多点赞`。
- Popular 固定映射 `list=promote`，Latest 固定映射 `list=weekly`；空搜索、标题搜索和 JM ID/URL 搜索契约均已在 Suwayomi 实际请求中验证。
- 请求预算、短 TTL 缓存、域名刷新隔离、预取资源预算、图片资源边界、Redis 限流、可信代理和扩展 URL/章节逻辑均已实现并通过本机测试。
- 已从 Codex 原始补丁日志逆向恢复 pre-change 源码，并逐文件命中行为修改前记录的四个 SHA-256；获批的 loopback-only 透明 HTTPS harness 已对恢复版 `A88271DC…7A13ED` 与 `.2 / index.php=7A2AC07A…70484` 完成同条件成功路径 A/B。该证据不能改标为当前 `.3`。
- 当前哈希 A/B 中，fixture 上游请求 `397 → 7`（减少 `98.237%`）；latest page 1/page 4/album p50 分别改善 `31.203% / 35.324% / 37.311%`，五路由 p99 均改善；health 与图片 p50/p95 回退。10 客户端图片队列 wall `100.018ms → 102.554ms`，本次回退 `2.536%`。这些结果只覆盖确定性 loopback、Windows PHP built-in 单 worker。
- 2026-07-17 现场出现老版正常而新版 Latest 返回 HTTP 502。`.3` 恢复老版重试窗口后，用户日志仍显示约 1 秒内 generic JmException 502 且无网络失败记录。直接解密真实 `/week` 后确认 category 为数字、type 为字母 slug；严格校验误把成功 payload 当故障。`.4` 分离两类 ID 契约，并继续保留 12 秒 wall budget；JSON/解密/业务错误仍不切域。
- `.4` 部署后的下一条现场日志明确变为 `MalformedChapterException`。对照老版与 Java 解析器发现当前章节 `id` 错误要求原生 string；`.5` 只增加 JSON int → canonical decimal string，并继续执行 requested-ID 等值及 1～20 位校验。浮点、布尔、数组、超长、非数字和错 ID 仍失败关闭；现场恢复仍以同一章节返回 200 为准。
- 随后全局审计证明其他合法整数 ID 已能规范化，但通用 `scalarString()` 会反向接受 bool/float。`.6` 用 string/int 专用门统一 album、series、列表、搜索跳转和 weekly；非法类型不再进入结果或缓存，JSON array/object 与图片文件名安全契约未放宽。
- `.7` 把预取剩余 byte budget 从协调器一路传到 HTTP body collector，超限时以 `budget-bytes` 在解码/缓存前停止；同时将列表 `total` 收紧为可表示的非负整数或纯数字字符串，不再截断 float、负数或溢出值。
- 早先绑定 `2026.07.13.2 / index.php=680AF597…18FB7C` 的 A/B 继续作为独立历史证据保留；不得与当前哈希结果混用。
- 两个目录都没有 `.git`。文件清单来自当前文件系统和实施计划，不是 Git diff；不伪造提交、提交哈希或版本历史。
- 两个交付项目分别是 PHP 和 Kotlin/Android 项目，没有 `go.mod` 或 Go 源码。将其改写为 Go 会改变既定部署/API 架构，本轮未擅自迁移；“使用最新 Go”对当前两项目不适用。

权威依据：

1. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-13-cross-project-performance-design.md`
2. `D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-13-cross-project-performance-delivery.md`
3. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-14-java-reference-adoption-audit.md`
4. `D:\jm\jmapi-extension\docs\apk-optimization-design.md`
5. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-17-transparent-https-performance-harness-design.md`
6. `D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-17-transparent-https-performance-harness.md`
7. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-17-upstream-retry-compatibility-design.md`
8. `D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-17-upstream-retry-compatibility.md`

## 2. 深化后的设计与合理性结论

### 2.1 API

| 领域 | 已交付设计 | 合理性与错误修复 |
|---|---|---|
| 上游调用 | 每个业务请求共享 `RequestContext` 和 `UpstreamBudget`；默认总预算 12000ms、最多 15 次 attempt；每次 attempt 重建 token | 按健康顺序每域最多三次，生产瞬态重试间隔 300ms；保留老版恢复窗口，wall budget 消除其无界尾延迟 |
| 预算耗尽 | 类型化 `UpstreamBudgetExhaustedException`；attempt、wall、bytes 分别映射 `budget-attempts`、`budget-wall`、`budget-bytes` | 修复预算拒绝被误记为 `executor-error`；预取字节上限进入单图 HTTP collector，已有真实 network/502 失败时仍保留真实失败 |
| 域名发现 | fresh → stale → 内置 fallback；远程刷新延后到响应后，并有 lease、失败抑制和测试白名单 | 避免正常请求同步等待域名源；刷新失败不污染当前可用域名 |
| 元数据缓存 | latest/popular/promote/search/weekly 源页、album、week defaults 使用规范化短 TTL；`TTL=0` 真正旁路 | key 包含 schema 与路由字段，只写入完整验证后的数据；malformed/失败响应不缓存 |
| 单飞与预取 | APCu/文件锁 authority、页 lease、全局 slot、续租和 `finally` 释放；wall/byte/active/低内存预算 | 防止多 worker 重复解码和预取挤占；剩余 byte budget 约束单图 body，`prefetch=0` 同时禁止当前窗口、后续页和下一章预热 |
| 图片资源 | chapter/manifest v2 相对 media path；decoded-page 严格 envelope/attestation；压缩字节、像素、容器结构、解码和编码均有限制 | 防止任意 URL、缓存投毒、截断/畸形图片、超大图片和失败结果进入缓存 |
| CDN | allowlist 选择，主 CDN 失败后最多一次有界切换 | 保留容错但不扩大为任意下载代理 |
| 限流与代理 | Redis 单次 Lua EVAL 滑动窗口、连接惰性建立和熔断；转发头只在 peer 命中可信 CIDR 时生效 | 修复非原子 check/increment 与伪造 `X-Forwarded-For` 风险 |
| 可观测性 | request id、attempts、upstream ms、deadline、cache/prefetch 状态和 APCu 碎片指标 | 只暴露低基数诊断，不泄漏 token、完整 query 或域名清单 |

### 2.2 扩展

- Base URL 使用可失效的 `ApiEndpoint(rawPreference, baseUrl, basePath)` 快照；偏好变化即时生效。
- 允许根路径和反向代理子路径；拒绝 userinfo、query、fragment、`0.0.0.0`、`::` 及全零数字 IPv4 变体。
- 所有请求和展示 URL 使用 `HttpUrl.Builder`；同源判断精确比较 scheme/host/port/path segments，`/api` 不会误匹配 `/api2`。
- `imageRequest()` 在最终发请求时双向增删 `prefetch=0`，因此已经加载的章节也能从禁用切回启用；外部 CDN 或同主机其他路径不改写。
- JM ID 统一为 1～20 位并带尾部数字边界，21 位整体拒绝，不会截断成前 20 位。
- 单章响应必须精确匹配 requested `photoId`；找不到就明确失败，不再用第一个章节静默兜底。
- 中文筛选、设置和 DTO 文本只改变显示，不改变 `new/mr`、`mv/mv`、`tf/tf` 请求映射。
- 未在 APK 中复制服务端图片缓存、解码、Redis 或磁盘任务逻辑。

### 2.3 从 `D:\jm\JMComic-Api-Java-master` 采纳的设计

采纳的是可适配当前 PHP/Kotlin 架构的模式，不是整体移植：

- 可注入 transport 与策略运行时测试，用 fake transport 精确覆盖 DNS/connect/TLS/timeout/429/502/坏 JSON/解密失败。
- 域名状态、刷新与业务请求解耦，并保留 stale/fallback/失败抑制。
- 协议 payload、资源路径和图片容器先验证再进入缓存。
- chapter/manifest 使用相对资源路径，CDN 在受信 allowlist 内选择。
- 失败分类、初始化/刷新状态和低基数指标显式可观测。

明确没有采纳：

- JVM 下载任务树、双线程池、磁盘临时文件和进程级全局可变状态。
- 任意 URL 下载、全域同步探活和 APK 直连/解码上游。
- 未经真实 A/B 的 `/comic_read` 生产替换；旧 `/chapter + /chapter_view_template` 契约继续保留。

## 3. 交付产物与哈希

### 3.1 API

| 文件 | SHA-256 |
|---|---|
| `jmcomic-api-main/index.php` | `1A3FF904F9319E13A7768AF754064F12CC6CE2B35B86406D9DBBBD885948CD7C` |
| `jmcomic-api-main/Dockerfile` | `D8A9092B4BD8715C93CB07120E908D6F134B6F0F1BBC5206AF7A6FB43D10DDB7` |
| `jmcomic-api-main/docker-compose.yml` | `415AFF049CD010B0C69C81C1B58010184ED38A50B9DFFC8F7B92BD7361DB28C9` |
| `jmcomic-api-main/docker-compose.test.yml` | `ED29DE1F902F37BE99B6A62406A61571AA0714B11C64A1E7E04AD78984B95C94` |
| `jmcomic-api-main/docker-entrypoint.sh` | `8F43F44CAF3017D03DC28AB39242CA2035915DE1A5F21B31DC99111CD6C7E85B` |
| `jmcomic-api-main/README.md` | `D80FD02D2C192E3A8F00E0E2ABC704A8799AD34DA3CB437A693D90CD0B8F174D` |
| `jmcomic-api-main/.github/workflows/docker-build.yml` | `E902C90F1BA78574B5A5A144A06341EB35321159A6E1AAB53C06CC660D331D4D` |
| `scripts/performance-baseline.ps1` | `C4B65EA3CC810F0E3C5E20523A5E389C0B303B0539263A40138A054E18C0A65F` |
| `scripts/performance-evidence.ps1` | `35238705319F7E7344F200A2D85D71C903124FA8BF52A304350C01CBD342D5F0` |
| `scripts/runtime-verify.ps1` | `68F2DF3C4CDAC6593DBDF846B2668E080FB6971E3A72835B3D7B09DD74D542BE` |
| `scripts/transparent-https-performance.ps1` | `CC3362851AEC1E209ABC66BB7B419DFC5DF9DB7F93A6ABB5A4CEE36895BEF6D9` |
| `tests/upstream-policy-runtime.php` | `EA425DDD7D1B6DDAA8C1BBB31C68AE2AF62C07413E8F636F11DD4B507E65855F` |
| `tests/prefetch-policy-runtime.php` | `2CF1A57661CE220AF9AC819FE9C8445C68A15568FA20188661CCA598DF7D1BA7` |
| `tests/fault-injection-runtime.ps1` | `D4C766A4CF00734ACF6EC7E7C51F21E0D03B18BA20EEBE8347077CC819C79D3F` |
| `tests/docker-runtime-contract.ps1` | `0DDECC482BB493DE92105E809DF75FBD0BBA16A08C78983E58C07A65C4B5660A` |
| `tests/resource-policy-runtime.php` | `39F890B9F746CFFBE422D575AA64D629A73356125D796428EA4B5CB2EB706922` |
| `tests/adoption-hardening-contract.ps1` | `AD799783555BE437E0A0E29CCD9E4929002815B286BEA906FB858182D7EAE8F5` |
| `tests/list-endpoint-contract.ps1` | `2956DF96772FC5D048C8798DA8F3EC464A7DAF43619CA4919D3C049F37234C46` |
| `tests/performance-policy-contract.ps1` | `E7DDB2134075E20701038224D4C8EBC669AA7C36A42C6077696A409D0A4F1FAD` |
| `tests/fixtures/transparent_https_proxy.py` | `CD97F28D9577A13BA47CA7D79E017611A2993BC96213BD5CF105FB95879808E2` |
| `tests/fixtures/transparent_https_probe.php` | `A3A41A8D61CE5264D41590E77EF3E50F49D255BB8C63611A0DCC354A37AB2F0F` |
| `tests/fixtures/upstream-router.php` | `1648E7C54DCC243058F6CFBE96EA2E28F64091CBCF88109E6308223891F3DA2B` |
| `tests/transparent-https-proxy-runtime.ps1` | `3F0FC3C69155F4B86E69877AACA153F2B9ED172FC3BC9C733172EE84D158CDEF` |
| `tests/transparent-https-performance-runtime.ps1` | `BAEA9275BD25A990C5C94DE8625FD60CA7DA85B8EC6C484A10B55002DE078123` |
| `docs/superpowers/specs/2026-07-17-transparent-https-performance-harness-design.md` | `4E1755D3C2FB8FF0BBDE34077E6BB1F7B4377478AFC1F02A845454A334863D52` |
| `docs/superpowers/plans/2026-07-17-transparent-https-performance-harness.md` | `5696EBAFEB40CF49E33DB787184F4BB9C8A1B8709ABC66B048E655381EEA998C` |
| `docs/superpowers/specs/2026-07-13-cross-project-performance-design.md` | `01A64EC65ED91C4C3C28CC43AB3F70DC9449E440169D72F13B24244A24E0ABEC` |
| `docs/superpowers/plans/2026-07-13-cross-project-performance-delivery.md` | `415D9AF0A7AB6C6B7DD8E8FE8EEDAD83449AF4B1465CC17B20EFC4AAAC1488A6` |
| `docs/superpowers/specs/2026-07-17-upstream-retry-compatibility-design.md` | `6B93D60AFD64FA699C24850F015330335F7E86619BB6DBEA74461F13EC1061DE` |
| `docs/superpowers/plans/2026-07-17-upstream-retry-compatibility.md` | `25C9191E600D6548CB099BC6EACB9DA59D1AD61186A812B0FA26930D9759CE0E` |
| `docs/bug-hunt-2026-07-17.md` | `6619E71A5AAE4D1D745E6D86C928E76FCF68CB2CDD6CECA81A8267AB28391ECC` |
| `docs/ai-delivery-prompt.md` | `1F757C6EB49A5ECA04F8EF091CD04B087F4E4C7FEDAB18B7163DA4CB41417CD8` |
| `docs/advanced-reader-optimization-design.md` | `C37DB920D6A9C455979A26F39D30E36BD2016657516F8652A0A24B71C0C8768C` |
| `docs/advanced-reader-optimization-ai-prompt.md` | `ACB9FCD812360727AAF91C283CF0C62E79F4D429524580CE01DEC23824FEFEF6` |
| `performance-evidence/transparent-https-common-ab-20260717.json` | `47044F43CF37BF16E77F2B8C6DEA76A28FF0C74E348C6890FD80B0179CAB8B77` |
| `performance-evidence/transparent-https-current-smoke-20260717.json` | `049CF4F97721D49BD2FE2A889F9805D92895B7BA1083A35C27C42A63F8C64C51` |
| `performance-evidence/transparent-https-current-ab-20260717.json` | `6ECB423EFE3A2B66B196B1AEC9D15D96CA8F1DB0732ABD01181A24F5CDB2F9B5` |
| `performance-evidence/transparent-https-v172-smoke-20260717.json` | `9A48571186B744346991C8A21FC517D869BF7B8120CDC1960AA63EE76C9A5BE1` |
| `performance-evidence/transparent-https-v172-ab-20260717.json` | `31FC02D295FF4F5F25CA4C0AEB46DEF1EB73092E7AA095D628002CCFF05524C9` |
| `performance-evidence/performance-after-v172.local-fixture.json` | `F709B6EE207B5A9DA790D51DC7F6A88C4FDE3D4D23AF51C12C986E9042A928E5` |

### 3.2 扩展

| 文件 | SHA-256 |
|---|---|
| `jmapi-extension/README.md` | `74CBB1CF0241F4E198F19BCACB0842CD3457DD634A20132D78322FE232A99ED8` |
| `src/zh/jmapi/build.gradle.kts` | `955A6AB1E5B41C47CC46C11BE5A1351121933FA85B3158701D57D042D583E461` |
| `src/zh/jmapi/src/eu/kanade/tachiyomi/extension/zh/jmapi/JmApi.kt` | `B7BCD1617B5277868D0A0ABEE6060D4613F2D4D54BF5667366B0532308316E3D` |
| `tests/extension-contract.ps1` | `9242B6AEEEA721C788953172907C8B45CBFBA5C88551B220154B5B94F657A6DB` |
| `tests/extension-safety-contract.ps1` | `6E7752F7AFCFC810289E7A99F3FBD7EB684BE950618259A8E5FC4F28FF71A741` |
| `scripts/build-with-keiyoushi.ps1` | `79F27AE74020352784068EE020BD7A25EB3874341E96D144932B301CE77ECAC3` |
| `scripts/generate-repo-metadata.ps1` | `FD37F1EBFBD604B2F6345B4B0416425BF108D48D505B155A614574D470D32D1E` |
| `scripts/path-safety.ps1` | `2FD3C44A58B4EE046EA1B5F5B0FF95C115359165A176426A73D0CC678F18411E` |
| `docs/apk-optimization-design.md` | `B3BDB1DAD4D9ABD61C9C7A0269BB4454466FAE13C4C32EC4B32C89B4E3A9A22F` |
| `docs/ai-delivery-prompt.md` | `AB51FD71138D091FB58C72D58DE6B584032236DBF5AA448D4B40600797738F96` |
| `dist-local/apk/tachiyomi-zh.jmapi-v1.4.15.apk` | `A1FD20677F53784CEAED728BCFC0A44E40DD53D35C47A582E1A3195D51B57872` |
| `dist-local/index.min.json` | `A387CFCB4AD236E92114AFD4B29583E011B1CEB94AFB11C269F4BA348811D8BC` |
| `dist-local/index.json` | `A387CFCB4AD236E92114AFD4B29583E011B1CEB94AFB11C269F4BA348811D8BC` |
| `dist-local/repo.json` | `4CE3C2A9D303E048943B3729A0CA1D370EA2526627987D3D07C9612CBF0EEE66` |

- APK：`D:\jm\jmapi-extension\dist-local\apk\tachiyomi-zh.jmapi-v1.4.15.apk`
- APK SHA-256：`A1FD20677F53784CEAED728BCFC0A44E40DD53D35C47A582E1A3195D51B57872`
- package：`eu.kanade.tachiyomi.extension.zh.jmapi`
- versionCode/versionName：`15 / 1.4.15`
- compile/target SDK：`37`
- v1/v2 签名：通过
- 签名证书 SHA-256：`c285ca414e833f61326e1104556114c4bb2db46686b4289576b2a758686ca080`
- `index.min.json` 与 `index.json` SHA-256：`A387CFCB4AD236E92114AFD4B29583E011B1CEB94AFB11C269F4BA348811D8BC`
- `repo.json` SHA-256：`4CE3C2A9D303E048943B3729A0CA1D370EA2526627987D3D07C9612CBF0EEE66`
- Keiyoushi 构建 APK 与 `dist-local` APK 哈希完全一致；构建后无 `.jmapi-stage-*` / `.jmapi-backup-*` 残留。

## 4. 本轮新鲜验证

验证日期：2026-07-17。以下均读取了本轮命令的 exit code 和最终输出。

| 验证 | 结果 |
|---|---|
| API 静态合同 | 本轮相关 list、docker、adoption hardening、performance Prefetch/CacheList 均 exit 0；未重跑无关 page 全量合同 |
| 上游策略运行时 | PASS；新增列表 total 合法最大值以及 float/负数/非有限/溢出拒绝；原有同域三次、第 15 次恢复、502、429、协议/解密/业务错误、scramble fallback、域名源失败及预算分类继续通过 |
| `.3` 本机真实上游烟测 | 策略生效：HTTP 头为 `version=.3`、`attempts=15`、`deadline=0`，6381ms；日志逐域 `retry=0/1/2`。本机网络路径仍 reset 并返回 502，不能冒充用户服务器结果 |
| `.4` weekly type 兼容 | RED 精确抛出 `Weekly defaults unavailable`；GREEN 完整执行 `/week → 缓存校验 → /week/filter`，HTTP 200、`version=.4`、`attempts=2`、结果含 `11/hanman` |
| 预取策略运行时 | PASS；executor 收到剩余 byte budget，页内超限映射 `budget-bytes`；既有 attempt/wall 与真实 `executor-error` 分类继续通过 |
| 资源策略 | 本轮聚焦 `compressed-body-limit` 1/1 PASS；此前 73/73 全量结果保留，未为本轮重复执行 |
| catalog order | PASS |
| fault 本地自测 | 并发屏障 PASS；fixture 锁定时 500/`ok=false`、释放后 200/`ok=true`；bootstrap 安全 500 PASS |
| 本地列表缓存 | latest=1+1、popular=2、search=3、weekly=3、empty=1、malformed=2、redirect=1、promote=mixed、disabled=2 |
| 本地元数据缓存 | album、week fresh/refresh/stale fallback、`TTL=0` 和 malformed 边界全部 PASS |
| 延后域名刷新 | PASS；domain source calls=1；隔离运行曾为 first=42ms/second=16ms，本轮与其他运行测试并行时为 65ms/737ms，均未重新同步探测配置源 |
| 资源 HTTP | PASS，含端口碰撞自检 |
| Redis | Redis 3.2.100；16 workers，allowed=5、rejected=11、EVAL=16；时钟回退不超发，breaker fail-open/suppressed/recovered |
| 输入/fixture 合同 | 输入校验、资源 fixture 与 fault harness 三项自身安全测试全部 PASS |
| PHP lint | 本轮变更相关 `index.php` 与 3 个 PHP 测试 4/4 无语法错误；此前 10/10 全量结果保留 |
| PowerShell AST | 两项目 23 个 `.ps1`，0 错误 |
| YAML | PyYAML 6.0.3 解析两项目 4 个 YAML：两个 workflow、生产 compose、测试 compose；0 错误 |
| 扩展合同 | 主合同 PASS；23 项 junction/race/rollback/manifest/version/path 安全用例全部 PASS |
| 交付哈希 | 报告列出的 API/扩展文件 54 项逐项重算，0 mismatch |
| Android 构建 | v1.4.15 的 `spotlessApply` 与 `assembleRelease` 均 `BUILD SUCCESSFUL` |
| APK/元数据 | v1.4.15 aapt2 manifest、v1/v2 签名、实际证书、3 个 JSON 回读、APK 名称/版本/哈希一致性全部通过 |
| 真实 Suwayomi | 2.3.2243 + Java 21；v1.4.15 installed=true/hasUpdate=false，中文筛选/设置、5 条浏览搜索路径、详情、章节、12 页与 WebP 图片通过；预取 disabled `0→1`、重新启用后保持 `1` |
| 透明 HTTPS 代理 runtime | PASS；listen/upstream 仅 loopback、真实 TLS/SAN/Host 转发、非白名单 403、加密 config、系统 CA 隔离和受控清理 |
| `.2` 哈希同条件 A/B smoke（历史） | PASS；`A88271DC…7A13ED → 7A2AC07A…70484`，warmup=1、iterations=2、concurrency=2；`comparable=true`、loopback/cleanup 通过；不代表 `.3` |
| 历史哈希绑定的正式成功路径 A/B | PASS；warmup=10、iterations=120、concurrency=10；BEFORE/AFTER 各 600 个 warm 样本全部 status 200 且业务合同通过，`comparable=true`；不代表当前 `index.php` |
| `.1` 哈希正式成功路径 A/B | PASS；`A88271DC…7A13ED → 53A15D40…A3B5C`，warmup=10、iterations=120、concurrency=10；BEFORE/AFTER 各 600 个 warm 样本全部 status 200、`contract_valid=true`、`comparable=true`；不代表 `.2` |
| `.2` 正式成功路径 A/B（历史） | PASS；`A88271DC…7A13ED → 7A2AC07A…70484`，BEFORE/AFTER 各 600 个 warm 样本全部 status 200、`contract_valid=true`、`comparable=true`；不代表 `.3` |
| 当前正式证据独立重算 | PASS；逐路由从 120 个原始样本重算 p50/p95/p99，复核 397→7 fixture 次数、源码/四文件哈希、临时 CA 不在系统根、进程/环境/临时目录清理；报告 SHA-256 `31FC02D2…24C9` |
| `.2` after-only 深度证据（历史） | PASS；11 路由各 120 个 warm 样本零失败；APCu hit/miss/inserts/expunges/fragmentation 完整；预取单事件归因 verified，stored=7、观察 HIT=2；不代表 `.3` |

静态 `docker-runtime-contract.ps1` 只证明 compose/runtime verifier 的文本合同，不能替代真实 Docker 运行。低内存场景由 standalone runtime verifier 与 fault matrix 的组合门禁覆盖，其中强制阈值覆盖及 `skipped-low-memory` 运行断言位于 fault matrix；当前机器未执行该 Docker 组合门禁。

### 4.1 实施计划 Task 1～10 完成审计

| 计划任务 | 当前证据 | 审计结论 |
|---|---|---|
| Task 1 基线、合同、fixture | 集中合同、加密 fixture、纯策略测试、fault/runtime、after-only 和透明 HTTPS common-denominator A/B 均存在并通过；`performance-before.unavailable.json` 仍保留原始时间点未测量的事实 | 精确 pre-change 源码已恢复；没有伪装原始 BEFORE，而是用恢复源码在获批的新 harness 中重新运行并单独标注证据模式 |
| Task 2 统一请求预算 | 默认 12 秒 wall/15 次 attempt；网络错误与可重试 HTTP 状态按域最多三次，生产重试间隔 300ms；确定性第 15 次恢复通过 | 完成 |
| Task 3 域名刷新隔离 | fresh/stale/fallback、lease、失败抑制、health 不触发 I/O 和本地黑洞刷新测试通过 | 本机完成；Docker worker 占用仍属于第 7 节外部验收 |
| Task 4 列表源页缓存 | latest/popular/search/weekly/promote、空值/malformed/redirect/`TTL=0` 精确计数通过 | 本机完成；真实 compose 多 worker 复验待 Docker |
| Task 5 album/week 缓存 | 同 ID、不同 ID、fresh/refresh/stale/expired/`TTL=0` 精确计数通过 | 单 worker 与策略完成；10 worker owner/loser 只能在 Docker 环境最终验收 |
| Task 6 预取预算与去重 | authority flock、APCu 镜像重建、foreign token、续租、slot、wall/byte/active、`prefetch=0` 和预算停止原因测试通过 | 本机策略完成；compose 多 worker overlap 待 Docker |
| Task 7 CDN/图片/Redis | chapter/manifest v2、相对路径、CDN failover、压缩字节/像素/容器/解码边界 71/71；真实 Redis 16 worker 原子限流通过 | 完成 |
| Task 8 扩展修复 | 中文化、URL builder/basePath、ID/章节、页面 URL 权威来源和预取双向同步合同通过；v1.4.15 构建、元数据、签名和 Suwayomi 实际请求通过 | 完成 |
| Task 9 版本与文档 | API 统一 `2026.07.17.7`；扩展 README/Gradle/APK/index 统一 v1.4.15/code 15 | 完成 |
| Task 10 完整验证 | `.7` 聚焦预取页内 byte cap、严格列表 total、既有上游/资源/扩展合同与 PHP lint；正式 A/B 与 after-only 深度证据绑定 `.2` 保留 | `.7` 本机相关回归完成并等待真实现场复验；Docker runtime/fault matrix 仍受外部环境阻塞 |

Git 提交步骤属于所有 Task 的条件性步骤。两个目标目录从开始到当前都没有 `.git`，因此这些步骤按计划 Preflight 第 49 行记录为“不适用/不可执行”，不能用无来源提交代替。

## 5. 性能证据

### 5.1 `.2` 透明 HTTPS 同条件成功路径 A/B（`.3` 前历史证据）

正式证据：`D:\jm\jmcomic-api-main\performance-evidence\transparent-https-v172-ab-20260717.json`  
SHA-256：`31FC02D295FF4F5F25CA4C0AEB46DEF1EB73092E7AA095D628002CCFF05524C9`  
模式：`historical-common-denominator-v1`（表示基线由权威快照恢复，不表示 AFTER 是历史旧版）  
生成时间：`2026-07-17T07:54:38.9927075Z`

证据严格绑定：

- BEFORE：API `2026.07.13.1`，`index.php=A88271DCD759CDB4992FB2C49C963441FDA7028201E991211CD4DE4B497A13ED`。
- AFTER：API `2026.07.17.2`，`index.php=7A2AC07AE89CA4EE0ADB4F4B8435C8038CE656769295FD48B8126F01CD870484`；不是当前 `.3`。
- PHP 8.3.32、APCu `134217640` bytes、Windows PHP built-in 单 worker、同一 fixture/透明代理实例、临时 CA、输入、端口分配策略和五条代表路由；外部条件指纹两侧均为 `DA0F7866…351C`。
- warmup=10、iterations=120、concurrency=10；BEFORE/AFTER 各 `5 × 120 = 600` 个 warm 样本，全部 HTTP 200、`contract_valid=true`，五路由均满足 p95/p99 样本门槛。
- config、API、章节与 CDN 请求全部命中 loopback fixture；`fixed_loopback_proven=true`、`comparable=true`。
- 恢复快照、当前源码、编排器、代理、fixture、探针、PHP/扩展文件在测量前后哈希不变；临时 CA 未进入 CurrentUser/LocalMachine Root，子进程、父环境和临时目录全部清理。

独立从原始样本按 nearest-rank 重算：

| route | BEFORE p50 | AFTER p50 | p50 改善 | BEFORE p95 | AFTER p95 | p95 改善 | BEFORE p99 | AFTER p99 | p99 改善 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| health | 16.200ms | 20.457ms | -26.278% | 25.365ms | 30.499ms | -20.240% | 52.263ms | 45.452ms | 13.032% |
| latest page 1 | 30.263ms | 20.820ms | 31.203% | 58.038ms | 27.547ms | 52.536% | 158.792ms | 34.568ms | 78.231% |
| latest page 4 | 30.999ms | 20.049ms | 35.324% | 58.150ms | 28.882ms | 50.332% | 63.771ms | 39.653ms | 37.820% |
| album | 32.095ms | 20.120ms | 37.311% | 59.432ms | 27.712ms | 53.372% | 134.048ms | 51.184ms | 61.817% |
| image_no_prefetch | 17.618ms | 20.432ms | -15.972% | 26.712ms | 30.260ms | -13.282% | 46.842ms | 43.820ms | 6.451% |

fixture 请求总数 `397 → 7`，减少 `390` 次／`98.237%`：BEFORE latest=262、album=131、chapter/scramble=2、media=2；当前 AFTER config=1、latest=1、album=1、chapter/scramble=2、media=2。10 客户端图片队列两侧均 10/10 成功，wall `100.018ms → 102.554ms`，本次回退 `2.536%`。

解释边界：当前实现继续显著减少重复上游调用，latest/album 的 p50/p95/p99 以及五路由 p99 在本次运行中改善；health 与图片的 p50/p95、图片并发 wall 回退，不能宣称“所有延迟都改善”。本地确定性 fixture 不代表真实公网绝对延迟或 Docker 吞吐。上一版 `.1` 的正式证据 `transparent-https-current-ab-20260717.json / 6ECB423E…2F9B5` 继续单独保留，不与本节混用。

### 5.2 历史透明 HTTPS 同条件成功路径 A/B

正式证据：`D:\jm\jmcomic-api-main\performance-evidence\transparent-https-common-ab-20260717.json`  
SHA-256：`47044F43CF37BF16E77F2B8C6DEA76A28FF0C74E348C6890FD80B0179CAB8B77`  
模式：`historical-common-denominator-v1`  
生成时间：`2026-07-17T00:25:06.6814951Z`

该报告绑定的测量 AFTER 是 `2026.07.13.2`，不是 `.1 / 53A15D40…A3B5C`，更不是当前 `.2`。严格门禁：

- 恢复版 `index.php`：`A88271DCD759CDB4992FB2C49C963441FDA7028201E991211CD4DE4B497A13ED`；测量 AFTER：`680AF5970DD5EC8A85C2D6F75F66ECA439F91D1DBB3FF476406CB0096B18FB7C`。
- 同一 fixture/代理进程、PID、启动时间、实例 nonce、临时 CA 和 API/fixture/proxy 端口；每阶段前后实时复核原进程仍存活。
- PHP 8.3.32、APCu 128M、Windows PHP built-in 单 worker、同一扩展/ini、同一输入和五条最小代表路由。
- 代理、fixture、探针以只读临时副本执行并逐阶段复核哈希；恢复目录四个文件及 manifest 前后哈希不变。
- 恢复版配置源使用不遵循 cURL 代理的 `file_get_contents(https://...)`，因此两版均设置 `allow_url_fopen=0`：恢复版 fail-closed 后使用内置域名；历史测量 AFTER 的 cURL config 必须命中 loopback fixture。没有公网 fallback。
- BEFORE/AFTER 各有 `5 × 120 = 600` 个正式 warm 样本，全部 status 200、`contract_valid=true`；`fixed_loopback_proven=true`、`comparable=true`、`cleanup.complete=true`。
- 临时 CA 未安装到 CurrentUser/LocalMachine Root，父环境已恢复，全部子进程退出且临时树不存在。

共同指标：

| route | BEFORE p50 | AFTER p50 | p50 改善 | BEFORE p95 | AFTER p95 | p95 改善 | BEFORE p99 | AFTER p99 | p99 改善 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| health | 14.592ms | 17.133ms | -17.414% | 41.147ms | 28.945ms | 29.655% | 88.664ms | 36.922ms | 58.357% |
| latest page 1 | 23.341ms | 18.311ms | 21.550% | 68.543ms | 41.488ms | 39.472% | 232.467ms | 258.976ms | -11.403% |
| latest page 4 | 24.318ms | 18.192ms | 25.191% | 73.008ms | 40.909ms | 43.966% | 129.868ms | 87.354ms | 32.736% |
| album | 23.064ms | 17.268ms | 25.130% | 61.778ms | 26.083ms | 57.779% | 148.697ms | 154.386ms | -3.826% |
| image_no_prefetch | 14.223ms | 16.922ms | -18.976% | 24.848ms | 31.452ms | -26.578% | 84.928ms | 59.135ms | 30.370% |

fixture 请求总数从 `397` 降到 `7`，减少 `390` 次／`98.237%`：恢复版 latest=262、album=131、chapter/scramble=2、media=2；历史测量 AFTER config=1、latest=1、album=1、chapter/scramble=2、media=2。该差异直接证明该历史 AFTER 的列表源页和 album 短 TTL 缓存消除了绝大多数重复上游请求。

10 客户端图片队列探针两版均 10/10 成功，单 worker wall 为 `89.660ms → 95.053ms`；历史测量 AFTER 没有改善该指标。它只描述 Windows 单 worker 排队，不是 Docker 多 worker singleflight 证据。

解释边界：本结果可以支持“重复上游调用显著减少”和特定列表/专辑分位数改善；不能支持“所有延迟都改善”。health p50、图片 p50/p95、latest page 1 p99 和 album p99 存在回退或抖动，后续优化应先用重复独立运行确认稳定性。该本地 fixture 结果不外推为真实公网绝对延迟或 Docker 10-worker 吞吐。

### 5.3 `.2` after-only 深度性能证据（`.3` 前历史证据）

文件：`D:\jm\jmcomic-api-main\performance-evidence\performance-after-v172.local-fixture.json`  
SHA-256：`F709B6EE207B5A9DA790D51DC7F6A88C4FDE3D4D23AF51C12C986E9042A928E5`  
生成时间：`2026-07-17T08:01:13.7101474Z`

证据绑定：

- `index.php`：`7A2AC07AE89CA4EE0ADB4F4B8435C8038CE656769295FD48B8126F01CD870484`
- 测量脚本：`C4B65EA3CC810F0E3C5E20523A5E389C0B303B0539263A40138A054E18C0A65F`
- evidence helper：`35238705319F7E7344F200A2D85D71C903124FA8BF52A304350C01CBD342D5F0`
- evidence helper 判定：`comparison_evidence_complete=true`

测量条件：Windows PowerShell 5.1、PHP 8.3.32 built-in server、loopback deterministic fixture、实际 1 worker；每路由 10 次 warm-up + 120 次计量，图片并发 10 客户端。该证据不能外推为 Docker 10 worker 或真实 JM 网络吞吐。

冷态原始事实：

- latest 页 1～4 上游次数：`1,0,0,0`。
- popular 页 1～4 上游次数：`1,0,0,0`。
- album：1 次上游。
- 首图：`MISS`。
- 10 路并发图片：10/10 成功，batch wall=1941ms；client occupancy ratio=1（仅为有界客户端估算，不是服务端 CPU 采样）。
- 预取：单事件归因通过，scheduled=1、attempted=8、stored=7、bytes=1043、后续观察页 HIT=2；唯一停止原因 `budget-attempts=1`，没有 `executor-error`。
- 本次只观察随后读取的第 2～3 页，利用率 0.2857、未利用率 0.7143；未在本探针读取的缓存页被计为未利用，不能外推为长期用户行为浪费率。
- APCu：hits `+5256`、misses `+7657`、inserts `+126`、expunges `+0`；fragmentation ratio `0 → 0`，free ratio 保持 99%。

warm 结果：

| route | success | p50 ms | p95 ms | p99 ms | max ms |
|---|---:|---:|---:|---:|---:|
| health | 120/120 | 22 | 31 | 99 | 169 |
| latest_1 | 120/120 | 22 | 33 | 83 | 87 |
| latest_2 | 120/120 | 22 | 36 | 57 | 98 |
| latest_3 | 120/120 | 22 | 30 | 35 | 42 |
| latest_4 | 120/120 | 22 | 32 | 104 | 144 |
| popular_1 | 120/120 | 21 | 29 | 40 | 45 |
| popular_2 | 120/120 | 22 | 32 | 46 | 175 |
| popular_3 | 120/120 | 22 | 32 | 72 | 74 |
| popular_4 | 120/120 | 22 | 29 | 34 | 76 |
| album | 120/120 | 21 | 29 | 50 | 117 |
| image_no_prefetch | 120/120 | 22 | 29 | 84 | 92 |

`performance-before.unavailable.json` 的 SHA-256 为 `9106FADFD00B190B3546212579C14E5144966FB102247340A0230FC5B7A78D1D`。精确源码快照位于 `performance-evidence/before-source-20260713T061309Z`，其 `manifest.json` SHA-256 为 `55187656E7446B8D9A897F9FF2AC55ECDBCF66819F440B108CFB7D52CF5F0FFD`；恢复的 `index.php` SHA-256 为 `A88271DCD759CDB4992FB2C49C963441FDA7028201E991211CD4DE4B497A13ED`，并通过 PHP 8.3.32 lint。以上只说明原始时间点未测量和源码来源；不能给 after-only 文件补写百分比。可比较百分比仅来自上节单独标识、同条件重跑的 common-denominator 报告。

### 5.4 历史同 PHP、同真实网络的冷失败路径夹测

原始证据：`performance-evidence/production-failure-cold-ab-20260717.json`，SHA-256 `D3E1906E1C9270141FA6ACD0F319D800BE7DDB27A3C3D9406C31F2A742E2E425`。

条件：PHP 8.3.32 built-in server、APCu 128M、memory limit 512M、实际 1 worker、无代理或 `JM_*` 环境变量；按历史测量 AFTER A → 恢复版 BEFORE → 同一历史 AFTER B 顺序请求同一个 latest 冷路径。

| 样本 | 源码 SHA-256 | HTTP | 客户端耗时 | 历史 AFTER 诊断 |
|---|---|---:|---:|---|
| after bracket A | `680AF597…18FB7C` | 502 | 1149ms | 5 attempts，upstream 1091ms，deadline=0 |
| recovered before | `A88271DC…7A13ED` | 502 | 6875ms | 旧版未暴露 request/attempt/upstream headers |
| after bracket B | `680AF597…18FB7C` | 502 | 936ms | 5 attempts，upstream 926ms，deadline=0 |

这证明在本次相邻夹测中，恢复版失败路径慢于历史测量 AFTER 两侧样本；但三次请求都因外部上游返回 502，网络也不可控。因此它只能作为失败路径原始事实，不能代替 deterministic fixture 成功路径、p95/p99、Docker 10-worker、当前源码结果或总体提升百分比。

## 6. 真实 Suwayomi v1.4.15 回归

环境：Suwayomi-Server `2.3.2243`、Java `21.0.11`，宿主地址 `http://127.0.0.1:4567`。

- 已安装扩展：`tachiyomi-zh.jmapi-v1.4.15.apk`；versionName=`1.4.15`、versionCode=`15`、installed=true、hasUpdate=false。
- source ID：`3584941114123005289`。
- 当前偏好 Base URL：`http://127.0.0.1:18088/api`。
- 筛选实际返回：`排序 / 最新 / 最多浏览 / 最多点赞`；设置标题、说明和摘要均为中文。
- Popular → `list=promote`；Latest → `list=weekly`；空搜索、标题搜索、JM ID/URL 搜索均保持既定映射。
- 详情、章节和阅读链路通过；目标章节返回 12 页，图片响应 HTTP 200、WebP、58 bytes。

### 6.1 反向代理子路径与预取双向开关回归

- 历史 RED（v1.4.14）：Base URL 为 `/api` 时，API 章节 JSON 的 `images[].url` 实际丢失 `/api`；旧 `pageListParse()` 信任该绝对 URL。图片虽因本地测试 router 同时承接根路径而返回 200，但禁用预取前后 API 的 `disabled` 计数不变，只增加 `skipped-low-memory`。
- 修复（v1.4.15）：`pageListParse()` 忽略载荷中的绝对图片 URL，统一用当前已校验 `ApiEndpoint` 和 album/chapter/page 重建每页 URL；未放宽 `/api`、根路径与 `/api2` 的精确隔离规则。
- 真实 GREEN：禁用状态显示 true，图片 HTTP 200/WebP/58 bytes，API `disabled: 0 → 1`；重新启用后状态显示 false，再次取图仍为 HTTP 200/WebP/58 bytes，`disabled` 保持 1，证明 `prefetch=0` 已被移除。

### 6.2 用户所贴 `refreshChapterPageList` 栈尾的诊断边界

同一 v1.4.15、同一章节、同一 Suwayomi 中，将 API 地址改为未监听的 `18089` 时，49ms 内返回 `ConnectException: Connection refused`；恢复 `18088` 后，61ms 内返回 12 页。由此可证 `awaitOne → getPageList → refreshChapterPageList` 是连接拒绝、HTTP 错误、JSON/协议错误等多种异常都会经过的公共传播路径，不是根因本身。现场仍需栈首 `Caused by:` 及其上方约 20～40 行、实际 Base URL、部署拓扑和同一时刻 API request-id 日志才能唯一归因。

因此，中文筛选和已知反代页面 URL 缺陷均已在 v1.4.15 的受控环境回归；若设备仍显示英文，应核对宿主实际安装的 package/versionCode/签名及扩展进程缓存。若 Suwayomi 与 API 位于不同容器，`127.0.0.1` 指向 Suwayomi 容器自身，必须改用容器可访问的服务名、平台支持时的 `host.docker.internal` 或局域网地址。

收尾时已按命令行和监听端口精确停止隔离 Java/PHP 进程；`4567`、`18088`、`18089`、`18090` 均确认无监听。

## 7. 外部阻塞与剩余风险

1. 当前机器没有 Docker、Podman、nerdctl、containerd 或 `ctr` 命令/服务；`wsl.exe --status` exit 50。`Microsoft-Windows-Subsystem-Linux`、`VirtualMachinePlatform`、`Microsoft-Hyper-V-All`、`Containers`、`HypervisorPlatform` 五个可选组件全部为 Disabled。因此无法在本机执行 compose build、真实多 worker owner/loser、完整 Docker fault matrix 和 Docker runtime verifier；未经用户明确授权不能擅自启用系统组件、安装容器运行时或触发重启。
2. 两项目没有 `.git`，bundled Git 对两个目录 `rev-parse` 均 exit 128；无法提供权威 diff 或 commit。pre-change 四文件改由原始会话中的成功补丁链逆向恢复，权威哈希记录位于会话日志时间 `2026-07-13T06:13:09.236Z`，恢复方法和哈希写入独立 manifest；这不等价于补造 Git 历史。
3. 原始修改时间点没有预先运行 BEFORE，这一事实仍由 `performance-before.unavailable.json` 保留；百分比来自用户批准后新建的 `historical-common-denominator-v1` 透明 HTTPS harness，对权威恢复快照和 `.2 / 7A2AC07A…70484` 同条件重跑，不冒充原始时间点、当前 `.3` 或严格同策略 schema 基线。`x-tunnel-smux` 继续保持只读且未使用。正式结果只覆盖确定性 loopback 单 worker 成功路径；Docker 10-worker 与真实公网仍需各自证据。
4. 2026-07-17 以无代理、无 `JM_*` 环境变量的独立生产进程，对历史测量 AFTER（`index.php=680AF597…18FB7C`）A／恢复版 BEFORE／同一历史 AFTER B 做夹测：三者均 HTTP 502，耗时分别为 1149ms／6875ms／936ms；历史 AFTER 两次均为 5 attempts 且 deadline=0。另一次该历史 AFTER 的真实 latest 烟测为 1544ms、upstream 1493ms。故障均受预算约束，但这些历史结果不属于 `.2` 或当前 `.3`，不能替代用户部署现场复验。
5. 当前 APK 使用 Android Debug 证书。两个项目中 `.jks/.keystore/.p12/.pfx/.pem` 生产密钥文件数量为 0；`KEYSTORE_FILE`、`KEYSTORE_PASSWORD`、`KEY_ALIAS`、`KEY_PASSWORD`、`ANDROID_KEYSTORE`、`SIGNING_KEY` 六个常用进程变量均未设置。功能、v1/v2 签名和仓库指纹一致，但正式长期发布必须由用户提供稳定生产密钥并保持升级签名连续，不能由 AI 擅自替换既有发布身份。
6. `apksigner` 提示 `META-INF/com/android/build/gradle/app-metadata.properties` 不受 v1 JAR 签名保护；v2 对整包提供保护。

这些是外部验收或发布治理风险，不是尚未实现的本机代码优化项。

## 8. 部署、验证与回滚

Docker-capable 主机：

```powershell
Set-Location D:\jm\jmcomic-api-main
docker compose build --no-cache
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1 -SkipBuild
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\fault-injection-runtime.ps1 `
  -DockerLogPath .\performance-evidence\fault-injection-runtime-docker.log
$log = Get-Item .\performance-evidence\fault-injection-runtime-docker.log -ErrorAction Stop
Get-FileHash -Algorithm SHA256 -LiteralPath $log.FullName
$health = Invoke-RestMethod 'http://127.0.0.1:8088/?health=1'
if ($health.diagnostics.test_mode -ne $false) { throw 'Production compose was not restored after the fault matrix.' }
```

`fault-injection-runtime.ps1` 会在 `finally` 中先保存测试 overlay 日志，再执行 combined `down -v --remove-orphans`，随后恢复 production compose 并等待 `health=1`，且以 `test_mode=false` 为完成门槛；因此不要在脚本返回后再向已删除的测试 overlay 请求日志。

恢复源码位于 `D:\jm\jmcomic-api-main\performance-evidence\before-source-20260713T061309Z`。只有用它取得真实且同条件的 `performance-before.json` 后，才运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\performance-baseline.ps1 `
  -RuntimeKind docker -RuntimeSourceBinding docker-image `
  -RuntimeImageDigest 'sha256:<本次镜像的64位SHA-256>' `
  -ActualWorkerCount 10 `
  -NetworkConditionId 'same-network-profile-v1' `
  -ResourceProfileId 'cpu2-memory512m-apcu128m-v1' `
  -OutputPath .\performance-after.json -ComparePath .\performance-before.json
```

扩展发布产物：

```text
D:\jm\jmapi-extension\dist-local\
  apk\tachiyomi-zh.jmapi-v1.4.15.apk
  icon\eu.kanade.tachiyomi.extension.zh.jmapi.png
  index.min.json
  index.json
  repo.json
```

只回滚性能策略时，可把列表/搜索/album/week 缓存 TTL、`JM_DOMAIN_REFRESH_DEFERRED`、`JM_PREFETCH_PAGES`、`JM_PREFETCH_MAX_ACTIVE`、`JM_PREFETCH_WALL_BUDGET_MS` 和 `JM_PREFETCH_BYTE_BUDGET` 设为 `0` 后重建容器。该操作不会恢复已修复的 URL、章节、代理、Redis 原子性或资源安全错误。

行为版本回滚必须使用真实保存、签名连续的旧镜像/APK/索引。当前无 Git 历史，不得通过手工降低 versionCode 或拼接旧文件伪造回滚。

## 9. 完成判定

- **代码优化：完成。**
- **本机可执行验收：完成。**
- **APK 与既有 Suwayomi 实际回归：完成；`.7` API 现场复验待用户容器。**
- **pre-change 源码恢复：完成；四个权威 SHA-256 全部命中。**
- **Docker 多 worker 验收：外部阻塞。**
- **历史同条件运行态 BEFORE/AFTER：绑定 `A88271DC…7A13ED → 680AF597…18FB7C` 的旧证据保留。**
- **性能 A/B：绑定 `A88271DC…7A13ED → 7A2AC07A…70484` 的 `.2` 结果已完成并独立重算；当前 `.7 / 1A3FF904…CD7C` 未重跑性能矩阵，不能继承 `.2` 标签。**
- **生产签名：发布治理待用户提供稳定密钥。**

除非代码、配置、APK 或外部条件发生变化，后续 AI 不应重做本报告已经有新鲜证据的本机工作。

## 10. 给后续 AI 的简短提示词

> 完整读取并严格执行 `D:\jm\jmcomic-api-main\docs\ai-delivery-prompt.md`。先核对 API `2026.07.17.7 / 1A3FF904…CD7C`，并把 `.2` A/B `31FC02D2…24C9` 和 after-only `F709B6EE…928E5` 视为历史性能证据；未变化时不要重做 APK/Suwayomi/本机性能矩阵。强制重建 `.7` 并现场验证 Latest、同一章节及预取统计，失败时按 request-id 最小修复；具备 Docker 后完成 runtime/fault matrix，严守固定契约，禁止伪造 Git、签名或性能百分比。
