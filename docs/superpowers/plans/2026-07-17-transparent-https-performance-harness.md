# 透明 HTTPS 性能测量 Harness 实施计划

状态：**已完成（2026-07-17）**。下方复选框保留为实施过程规范，不表示待办；完成证据为 `D:\jm\jmcomic-api-main\performance-evidence\transparent-https-common-ab-20260717.json`，SHA-256 `47044F43CF37BF16E77F2B8C6DEA76A28FF0C74E348C6890FD80B0179CAB8B77`。正式参数为 warmup 10、iterations 120、concurrency 10，BEFORE/AFTER 各 600 个 warm 样本全部成功，`comparable=true`、`fixed_loopback_proven=true`、`cleanup.complete=true`。除非代码或环境哈希变化，后续 AI 不得重跑本计划。

> 执行要求：严格遵循权威设计 `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-17-transparent-https-performance-harness-design.md`，测试先行，自主诊断失败并持续到正式 A/B 证据和交付报告完成。不得修改恢复版源码，不得安装系统 CA，不得访问或修改 `x-tunnel-smux`。

**目标：** 用一个仅 loopback、严格白名单、临时 CA 的透明 HTTPS 代理，让恢复版和当前版 PHP 在完全相同的本地 fixture 条件下完成成功路径性能测量，并生成可复核的 `historical-common-denominator-v1` 比较报告。

**架构：** bundled Python 代理终止 PHP cURL 的 CONNECT/TLS，将允许的 JM API/CDN/config 请求固定转发到一个 PHP fixture。PowerShell 编排同一代理/fixture 下依次启动两份单 worker PHP 源码，采集最小代表路由的原始样本、fixture 上游计数、源码/环境哈希和清理证据。

**测试取舍：** 只增加一个代理安全/转发 runtime 和一个小样本 A/B smoke；实现完成后统一跑一次相关回归。正式 120 次是统计样本，不扩张为重复测试矩阵。

---

## Task 1：冻结输入与建立 RED 代理合同

**Files:**

- Create: `D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1`
- Expected missing: `D:\jm\jmcomic-api-main\tests\fixtures\transparent_https_proxy.py`

- [ ] 记录恢复版 `index.php`、当前 `index.php`、fixture、PHP、APCu DLL 和 Python 的 SHA-256；断言恢复版目录四个文件与 `manifest.json` 一致。
- [ ] 测试只覆盖四个高价值行为：
  1. 白名单主机通过真实 TLS 主机校验，fixture 看到原 Host；
  2. 非白名单 CONNECT 返回 403 且 fixture 计数不增加；
  3. 非 loopback listen/upstream 参数被拒绝；
  4. CA 不进入系统 Root，退出后进程和临时目录可清理。
- [ ] 所有临时路径必须位于 `%TEMP%\jm-transparent-https-proxy-test-<guid>`，删除前验证绝对路径仍在 `%TEMP%` 且叶名称匹配固定前缀。
- [ ] 运行测试并确认 RED 原因是代理文件不存在，而不是语法或环境错误。

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1
```

Expected: FAIL，明确报告 `transparent_https_proxy.py` 缺失。

## Task 2：实现最小安全透明代理

**Files:**

- Create: `D:\jm\jmcomic-api-main\tests\fixtures\transparent_https_proxy.py`
- Modify only if test needs correction: `D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1`

- [ ] CLI 要求显式 `--listen-host`、`--listen-port`、`--upstream-host`、`--upstream-port`、`--work-dir`、`--state-file`；listen/upstream 只允许 `127.0.0.1`。
- [ ] 在临时目录生成短期 RSA-2048 CA/leaf；leaf SAN 精确包含设计文档的 14 个主机。state JSON 写出 CA 路径、SHA-256 指纹、PID、地址和白名单。
- [ ] 只实现 CONNECT:443、GET、无请求体；对 CONNECT、SNI、Host 和主机类型路径做精确校验。
- [ ] 上游 socket 永远连接固定 loopback fixture，并保留原始 Host；请求和响应设置大小、超时、线程上限。
- [ ] 禁止通用转发、DNS、明文 HTTP proxy、证书安装命令和跳过 TLS 验证选项。
- [ ] 运行代理 runtime 至 GREEN；失败时只修复能解释失败的最小代码，不增加重复用例。

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1
```

