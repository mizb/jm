# 透明 HTTPS 性能测量 Harness 设计

日期：2026-07-17  
状态：用户已批准，作为后续实现与测量的权威边界  
项目：`D:\jm\jmcomic-api-main`  
历史源码：`D:\jm\jmcomic-api-main\performance-evidence\before-source-20260713T061309Z`

## 1. 目的

现有优化版可以通过 `JM_TEST_*` 把上游改为本地 fixture，但恢复版源码没有这些注入点，并且把 JM API、CDN 和域名配置源写死为 HTTPS。直接把优化版接到本地 fixture、恢复版接到真实互联网，或只比较两次失败请求，都不构成可比的 BEFORE/AFTER 性能证据。

本设计增加一个仅用于本机测量的透明 HTTPS 代理，使两份源码在不修改业务源码的前提下访问同一个确定性 fixture。代理接收 PHP cURL 的 HTTPS `CONNECT`，以临时 CA 终止 TLS，然后把请求转发到固定的 loopback fixture。两份源码使用同一个代理进程、同一个临时证书、同一个 fixture 进程、同一 PHP/APCu 配置、同一 API 端口和同一输入。

交付目标只有三个：

1. 在不修改恢复版源码的前提下得到成功路径 BEFORE 数据。
2. 形成可以复核外部条件与源码哈希的共同指标比较证据。
3. 测量结束后不留下受信任 CA、监听进程或临时目录。

## 2. 不可突破的边界

- 代理和 fixture 只监听 `127.0.0.1`，禁止 `0.0.0.0`、`::`、局域网地址和外部地址。
- 上游转发目标固定为 `127.0.0.1:<fixture-port>`，不得进行公网 DNS 解析或连接原始目标。
- 只接受端口 443 的 HTTPS `CONNECT`；明文代理请求、非 443 端口和非白名单主机全部拒绝。
- 临时 CA 不写入 Windows CurrentUser/LocalMachine 证书库，不调用 `certutil`、PowerShell 证书导入或系统设置。
- CA 只注入被测 PHP 进程：同时设置 `CURL_CA_BUNDLE`、`SSL_CERT_FILE`，并用该进程独有的 `-d curl.cainfo=<ca>`、`-d openssl.cafile=<ca>` 避免 Windows PHP/libcurl 忽略环境默认值；父进程环境必须在 `finally` 中恢复。
- 两版 PHP 都设置 `allow_url_fopen=0`。恢复版唯一的远程 stream 路径是域名配置 `file_get_contents(https://...)`，它不遵循 cURL 代理；必须 fail-closed 后使用内置域名，禁止该路径绕过 loopback。当前版配置刷新使用 cURL，仍须命中配置 fixture。
- 不修改 `performance-evidence\before-source-20260713T061309Z` 中任何文件；测量前后重新计算 `index.php` SHA-256 并要求一致。
- 不修改或调用 `E:\软件备份\cloudflare\x-tunnel-smux`。该项目只有 TLS 透传能力，不满足本设计的 TLS 终止、固定 fixture 映射和临时 CA 要求。
- 代理仅属于测试基础设施，不进入生产镜像、生产 compose、APK 或部署文档。

## 3. 威胁模型与白名单

### 3.1 主机白名单

白名单是代码中的显式常量，并同时用于证书 SAN、`CONNECT` 校验和 HTTP `Host` 校验：

JM API：

- `www.cdnhjk.net`
- `www.cdngwc.cc`
- `www.cdngwc.net`
- `www.cdngwc.club`
- `www.cdnutc.me`

域名配置源：

- `rup4a04-c01.tos-ap-southeast-1.bytepluses.com`
- `rup4a04-c02.tos-cn-hongkong.bytepluses.com`
- `rup4a04-c03.tos-cn-beijing.bytepluses.com.cn`

JM CDN：

