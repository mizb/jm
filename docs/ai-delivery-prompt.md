# AI 自主收尾指令：JM API 跨项目交付

本文件用于后续 AI 继续当前交付，主要事实、测试、哈希、外部阻塞和命令均在最终报告内。

## 权威读取顺序

1. `D:\jm\jmcomic-api-main\docs\performance-delivery-report.md`
2. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-13-cross-project-performance-design.md`
3. `D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-13-cross-project-performance-delivery.md`
4. `D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-14-java-reference-adoption-audit.md`
5. `D:\jm\jmcomic-api-main\docs\bug-hunt-2026-07-17.md`
6. `D:\jm\jmapi-extension\docs\superpowers\specs\2026-07-17-canonical-page-url-design.md`
7. `D:\jm\jmapi-extension\docs\apk-optimization-design.md`
8. `D:\jm\jmapi-extension\docs\ai-delivery-prompt.md`

项目路径固定为：

- API：`D:\jm\jmcomic-api-main`
- 扩展：`D:\jm\jmapi-extension`

## 当前检查点

- API：`2026.07.17.2`。
- APK 交付版本：`1.4.15 / versionCode 15`；`1.4.14 / 14` 的反代子路径页面 URL 缺陷仅作为历史 RED 基线。
- 当前 API `index.php` SHA-256：`7A2AC07AE89CA4EE0ADB4F4B8435C8038CE656769295FD48B8126F01CD870484`；v1.4.15 APK SHA-256：`A1FD20677F53784CEAED728BCFC0A44E40DD53D35C47A582E1A3195D51B57872`。
- 本机代码、聚焦静态/运行时测试、Keiyoushi 构建、元数据和 Suwayomi 2.3.2243 真实回归已经完成。
- 正式同条件性能 A/B 只绑定恢复版 `A88271DC…7A13ED` 与上一版 `.1 / 53A15D40…A3B5C`；证据 SHA-256 为 `6ECB423EFE3A2B66B196B1AEC9D15D96CA8F1DB0732ABD01181A24F5CDB2F9B5`，不得冒充当前 `.2`。
- `.2` 已针对现场 Latest 502 增加五域最多两轮、默认 10 次的瞬态网络恢复，仍受 12 秒总预算限制；优先部署并核对 `X-JM-API-Version: 2026.07.17.2` 与现场结果。
- 历史透明 HTTPS A/B 绑定恢复版 `A88271DC…7A13ED` 与历史 AFTER `680AF597…18FB7C / 2026.07.13.2`；后一份 A/B 绑定恢复版与 `.1 / 53A15D40…A3B5C`。两份证据都不代表当前 `.2`，不得混用。
- 两项目没有 Git 元数据；原始修改时间点没有预先测量 BEFORE。不得伪造 diff、commit、基线或提升百分比。

开始时先比较最终报告所列源码与产物哈希：

- 哈希未变化：不要重做 APK、Suwayomi 或 `.1` A/B；优先完成 `.2` 现场 502 复验和 Docker 外部验收。
- 哈希发生变化：调查变化来源，保留用户改动，按影响范围重新执行合同、运行时、构建和回归，并更新最终报告。
- Docker 或生产密钥仍不可用：完成其他所有可执行工作后，保留精确错误证据和可复制命令；不要以猜测代替结果。
- Suwayomi 与 API 若位于不同容器，扩展中的 `127.0.0.1` 指向 Suwayomi 容器自身；必须使用该容器可访问的 API 服务名、平台支持时的 `host.docker.internal` 或局域网地址。

## 不可偏离契约

- 端口保持 `8088`；APK 只访问 PHP API。
- Popular=`promote`，Latest=`weekly`。
- 筛选显示“排序 / 最新 / 最多浏览 / 最多点赞”；空搜索、标题搜索、JM ID/URL 的 `new/mr`、`mv/mv`、`tf/tf` 和 `jmid` 映射保持不变。
- API JSON、章节顺序和 decoded-page URL 对外结构保持兼容。
- `pageListParse()` 必须忽略 API 载荷中的绝对 `images[].url`，按当前已校验 endpoint 和 album/chapter/page 重建每页 URL；不得通过放宽 base-path 同源规则来掩盖反代前缀丢失。
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

> 完整读取并严格执行 `D:\jm\jmcomic-api-main\docs\ai-delivery-prompt.md`。先部署并验证 API `2026.07.17.2 / 7A2AC07A…70484` 的 Latest 502 修复；不要重做未变化的 APK/Suwayomi，也不得把 `.1` A/B 冒充 `.2`。失败时按 request-id 诊断并最小修复；随后自主完成具备条件的 Docker 验收，严守固定筛选/API/章节/页面 URL/缓存安全契约。