Expected: PASS，一条汇总输出四类断言均通过。

## Task 3：补全配置源 fixture 合同

**Files:**

- Modify: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php`
- Modify: `D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1`

- [ ] 先给 runtime 增加一个断言：经 config 白名单主机请求 `/newsvr-2025.txt`，返回内容必须能由现有 fixture 规则表示为加密域名配置，而不是普通 API envelope。
- [ ] 运行并确认仅该断言 RED。
- [ ] 在 fixture 中按 config 主机 + 精确路径返回 `encryptedDomainConfig()`；域名顺序固定为恢复版 `API_DOMAINS`，不改其他场景。
- [ ] 运行 PHP lint 和代理 runtime 至 GREEN。

Run:

```powershell
& D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe -l D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1
```

## Task 4：建立 RED A/B 小样本 runtime

**Files:**

- Create: `D:\jm\jmcomic-api-main\tests\transparent-https-performance-runtime.ps1`
- Expected missing: `D:\jm\jmcomic-api-main\scripts\transparent-https-performance.ps1`

- [ ] runtime 调用真实编排脚本的 `warmup=1`、`iterations=2`、`concurrency=2`，不复制代理测试。
- [ ] 只断言最终报告不可省略的证据：两个版本五条路由成功、源码哈希前后相同、同一代理/fixture、共同环境指纹、`historical-common-denominator-v1`、`comparable=true`、清理 complete 和临时目录消失。
- [ ] runtime 在 `finally` 删除 smoke JSON，避免把小样本误当正式证据。
- [ ] 运行并确认 RED 原因是编排文件不存在。

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-performance-runtime.ps1
```

Expected: FAIL，明确报告编排脚本缺失。

## Task 5：实现生命周期与共同指标编排

**Files:**

- Create: `D:\jm\jmcomic-api-main\scripts\transparent-https-performance.ps1`
- Modify only if runtime expectation is wrong: `D:\jm\jmcomic-api-main\tests\transparent-https-performance-runtime.ps1`

- [ ] 参数默认值：恢复版目录、当前 `index.php`、bundled Python、PHP 8.3.32、APCu DLL、warmup 10、iterations 120、concurrency 10；允许 smoke 覆盖为 1/2/2。
- [ ] 校验工具/扩展/源码哈希；使用受控临时根和有限端口重试。
- [ ] 启动同一个 fixture/代理；代理 ready 后验证 state PID、地址、CA 文件和指纹。
- [ ] 保存父进程相关环境；为 API 子进程设置 `https_proxy`/`HTTPS_PROXY`、`CURL_CA_BUNDLE`、`SSL_CERT_FILE`，并仅给该 PHP 进程传入 `curl.cainfo`/`openssl.cafile`；清空会绕过生产 HTTPS 的 `JM_TEST_*` 上游变量。
- [ ] 两个版本使用相同 PHP `-n/-d` 参数、APCu 128M、`allow_url_fopen=0`、单 worker、API 端口和输入；每版独立新 PHP 进程/APCu 冷状态。
- [ ] 每版 fixture reset 后确认零计数；采集 cold、warm-up、正式 raw samples、warm summary、一次图片队列探针、health 策略快照和 fixture counts。
- [ ] 五条正式路由固定为 health、latest_page_1、latest_page_4、album、image_no_prefetch。
- [ ] canonical 外部环境指纹不包含源码/运行策略；源码和策略分别记录。每阶段实时核对代理/fixture PID、启动时间和 nonce，运行只读 fixture/代理副本并核对哈希；只有两版 status 200 + fixture 专属业务合同成功、loopback 路由覆盖完整、外部指纹相同、历史四文件/manifest 未变化并最终清理完成时才生成百分比。
- [ ] `finally` 停止 API/proxy/fixture、恢复环境、验证证书库未安装 CA、删除受控临时树。最终 JSON 写到项目内调用方指定路径。
- [ ] 脚本语法解析通过；功能 GREEN 留给下一步一次小样本 runtime，避免重复执行昂贵流程。