- `cdn-msp.jmapiproxy1.cc`
- `cdn-msp.jmapiproxy2.cc`
- `cdn-msp2.jmapiproxy2.cc`
- `cdn-msp3.jmapiproxy2.cc`
- `cdn-msp.jmapinodeudzn.net`
- `cdn-msp3.jmapinodeudzn.net`

主机名比较使用去除末尾点后的 ASCII 小写精确匹配；禁止通配符、后缀匹配、用户信息、IP 字面量和非 ASCII 欺骗形式。

### 3.2 路径与方法限制

代理只允许 `GET`，拒绝 HEAD、请求体和 `Transfer-Encoding`。按主机类型进一步限制路径：

- API 主机：当前两份源码实际使用的 `/album`、`/chapter`、`/chapter_view_template`、`/comic_read`、`/latest`、`/search`、`/categories/filter`、`/promote`、`/promote_list`、`/week`、`/week/filter`。
- 配置源主机：仅 `/newsvr-2025.txt`。
- CDN 主机：仅 `/media/photos/` 或 `/media/albums/` 下的路径。

CONNECT 主机、TLS SNI（存在时）和内部 HTTP `Host` 必须一致。转发时保留原始 `Host`，fixture 因而能按真实逻辑区分 API、CDN 和配置源；代理不得把 Host 改为 `127.0.0.1`。

### 3.3 资源限制

- CONNECT/HTTP 请求头各限制为 64 KiB。
- 单个 fixture 响应限制为 32 MiB；本次确定性图片远小于该值。
- 握手、上游连接和读写均设置超时。
- 线程数设置上限；超过上限返回 503，避免本机资源失控。
- 状态文件只写入受控临时目录，不记录 token、完整查询密钥或响应正文。

## 4. 组件设计

### 4.1 `tests/fixtures/transparent_https_proxy.py`

代理使用 bundled Python 与 `cryptography`，不安装第三方服务。启动流程：

1. 验证 listen/upstream 都是 loopback、端口有效、白名单非空且无重复。
2. 在调用方提供的临时目录生成一次性根 CA 和服务器证书，并为代理实例生成随机 nonce。
3. 服务器证书 SAN 精确包含全部白名单主机；有效期短，私钥只存在于临时目录。
4. 绑定 `127.0.0.1` 后原子写入 ready state JSON，其中包含 PID、监听地址、CA 路径/指纹、白名单和固定上游地址。
5. 对每个 CONNECT 执行白名单、端口、SNI、Host、方法和路径校验，再转发到 fixture。
6. 收到 Ctrl+C/终止或父编排退出后关闭监听；目录删除由编排脚本负责。

不实现通用 HTTP/SOCKS 转发、动态 DNS、上游 TLS、证书缓存服务、系统 CA 安装或生产配置。

### 4.2 fixture 增补

`tests/fixtures/upstream-router.php` 继续作为唯一确定性上游。只增加一个必要行为：白名单配置源主机访问 `/newsvr-2025.txt` 时，返回现有加密格式的固定 API 域名列表。其余 API/CDN 响应沿用已验证 fixture，不复制第二套业务模拟器。

### 4.3 `scripts/transparent-https-performance.ps1`

编排脚本负责完整生命周期：

1. 解析并校验 Python、PHP、APCu DLL、两份源码和代理/fixture 路径。
2. 在受保护的 `try/finally` 内创建名称受控的 `%TEMP%\jm-transparent-https-performance-<guid>`，把代理、fixture 和探针复制为只读 runtime 输入；测量按这些副本执行并在每阶段前后核对哈希。
3. 启动一个 fixture 和一个代理，记录 PID、进程启动时间和随机实例 nonce，并执行一次允许主机的 TLS 预热探针。
4. 依次启动恢复版和当前版 API；两者均使用同一 API 端口和相同 PHP 参数。
5. 每个版本启动前重置 fixture 计数，启动全新的 PHP 进程以获得独立 APCu 冷状态。
6. 执行冷样本、10 轮 warm-up、120 轮正式样本和一次 10 客户端图片队列探针。
7. 停止全部子进程，恢复父进程环境，删除证书/日志/临时目录，再写出最终 JSON。

