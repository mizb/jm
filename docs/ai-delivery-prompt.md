# AI 自主收尾指令：JM API 跨项目交付

本文件用于后续 AI 继续当前交付，主要事实、测试、哈希、外部阻塞和命令均在最终报告内。

## 权威读取顺序

1. `D:\jm\jmcomic-api-main\docs\performance-delivery-report.md`
2. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-13-cross-project-performance-design.md`
3. `D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-13-cross-project-performance-delivery.md`
4. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-14-java-reference-adoption-audit.md`
5. `D:\jm\jmapi-extension\docs\apk-optimization-design.md`
6. `D:\jm\jmapi-extension\docs\ai-delivery-prompt.md`

项目路径固定为：

- API：`D:\jm\jmcomic-api-main`
- 扩展：`D:\jm\jmapi-extension`

## 当前检查点

- API：`2026.07.13.2`。
- APK：`1.4.13 / versionCode 13`。
- 本机代码、静态/运行时测试、Keiyoushi 构建、元数据和 Suwayomi 2.3.2243 回归已经完成。
- 当前剩余项只包括：Docker-capable 主机上的真实多 worker 验收、可溯源旧版 BEFORE 到位后的同条件对比，以及用户提供稳定生产密钥后的正式签名。
- 两项目没有 Git 元数据；真实 BEFORE 不存在。不得伪造 diff、commit、基线或提升百分比。

开始时先比较最终报告所列源码与产物哈希：

- 哈希未变化：不要重做已完成的本机实现或 Suwayomi 回归，直接检查外部条件是否已具备。
- 哈希发生变化：调查变化来源，保留用户改动，按影响范围重新执行合同、运行时、构建和回归，并更新最终报告。
- Docker、历史 BEFORE 或生产密钥仍不可用：完成其他所有可执行工作后，保留精确错误证据和可复制命令；不要以猜测代替结果。

## 不可偏离契约

- 端口保持 `8088`；APK 只访问 PHP API。
- Popular=`promote`，Latest=`weekly`。
- 筛选显示“排序 / 最新 / 最多浏览 / 最多点赞”；空搜索、标题搜索、JM ID/URL 的 `new/mr`、`mv/mv`、`tf/tf` 和 `jmid` 映射保持不变。
- API JSON、章节顺序和 decoded-page URL 对外结构保持兼容。
- 不新增 APK 图片缓存、Redis 图片缓存、decoded image 文件缓存或 `/app/cache` 图片卷。
- 不启用未经真实 A/B 的 `/comic_read` 生产替换。
- `prefetch=0` 同时禁止普通预取和下一章预热，重新启用时必须移除。
- 生产 HTTPS/test-mode 白名单、可信代理、CDN allowlist、压缩字节/像素/容器校验和缓存 attestation 不得弱化。
- `initialized` 不能作为盲目减少请求的开关；当前真实请求证据支持继续使用 API album cache。

## 自主执行规则

1. 遇到失败先复现和定位根因，再做单一最小修复。
2. 修改行为前补充或确认失败测试；修复后运行聚焦测试、相关全量回归和构建。
3. 不得停在分析、方案或半成品；可安全执行的下一步必须继续。
4. 不使用 reset/checkout/revert 覆盖现有文件，不修改其他项目。
5. 未实际执行的检查不得写“通过”；fixture 成功不得冒充真实上游成功。
6. 所有新证据、哈希、部署/回滚变化和剩余风险都写回最终报告。

## 外部条件到位后的命令

Docker-capable 主机：

```powershell
Set-Location D:\jm\jmcomic-api-main
docker compose build --no-cache
docker compose up -d --force-recreate
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\runtime-verify.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\fault-injection-runtime.ps1
```

只有取得可匹配旧版源码/镜像且测量条件完全一致的 `performance-before.json` 后，才执行正式 compare；否则只保留 after-only。

正式签名时必须使用用户提供、可长期保存的生产密钥，更新 APK、索引指纹并验证升级签名连续性；不得用新证书覆盖已发布包而不说明迁移。

## 简短启动提示词

> 完整读取并严格执行 `D:\jm\jmcomic-api-main\docs\ai-delivery-prompt.md`。先核对最终报告哈希；未变化时不要重做已验证工作，只自主完成已具备条件的外部验收并更新报告。失败必须根因诊断、最小修复、全量复测；严守固定筛选/API/章节/缓存安全契约，禁止伪造 Git、BEFORE 或性能百分比，持续到所有可执行项完整交付。