## Task 6：小样本 smoke，诊断到成功

**Files:**

- Modify as required by observed defect: proxy/runtime/orchestrator/fixture files only
- Create temporary output, deleted after validation

- [ ] 运行 `warmup=1`、`iterations=2`、`concurrency=2`。
- [ ] 断言两版五条路由均成功、fixture 计数非零、同一 proxy/fixture 身份、`comparable=true`、清理 complete、临时目录不存在。
- [ ] 若失败，按系统化调试顺序定位：进程启动 → proxy env → CA trust/SAN → CONNECT/Host/path → fixture 加密 → PHP/API 业务响应 → 报告门禁；不得通过关闭证书校验或跳过门禁修复。
- [ ] smoke 通过后删除临时 smoke 报告，避免把它误当正式证据。

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-performance-runtime.ps1
```

## Task 7：正式 A/B 测量

**Files:**

- Create: `D:\jm\jmcomic-api-main\performance-evidence\transparent-https-common-ab-20260717.json`

- [ ] 测量前重新确认恢复版/当前版源码 SHA-256，fixture/proxy/编排文件在测量期间不再编辑。
- [ ] 运行正式参数 warmup 10、iterations 120、concurrency 10。
- [ ] 解析 JSON 并独立核验：schema、样本数、成功率、p50/p95/p99、fixture 计数、外部指纹、源码哈希、同一进程身份、`comparable=true`、`cleanup.complete=true`。
- [ ] 计算报告文件 SHA-256；只报告文件实际包含的百分比，不手工外推真实公网性能。

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\scripts\transparent-https-performance.ps1 `
  -WarmupIterations 10 -Iterations 120 -Concurrency 10 `
  -OutputPath D:\jm\jmcomic-api-main\performance-evidence\transparent-https-common-ab-20260717.json
```

## Task 8：一次性相关回归与交付报告

**Files:**

- Modify: `D:\jm\jmcomic-api-main\docs\performance-delivery-report.md`

- [ ] 运行一次：PHP lint、代理 runtime、A/B 小样本 runtime、现有 API 五项静态合同。仅当共享业务源码变化时才增加运行时业务套件。
- [ ] 不重建 APK、不重复扩展测试：本次没有修改扩展源码或产物。
- [ ] 更新交付报告：列出设计/计划/代理/测试/编排/fixture/正式证据，记录 SHA-256、关键共同指标、适用范围和清理证明。
- [ ] 明确区分：正式本地成功路径 A/B、既有 after-only、本地/生产失败路径证据；不得混合百分比。
- [ ] 再次确认恢复目录四个权威哈希、当前源码哈希、APK 哈希与既有交付物未被意外改动。
- [ ] 检查没有 `%TEMP%\jm-transparent-https-*` 残留进程/目录，CA 指纹不在系统 Root。

Final verification:

```powershell
& D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe -l D:\jm\jmcomic-api-main\index.php
& D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe -l D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-proxy-runtime.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File D:\jm\jmcomic-api-main\tests\transparent-https-performance-runtime.ps1
```

## 自主执行停止条件

只有以下情况可以停止：

1. 上述实现、正式证据、清理和交付报告全部完成并有新鲜验证输出；或
2. bundled Python/PHP/APCu 等真实外部依赖不可用，且所有不依赖它的工作已经完成，并给出原始错误与精确恢复命令；或
3. 必须改变获批安全边界或产品公共契约，需要用户决定。

一次测试失败、耗时较长、端口冲突或实现复杂都不是停止理由；必须诊断、修复并复测。不得通过减少 TLS 验证、放宽白名单、修改恢复源码、伪造样本或手填百分比来“完成”。