所有进程都使用隐藏窗口。脚本在任何异常路径都执行同一个 `finally` 清理；若清理不完整，报告不得标记为 complete。

## 5. 公平性与共同指标

### 5.1 严格相等的外部条件

报告对以下字段做相等门禁并计算一个 canonical SHA-256 指纹：

- harness、代理和 fixture 文件 SHA-256；
- PHP 可执行文件、APCu DLL 和 Python 可执行文件 SHA-256/版本；
- API、proxy、fixture 的 loopback 地址与端口；
- PHP 扩展、ini 参数、APCu 容量、单 worker 声明；
- 同一代理 PID、CA 指纹和 fixture PID；
- 同一代理/fixture 进程启动时间和实例 nonce，并在每个版本阶段前后实时观察原进程句柄仍存活；
- warm-up、iterations、concurrency、超时、路由和输入 ID；
- 固定白名单、固定上游映射和 loopback 网络条件标识。

源码哈希允许不同，因为它是实验变量；每个版本的哈希、路径和测量前后哈希都必须记录。运行时缓存/预取策略也允许不同，因为它们正是优化内容，但要分别完整记录并生成各自指纹。

### 5.2 为什么不复用严格策略哈希比较

`performance-baseline.ps1` 的严格模式要求 BEFORE/AFTER 的 `runtime_prefetch_policy_sha256` 和 `runtime_cache_policy_sha256` 相同，并要求新版诊断 schema 的全部字段存在。恢复版没有 wall/byte/max-active、page-cache-enabled/TTL 等字段，因此把历史报告强行塞入该模式会被正确拒绝。

本 harness 使用独立的 `historical-common-denominator-v1` 比较模式：

- 外部条件必须完全一致；
- 策略差异作为实验变量并列记录，不参与环境相等门禁；
- 只比较两份源码都能真实产生的 HTTP 成功率、客户端 elapsed、共同响应头和 fixture 上游计数；
- HTTP 成功必须同时满足 status 200、路由业务结构和 fixture 专属内容；计数必须证明 API、章节和 CDN 都命中 loopback，当前版还必须命中 config fixture，恢复版 config stream 必须保持 fail-closed 零请求；
- 任一版本失败、样本缺失、源码运行中变化、代理/fixture 不是同一实例或清理失败时，`comparable=false`，不得输出提升百分比。

这不是降低证据标准，而是避免把“被优化的策略”误当成“必须相同的外部环境”。原严格比较器保持不变，防止影响现有 after-only 与同 schema 比较。

### 5.3 最小但有代表性的测量路由

为避免过度测试，正式 warm 路由只保留：

- `health`：不访问上游，观察 PHP/API 本地固定开销。
- `latest_page_1`：列表源页与短 TTL 缓存主路径。
- `latest_page_4`：与 page 1 共享上游源页，验证分页缓存复用。
- `album`：专辑短 TTL 缓存主路径。
- `image_no_prefetch`：章节、CDN、解码与页面缓存路径，显式关闭预取以隔离变量。

每条路由保留 120 个正式成功样本，以满足 p99 的最低样本要求；减少路由而不削弱统计口径。并发只做一次 `concurrency=10` 的图片队列探针。由于 Windows PHP 内置服务器是单 worker，该探针只报告队列吞吐/成功率，不宣称验证多 worker single-flight。

百分比采用 `(before - after) / before * 100`，仅在 before 指标大于零且比较门禁通过时生成。报告同时保留原始样本、p50/p95/p99/max、成功数和 fixture 请求计数，避免只展示有利百分比。

## 6. 精简测试策略

只新增两个高价值测试入口，不为内部辅助函数堆叠用例：

1. `tests/transparent-https-proxy-runtime.ps1`
   - 允许主机经 TLS 校验成功并由 fixture 看到原始 Host；
   - 非白名单 CONNECT 被 403 拒绝且未到达 fixture；
   - 非 loopback 监听配置启动失败；
   - 临时 CA 未进入 CurrentUser/LocalMachine Root，退出后临时目录可删除。
2. 编排脚本小样本 smoke：`warmup=1`、`iterations=2`、`concurrency=2`，证明恢复版和当前版均能成功产出可比较报告。

开发中不反复运行全部旧套件。实现完成后只统一运行一次与本变更相关的 API 静态合同、代理 runtime、正式 harness，以及既有关键 API 回归；扩展源码未变，不重复构建 APK 或跑扩展测试。只有共享业务文件意外变化时才扩大回归范围。

## 7. 错误处理与逻辑合理性检查

- **代理环境未生效：** fixture 计数为零或 cURL 访问真实网络时立即失败；报告不得回退到公网。
- **部分代理绕过：** 只出现非零计数仍不够；列表/专辑必须返回 fixture 专属值，图片必须有正确媒体/缓存合同，fixture 计数必须覆盖各上游路由族。恢复版 stream 网络由 `allow_url_fopen=0` 物理禁用。
- **CA 注入未生效：** TLS 主机校验失败属于 harness 错误，不允许关闭 `CURLOPT_SSL_VERIFYPEER` 或 `VERIFYHOST`。
- **Host/SNI 不一致：** 拒绝请求，防止白名单 CONNECT 被用作其他主机的隧道。
- **端口占用竞态：** 启动后必须用 state/health 实际确认 PID 与监听端口；失败则清理并重新选择端口，最多有限次数。
- **旧版配置源语义：** fixture 返回与生产加密格式一致的域名列表，不能用明文或未加密 JSON 绕过旧版解密路径。
- **缓存污染：** BEFORE/AFTER 各自使用全新 API 进程；fixture 计数在每个版本前 reset 并回读确认归零。
- **顺序偏差：** fixture/代理先做一次不计入数据的预热；报告记录执行顺序，不隐瞒 BEFORE 先运行。
- **策略 schema 不同：** 只并列记录，不填造旧版不存在字段，不调用严格比较器生成伪百分比。
- **清理失败：** 捕获具体 PID/路径并把报告标记为 incomplete；不得吞掉清理异常后声称完成。
- **源文件变化：** 任一测量阶段前后哈希不同即废弃该版本结果。

## 8. 产物与验收

新增或修改的预期文件：

- `tests/fixtures/transparent_https_proxy.py`
- `tests/fixtures/transparent_https_probe.php`
- `tests/transparent-https-proxy-runtime.ps1`
- `tests/transparent-https-performance-runtime.ps1`
- `tests/fixtures/upstream-router.php`（仅配置源 fixture 增补）
- `scripts/transparent-https-performance.ps1`
- `performance-evidence/transparent-https-common-ab-20260717.json`
- `docs/performance-delivery-report.md`

完成条件：

- 代理 runtime 四类验收全部通过。
- smoke 中两份源码均获得成功业务响应，`comparable=true`。
- 正式报告使用 warm-up 10、iterations 120、concurrency 10，五条最小代表路由均有 120 个成功正式样本。
- 报告记录两个源码哈希、同一代理/fixture 身份、外部环境指纹、原始样本、共同指标和策略差异。
- 恢复版源码哈希仍为权威值，当前源码在测量期间不变。
- 报告中的 `cleanup.complete=true`，临时 CA 不在系统证书库，所有子进程已退出，临时目录不存在。
- 交付报告明确区分正式成功路径 A/B、既有 after-only 证据和生产失败路径夹测，不混写结论。

## 9. 非目标

- 不把代理发展为通用抓包工具、生产反向代理或远程调试服务。
- 不安装 Docker、启用 WSL/Hyper-V、重启系统或修改系统 DNS/hosts。
- 不修改 API/扩展产品行为、版本号或 APK。
- 不把本地 fixture 结果外推为真实公网绝对延迟；结论只覆盖确定性成功路径中的代码与缓存开销。
- 不用更多相似路由、重复构建或全矩阵故障注入来增加“测试数量”。
