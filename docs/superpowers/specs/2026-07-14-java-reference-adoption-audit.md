# JMComic-Api-Java 全项目参考采纳审计

> 审计日期：2026-07-14  
> Java 参考仓库：`D:\jm\JMComic-Api-Java-master`（下文记为 `J/`）  
> 当前 API：`D:\jm\jmcomic-api-main\index.php`（下文记为 `P/index.php`）  
> 当前扩展：`D:\jm\jmapi-extension`（下文记为 `K/`）  
> 当前交付计划：`D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-13-cross-project-performance-delivery.md`（下文记为 `Plan/`）

## 1. 执行摘要

本次不是抽样审阅。清理 Maven 生成目录后，审计以仓库根执行的 `rg --files --hidden -g '!.git/**'` 建立 167 个文件的闭合清单；为使复核不受构建产物影响，稳定源码统计另用同一命令追加 `-g '!**/target/**'`，结果同为 167。范围覆盖根 POM、两条 workflow、两份 README、30 个文档文件、四个模块全部 123 个生产 Java 源文件、两个资源文件；仓库不存在 `src/test`，测试 Java 文件为 0。完整计数和逐文件清单见附录 A、B。

结论是：**Java 仓库适合作为“协议容错、资源边界、可注入性和状态可观测性”的反例与模式来源，不适合整体移植。** 当前 PHP/Kotlin 目标是短生命周期 HTTP 服务和 Suwayomi 扩展，不是 JVM 下载 SDK；Java 的本地下载任务树、双线程池、全域同步探活、进程级可变常量、任意 URL 下载和磁盘临时文件语义与现有目标契约冲突。可采纳内容必须改造成当前架构的 APCu、请求预算、fixture、相对 media path 和 Kotlin DTO/validator 形式。

本轮最重要的结论如下。

1. **跨项目协议缺口已确认。** Java 的图片解析同时处理 primitive 与 `{ "image": primitive }`（正常协议样本是 string；实现还会把 number/boolean primitive 转成字符串，`J/jmcomic-core/src/main/java/io/github/jukomu/jmcomic/core/parser/ApiParser.java:447-457`）；PHP 先保留数组元素，再用只接受 PHP scalar 的 `scalarString()` 转换并跳过空值（`P/index.php:1158-1162`, `P/index.php:2020-2023`），因此 `json_decode(..., true)` 形成的对象图片关联数组会被静默丢弃，混合数组还会造成页码收缩。PHP 8.3 一次性复现也得到 `is_scalar=false, normalized='', would_skip=true`。该问题必须进入 Task 7 的 string/object/malformed-object fixture、payload validator、兼容解析和 cache schema 升级，并在 Task 10 做端到端回归。
2. **`/comic_read?id=...` 是可测候选，不是可直接替换。** Java 为该端点提供独立方法（`J/jmcomic-core/src/main/java/io/github/jukomu/jmcomic/core/client/impl/JmApiClient.java:199-207`），解析器从一次响应读取 `images` 与 `scramble_id`（`J/jmcomic-core/src/main/java/io/github/jukomu/jmcomic/core/parser/ApiParser.java:174-222`）。但同一 Java 客户端的正式 `getPhoto()` 仍保留 `/chapter` 加 `/chapter_view_template` 两请求路径（`J/.../JmApiClient.java:211-255`），证明作者也没有把两者视为无条件等价。应在 Task 7 建 fixture/适配器、Task 10 做真实 A/B，保留现有 fallback。
3. **Java 自身存在多条高风险生命周期缺陷。** 基类构造器提交捕获 `this` 的异步任务并调用可覆盖方法，且初始化异常没有 `finally` 释放 latch（`J/.../AbstractJmClient.java:78-113`; `J/.../JmDomainManager.java:267-275`）；`close()` 又可能先于 scheduler 创建（`J/.../AbstractJmClient.java:1074-1104`; `J/.../JmDomainManager.java:212-258`）。这些设计不能移植。
4. **下载与图片路径不能照搬。** 响应、gzip、图片和重编码均存在无上限整包驻留，OOM 是输入规模触发的高风险而非本轮实测事件（`J/.../CommonResponse.java:117-170`; `J/.../ImageDownloadTask.java:178-210,256-261`）；固定 `.tmp` 在同目标并发时竞争（同步路径 `J/.../AbstractJmClient.java:329-357`，任务构造 `:862-864` 与写入 `J/.../ImageDownloadTask.java:277-303`）；AWT 未释放 Graphics，Android 异常路径没有及时 recycle。当前 API 明确不引入 decoded image 文件缓存，因此只采纳“唯一临时名、同目录原子提交”的通用规则，不能借此新增 `/app/cache`。
5. **根构建成功不等于交付可信。** 使用 Temurin 17.0.19 与 Maven 3.9.11 fresh 执行根 reactor `clean verify`，4/4 模块 exit 0，但 api/core/android 均明确输出 `No tests to run`，sample 仍被根 POM 注释掉（`J/pom.xml:11-17`）。sample 独立构建在使用 Central 依赖和本地安装当前 reactor 两种条件下都 exit 1，均为 11 个 javac 错误，根因是 `DownloaderSample.java:3-4` 的错误包导入（真实类型在 `J/jmcomic-api/src/main/java/io/github/jukomu/jmcomic/api/download/DownloadProgress.java:21` 与 `DownloadResult.java:16`）。CI 因而看不到该错误（`J/.github/workflows/ci.yml:34-35`）；release workflow 只建 GitHub Release，不 checkout、不构建、不上传 jar（`J/.github/workflows/release.yml:27-40`）。
6. **工具链已实际定位并使用。** 便携 JDK 17、Maven 3.9.11、PHP 8.3、MinGit、Android SDK 与 Gradle 均在 `D:\jm\.tools`；系统 PATH 缺命令不能推导本机缺工具。目录本身没有 `.git`，Docker 不存在。根 Maven、sample 和 PHP lint/shape 复现结果见附录 F；未运行 Android APK/D8/instrumentation、Docker 或真实 JM 上游 A/B，不能声称这些通过。

所有候选都在第 10 节逐项给出“采纳 / 改造后采纳 / 拒绝 / 待 A/B 验证”决策，并明确落入 Task 5/6/7/8/10 或未来项；复合候选（例如“本轮拒绝文件缓存、未来导出才采用唯一 tmp”）按当前契约和未来边界分别说明，不用互斥数量掩盖条件。

## 2. 审计方法与证据规则

### 2.1 闭合范围

- 文件全集：在 Java 根目录清理 `target` 后执行 `rg --files --hidden -g '!.git/**'`，得到 167 个文件；构建期间 raw 结果会包含 573 个 `target` 文件而变为 740，因此稳定源码复核固定追加 `-g '!**/target/**'`，仍为 167。
- Java 全集：匹配 `**/src/main/java/**/*.java`，得到 123 个生产源；匹配 `**/src/test/java/**/*.java`，得到 0 个测试源。
- 模块计数：`jmcomic-api` 85、`jmcomic-core` 33、`jmcomic-android-support` 1、`jmcomic-sample` 4。
- 非 Java：5 个 POM、2 个 workflow、2 个 README、30 个 docs 文件、2 个运行时资源、`.gitignore`、`.readthedocs.yaml`、`LICENSE`，共 44。
- 每个生产文件在附录 B 只出现一次；模块主分析给出职责和风险归类，避免“列名但未分析”。

### 2.2 判断等级

- **确认**：代码存在确定性路径，可由静态语义直接推出。
- **边界确认**：风险依赖输入信任、并发或运行环境，文中明确触发条件。
- **待 A/B**：参考实现证明能力存在，但没有当前真实上游等价性证据。
- **拒绝**：与当前目标契约冲突，或参考实现的代价/风险高于收益。

所有 Java 关键判断都附 `J/<file>:line`；PHP、Kotlin 和计划对照附 `P/`、`K/`、`Plan/` 证据。Java 目录没有 `.git`，即使便携 MinGit 可用也无法记录 commit SHA；行号以 2026-07-14 本地快照为准。

## 3. 仓库结构与技术基线

| 层 | 基线 | 审计结论与证据 |
|---|---|---|
| 根构建 | Maven 聚合，Java 17；reactor 含 api/core/android，不含 sample | `J/pom.xml:11-17,46-60`。sample POM 存在但默认 CI/release 均不编译它。 |
| `jmcomic-api` | 纯 Java 公共契约；85 源文件、89 public 类型 | 4 client、8 download、10 enum、7 exception、53 model、3 strategy 是**文件数**；另有 4 个 public nested 类型，见 4.1.1。POM 无第三方依赖（`J/jmcomic-api/pom.xml:12-19`）。 |
| `jmcomic-core` | OkHttp 4.12、Gson 2.13.2、Jsoup 1.17.2、webp-imageio、SLF4J | 版本在 `J/pom.xml:51-57`，模块依赖在 `J/jmcomic-core/pom.xml:19-88`。网络、解析、缓存、下载和 AWT 混在一个实现模块，资源/线程所有权集中于 `AbstractJmClient`。 |
| Android support | 单一 Bitmap `ImageProcessor` + ServiceLoader 注册 | `J/jmcomic-android-support/.../AndroidImageProcessor.java:23-102`；provider 文件第 1 行。它是普通 Maven jar，使用老 `com.google.android:android:4.1.1.4` provided stub（`J/jmcomic-android-support/pom.xml:19-30`），没有 Android Gradle/D8/instrumentation 验证。 |
| sample | 4 个 `main` 示例 | `J/jmcomic-sample/pom.xml:14-21` 依赖 core，但根 POM 排除；其中下载示例无法按当前 import 编译。 |
| CI | Ubuntu + Temurin 17，`mvn ... clean verify`，上传 surefire | `J/.github/workflows/ci.yml:17-42`。本机等价 reactor exit 0，但 0 个测试且 sample 不在 reactor，因此“Build & Test (all modules)”名称不准确。 |
| Release | tag/dispatch 创建 GitHub Release | `J/.github/workflows/release.yml:27-40`。无 checkout、Maven、Central publish 或 release asset，无法证明发布内容对应源码。 |
| 文档 | MkDocs Material，28 个 source + 2 配置 | `J/docs/mkdocs.yml:37-85` 覆盖全部 source 导航；ReadTheDocs 用 Python 3.12（`J/.readthedocs.yaml:5-17`）。版本统一写 1.1.7，但 changelog 只到 1.1.0（`J/docs/sources/changelog.md:1-17`）。 |

### 3.1 构建与发布的实际含义

根 POM 在 `verify` 绑定 GPG 签名（`J/pom.xml:240-253`），CI 显式传 `-Dgpg.skip=true`（`J/.github/workflows/ci.yml:34-35`）；源码/javadoc jar 由父构建配置（`J/pom.xml:145-178,212-227`），但 release workflow 从未调用 Maven。中央发布插件设置 `autoPublish=false`（`J/pom.xml:229-238`），仓库内也没有部署 job。因此只能确认“源码声明了发布插件”，不能确认“tag 会构建或发布 artifact”。

### 3.2 README 与文档一致性

完整阅读两份 README 和 30 个 docs 文件后，确认以下文档偏差：

- README 称 record 为不可变对象（`J/README.md:92-103`），但如 `JmAlbum`、`JmPhoto` 直接保存并返回可变 List（`J/.../JmAlbum.java:63-104,227-318`; `J/.../JmPhoto.java:35-43,109-119`），只是浅层 record。
- README 的“库本身不负责线程调度和文件 I/O”与实现相反（文字 `J/README.md:134-140`；代码自行创建线程池 `J/.../AbstractJmClient.java:89-102` 并写文件 `:329-357`）。
- 配置文档把自定义 domain 写成带 scheme 的 URL（`J/docs/sources/configuration.md:15-20,85-86`），实现却把字符串直接传给 `HttpUrl.Builder.host()`（`J/.../AbstractJmClient.java:170-177,1051-1058`），契约实际应是纯 host 或先规范化。
- 首次下载文档称图片默认保存为 jpg（`J/docs/sources/quickstart/first-download.md:60-64`）；实现使用净化后的原文件名并按原扩展编码（`J/.../AbstractJmClient.java:318-359`; `J/.../AwtImageProcessor.java:72-76`）。
- `ConfigUsage` 注释称不发网络请求，却在末尾创建客户端，构造器立即提交域名更新/探活/setting 请求（`J/jmcomic-sample/.../ConfigUsage.java:10-14,46-49`; `J/.../AbstractJmClient.java:102-113`）。
- Android 文档仅说“与标准 Java 一致”（`J/docs/sources/android.md:13-32`），未说明 core 含 AWT/webp 依赖、Bitmap 峰值内存和 scoped storage；当前 CI 也没有 APK/D8 证据。

## 4. 逐模块分析

### 4.1 `jmcomic-api`：公共契约、模型与异常

该模块 85 个顶层文件全部是 public 契约，连同 4 个 nested 类型共有 89 个 public 类型。优点是调用面集中，缺点是类型安全并不一致：`JmClient` 仍公开多个 raw `Map`/`List`（`J/jmcomic-api/src/main/java/io/github/jukomu/jmcomic/api/client/JmClient.java:108,315,362,403-438,466-474`），`JmNovelClient.buyNovelChapter` 也返回 raw `Map`（`J/.../JmNovelClient.java:115`）。当前 PHP 不应复制这种“接口看似 typed、边缘端点退回 raw 容器”的方式；Task 5/7 的 cache payload 必须分别有 schema validator。

| 文件组 | 逐文件职责 | 结论 |
|---|---|---|
| `client/` 4 文件 | `JmClient` 漫画/用户/评论/收藏/发现/任务；`JmDownloadClient` 下载、链式请求、任务工厂和 `close`；`JmNovelClient` 小说；`JmCreatorClient` 创作者 | 能力拆分值得参考，但只有 `JmDownloadClient` 继承 `AutoCloseable`（`J/.../JmDownloadClient.java:26,226-227`），主 `JmClient` 本身未表达生命周期。PHP 是请求级服务，不照搬 Java client 层级。 |
| `download/` 8 文件 | `DownloadRequest/Result/Progress`，`IDownloadManager`，`BaseDownloadTask/TaskObserver`，`TaskState/TaskType` | 进度值对象和显式状态有价值；具体状态机有卡死与 observer 隔离缺陷，见第 6 节。`DownloadResult` 只包装原集合而不复制（`J/.../DownloadResult.java:20-22`），不能当强快照。 |
| `strategy/` 3 文件 | album/photo/完整图片路径生成 SPI，两个局部策略支持 `andThen`（`J/.../IAlbumPathGenerator.java:17-34`; `IPhotoPathGenerator.java:17-34`） | 策略边界可参考，但当前 PHP 不接受用户任意落盘路径；只采纳为受控图片处理/transport 注入，不开放路径 SPI。 |
| `exception/` 7 文件 | `JmComicException` 根；network/parse/response/resource-not-found 与 album/photo 特化 | 层级清楚，但全为 unchecked，且实现会吞异常；当前 PHP 应保留 `ApiFailure` 的可重试/硬失败分类，不按 Java 类名机械映射。 |
| `enums/` 10 文件 | category、subcategory、order、time、comment/favorite/forum/vote/client | 值映射可作 fixture 参考；当前扩展 `new/mr`、`mv/mv`、`tf/tf` 契约优先，不因 Java enum 改动（`J/.../OrderBy.java:9-38`）。 |
| `model/` 53 文件 | 50 顶层 record + `SearchQuery`、`FavoriteQuery`、`ForumQuery`；另有 nested record `JmCreatorWorkDetail.Image` 和三个 public `Builder` | 字段覆盖广，可用于命名对照；record 无统一 compact constructor、List/Map 防御复制和统一 null 规则，不能直接作为 PHP schema 真相。完整模型名见附录 B.1。 |

三个核心模型还暴露额外边界：`JmImage` 可由调用方构造任意 URL（`J/.../JmImage.java:11-35,138-143`）；`JmAlbum.getPhotoMeta()` 对越界采取夹取而不是失败，空章节列表则 `get(0)` 崩溃（`J/.../JmAlbum.java:353-369`）；`JmImage.equals()` 对多个字段直接 `.equals`，手工构造 null 会 NPE（`J/.../JmImage.java:157-166`）。当前 validator 应明确失败，不能复制这些隐式容错。

#### 4.1.1 公开接口、模型、异常与扩展面闭合

- **11 个 public interface 全部列出**：api 的 `JmClient`（声明 `J/.../JmClient.java:18`）、`JmCreatorClient`（`:11`）、`JmDownloadClient`（`:26`）、`JmNovelClient`（`:14`）、`IDownloadManager`（`:13`）、`TaskObserver`（`:13`）、`IAlbumPathGenerator`（`:17`）、`IDownloadPathGenerator`（`:18`）、`IPhotoPathGenerator`（`:17`）；core 的 `ImageProcessor`（`J/.../ImageProcessor.java:14`）与 `DomainProbe`（`J/.../DomainProbe.java:12`）。四个 client 接口分别有 52/5/22/11 个 public 方法；其余 SPI 分别为 8/4/2/1/2/1/1 个方法（两个 `andThen` 计入对应 path SPI）。
- **57 个 public model-package 类型全部闭合**：50 个顶层 record（附录 B.1 完整列名）、nested record `JmCreatorWorkDetail.Image`（`J/.../JmCreatorWorkDetail.java:97`）、3 个 query class，以及 `FavoriteQuery.Builder`（`J/.../FavoriteQuery.java:48`）、`ForumQuery.Builder`（`J/.../ForumQuery.java:119`）、`SearchQuery.Builder`（`J/.../SearchQuery.java:102`）。`DownloadProgress`（`J/.../DownloadProgress.java:21`）是 model package 外的第 52 个 api public record。
- **7 个 public exception 全部闭合**：`JmComicException`、`NetworkException`、`ParseResponseException`、`ResponseException`、`ResourceNotFoundException`、`AlbumNotFoundException`、`PhotoNotFoundException`（声明分别在对应文件 `:9-10`）；共 15 个构造器，另有 `ResponseException.getErrorCode()` 与 `ResourceNotFoundException.getResourceId()`。
- fresh Javadoc 索引与源码对账得到全仓 129 个 public 类型；成员索引为 1,664 public + 57 protected。模块明细为 api `89 / 1,194 / 26`、core `35 / 460 / 31`、android `1 / 2 / 0`、sample `4 / 8 / 0`（格式为 public type/public member/protected member）。api 的 26 个 protected 成员都属于 `BaseDownloadTask`（字段从 `J/.../BaseDownloadTask.java:22` 起，关键扩展方法 `:120,226,293,317`）；core 另有 public nested `JmConfiguration.Builder`（`J/.../JmConfiguration.java:158`）和 `OkHttpBuilder.HttpClientContext`（`J/.../OkHttpBuilder.java:69`）。这组数字用于闭合审计，不表示建议把所有实现类都当稳定 API。

### 4.2 `jmcomic-core`：33 个生产文件

| 文件 | 职责与审计结论 |
|---|---|
| `JmComic.java` | 工厂校验 ClientType，构造 OkHttp context 后创建具体 client（`J/.../JmComic.java:30-55`）；没有构造失败时回收已创建资源的保护。 |
| `cache/CacheKey.java` | `(Class<?>, id)` typed key（`J/.../CacheKey.java:11-35`）；**采纳其类型隔离思想**，但当前 PHP 使用 `<class>:vN:<sha256(canonical fields)>`，不能存 JVM Class。 |
| `cache/CacheObjectSizer.java` | 以 Gson JSON 字节估堆大小，失败按 1 byte（`J/.../CacheObjectSizer.java:13-23`）；估算不可信，拒绝移植。 |
| `cache/CachePool.java` | 加锁 LFU；新条目有单项超容量拒绝（`J/.../CachePool.java:99-130`），但更新已有 key 不复验容量（`:105-112`），且无 TTL/single-flight/身份维度。只采纳“单项超容量不缓存”。 |
| `client/AbstractJmClient.java` | 网络、cookie、缓存、同步/异步下载、文件写入、密码内存加密、初始化和 close 的总基类；职责过载，并含 latch、this-escape、双 CPU pool、SSRF、tmp 与 close 竞态。不能整体采纳。 |
| `client/impl/JmApiClient.java` | 移动 API 全端点、签名/解密、`/comic_read`、`/chapter`；远端 setting 可改进程级 APP_VERSION，并尝试向默认不可变 CDN list `add`（`J/.../JmApiClient.java:102-118`; `J/.../JmConstants.java:129-136`）。在该 public static list 没被调用方重绑定且前序解析成功的默认状态下，非 null `img_host` 确定抛 `UnsupportedOperationException`。 |
| `client/impl/JmHtmlClient.java` | HTML 端点与大量 unsupported fallback；并发抓 10 个 GitHub 页创建独立线程池（`J/.../JmHtmlClient.java:763-827`），加剧每 client 线程数；HTML 解析随 DOM 漂移。 |
| `config/JmConfiguration.java` | Builder、properties、proxy、domain、timeout、executor/cache；允许 probe interval 0（`J/.../JmConfiguration.java:254-257`），随后 `scheduleWithFixedDelay` 要求正数（`J/.../JmDomainManager.java:212-246`），可稳定触发初始化故障。 |
| `constant/JmConstants.java` | 协议常量、密钥、端点、domain/CDN；`APP_VERSION`、默认 API/CDN list 是 public static 非 final（`J/.../JmConstants.java:35,121-136`），跨 client 污染。只把 scramble 阈值作为 golden-vector 输入。 |
| `crypto/JmCryptoTool.java` | token、MD5、AES/ECB/PKCS5 解密；属于上游协议兼容，不作为新安全设计。当前 PHP 已有等价签名/解密，应以 fixture 验证而非复写。 |
| `crypto/JmImageTool.java` | scramble 分段算法 + `ServiceLoader<ImageProcessor>` 首个 provider + AWT 反射 fallback（`J/.../JmImageTool.java:15-44,55-75`）；SPI 思想可采纳，首个 provider/进程静态选择不可采纳。 |
| `download/DownloadManager.java` | registry/active/executor；终态只移出 active，不移出 registry（`J/.../DownloadManager.java:28-30,54-75,125-131`），长生命周期 client 无界增长；close/submit 无 closed gate。 |
| `download/task/AlbumDownloadTask.java` | 聚合 photo 子任务、结果与专辑进度；取消混合终态可永久 CANCELLING（`J/.../AlbumDownloadTask.java:69-77,85-140`）。 |
| `download/task/PhotoDownloadTask.java` | 聚合 image 子任务；与 Album 同样的取消缺陷，且进度按当前瞬时子状态重算。 |
| `download/task/ImageDownloadTask.java` | 流式读取但最终整包解码/写盘；每 256 KiB 回调（`J/.../ImageDownloadTask.java:178-253`）是可参考指标，缺少输入/像素上限和唯一 tmp。 |
| `image/spi/ImageProcessor.java` | 两参数 byte[] -> byte[] 图片 SPI（`J/.../ImageProcessor.java:14-25`）；接口过粗，改造后应返回 bytes/mime/codec/metrics，并接收预算/限制。 |
| `image/AwtImageProcessor.java` | 行段重排与编码；每段 `createGraphics()` 未 dispose、忽略 `ImageIO.write` boolean（`J/.../AwtImageProcessor.java:65-76`），无像素上限。 |
| `net/OkHttpBuilder.java` | 每 client 独立 CookieManager/domain manager，但 retry interceptor 同时覆盖 API 与任意图片 URL（`J/.../OkHttpBuilder.java:36-63`）。 |
| `net/interceptor/UserAgentInterceptor.java` | 把默认及用户全局 headers 加到每个请求（`J/.../UserAgentInterceptor.java:27-57`）；与任意 URL 下载结合会向外部 host 泄露自定义 header。 |
| `net/interceptor/RetryAndDomainRedirectInterceptor.java` | 统一重试/切域；POST、body、GET 无差别，5xx/403/IO 最多 6 次，无业务总 deadline（`J/.../RetryAndDomainRedirectInterceptor.java:36-110`; 默认 5 retries 在 `J/.../JmConfiguration.java:164-166`）。拒绝其语义。 |
| `net/model/CommonResponse.java` | lazy 读取并缓存 body；`body.bytes()` 在 try-with response 作用域内触发关闭（`J/.../CommonResponse.java:117-149`; 调用处 `J/.../AbstractJmClient.java:881-888`）。作用域关闭值得采纳；无界 buffer 与返回内部 byte[] 不采纳。 |
| `net/model/JmResponse.java` | HTTP 成功还要求 body 非空（`J/.../JmResponse.java:39-53`）。 |
| `net/model/JmApiResponse.java` | code + AES data；`requireSuccess()` 自己抛 `ResourceNotFoundException` 后被同一 `catch (JmComicException)` 吞掉（`J/.../JmApiResponse.java:51-63`），not-found 判断失效。 |
| `net/model/JmHtmlResponse.java` | 重定向语义和 HTML text；空串或全非 ASCII 前缀会在 `charAt(0)` 越界（`J/.../JmHtmlResponse.java:54-63`）。 |
| `net/provider/DomainProbe.java` | 可注入 `boolean isReachable(domain)`（`J/.../DomainProbe.java:11-20`）；**采纳可注入探针边界**，但需要返回分类、延迟和 deadline，而非 boolean。 |
| `net/provider/JmDomainManager.java` | list + failure count + initial/periodic probe；list/map 更新非原子，暴露内部 list，全量 commonPool join，无总 deadline（`J/.../JmDomainManager.java:32-46,101-110,145-200`）。同样的 commonPool + allOf/join 还出现在公开延迟查询（`J/.../AbstractJmClient.java:164-198`）。 |
| `parser/ApiParser.java` | 全部 API payload -> 领域模型；对象/字符串图片兼容和 `/comic_read` 是本次最有价值协议证据；随机 CDN 和宽泛 catch 降低确定性。 |
| `parser/HtmlParser.java` | album/photo/search/favorite/comment/域名 DOM 解析；多处 `selectFirst` 后直接解引用（例如 `J/.../HtmlParser.java:307-313,418-424,521-527`），结构漂移常泄漏 NPE。 |
| `parser/ParseHelper.java` | selector、数字、页数等辅助；部分 helper 抛 `ParseResponseException`，但调用方未统一使用，错误分类不闭合。 |
| `strategy/impl/DefaultAlbumPathGenerator.java` | `{author}/{title}/{id}` 并净化组件（`J/.../DefaultAlbumPathGenerator.java:18-29`）；只替换非法字符，不构成服务端路径沙箱。 |
| `strategy/impl/DefaultPhotoPathGenerator.java` | `{sort_title}/{photoId}`（`J/.../DefaultPhotoPathGenerator.java:18-24`）；同上，不适用于当前 API。 |
| `util/FileUtils.java` | 替换 Windows/控制字符（`J/.../FileUtils.java:17-50`）；未处理空名、`.`/`..`、Windows 保留名、尾点空格和根目录约束，不能当完整安全校验。 |
| `util/JsonUtils.java` | 全局 Gson helper；当前 PHP 继续使用原生结构化 JSON 和显式 validator，无需引入等价全局单例。 |

### 4.3 `jmcomic-android-support`

唯一生产文件完整覆盖。正常成功路径会 recycle 两个 Bitmap（`J/jmcomic-android-support/.../AndroidImageProcessor.java:74-84`），但在 decode 后、成功 recycle 前的 `createBitmap`、draw、format、compress 或 `toByteArray` 异常都会进入 catch 而不及时 recycle（`:34-49,74-90`）；对象最终仍可由 GC 回收，因此不能静态夸大为“永久泄漏”，但在大图/旧 Android 内存模型下会扩大峰值和回收压力。`compress()` boolean 也被忽略（`:78`），同时持有压缩输入、原 Bitmap、目标 Bitmap 和输出 BAOS，且无像素/尺寸上限。

结论：Android 实现不进入 PHP/Kotlin 本轮交付；其“平台实现同一图片 SPI”概念可用于 PHP fixture decoder 与生产 GD decoder 分离。若未来维护 Java 仓库，必须用 `try/finally` recycle、检查 compress 返回值、处理 null config，并增加 Robolectric/instrumentation + 大图/OOM 测试。

### 4.4 `jmcomic-sample`

- `ConfigUsage.java`：展示 Builder，但“无网络”注释错误，且 `try` 立即 close 会触发初始化/close 竞态。
- `FetchDataSample.java`：顺序调用漫画、评论、收藏、发现、追踪；`photo.images().get(0)` 无空列表保护（`J/jmcomic-sample/.../FetchDataSample.java:80-86`），真实上游异常时示例崩溃。
- `DownloaderSample.java`：错误导入 `api.client.DownloadProgress/DownloadResult`；两次独立 Maven 编译均以 11 个 javac 错误失败，因 sample 不在 reactor 被 CI 掩盖。
- `PathGeneratorSample.java`：只构造策略，不验证输出沙箱；示例使用绝对 `/downloads`（`J/jmcomic-sample/.../PathGeneratorSample.java:33-45`），Windows/权限行为与其他示例不一致。

sample 应作为至少 compile-only 的 reactor/CI job；fresh 根构建虽 exit 0，但“0 测试 + sample 实测不编译”使 README 代码片段成为唯一软验证，不能作为发布门禁。

## 5. 端到端调用流程

### 5.1 Java API/HTML 请求

```text
JmComic factory
  -> OkHttpBuilder: CookieJar + UA/global headers + retry interceptor + DomainManager
  -> AbstractJmClient constructor
       -> pool A（外部或 CPU 固定池）提交 updateDomains
       -> commonPool 全域 probe/join
       -> scheduler 启动
       -> initialized=true/latch release
       -> subclass initialize (API: /setting)
  -> public API method
       -> placeholder URL -> interceptor 选 best domain
       -> 最多 retryTimes + 1 次
       -> try-with Response -> body 全量缓存
       -> API AES decode / HTML parse
       -> CachePool 或返回 record
```

证据链：factory `J/.../JmComic.java:30-55`；HTTP 组装 `J/.../OkHttpBuilder.java:36-63`；构造初始化 `J/.../AbstractJmClient.java:78-120`；domain 门控 `J/.../JmDomainManager.java:55-59,267-275`；重试 `J/.../RetryAndDomainRedirectInterceptor.java:36-110`；响应作用域 `J/.../AbstractJmClient.java:881-888` 与 `J/.../CommonResponse.java:117-149`。

这与当前 PHP 不同：PHP 已有 request-scoped `UpstreamBudget` 和 max attempts（`P/index.php:166-195,237-239`），域名 fallback/健康分数从 APCu 读取（`P/index.php:1200-1292`），无需 JVM 式“每 client 冷启动并阻塞全部调用”。Java 可注入 probe 的边界有价值，执行时序应拒绝。

### 5.2 Java 图片与下载

```text
JmPhoto/JmImage
  -> 任意 image.url + 同一 OkHttp client/cookies/headers/retry
  -> response body / gzip 全量 byte[]
  -> ServiceLoader 选 ImageProcessor
  -> AWT 或 Android：原图 + 目标图 + encoded bytes
  -> fixed <final>.tmp
  -> ATOMIC_MOVE；不支持时 REPLACE_EXISTING
```

同步下载证据 `J/.../AbstractJmClient.java:203-233,318-359`；任务下载证据 `J/.../ImageDownloadTask.java:154-210,256-303`；图片 SPI `J/.../JmImageTool.java:15-44`。当前 PHP 使用内存 decoded page cache，生产目标明确不写 decoded image 文件；Task 7 应采用 cURL write callback compressed-byte 上限、像素上限、allowlist CDN 和 decoder 注入，而不是复制 Java 落盘流程。

### 5.3 Java 任务树

```text
AlbumDownloadTask
  -> PhotoDownloadTask * N
       -> ImageDownloadTask * M
            -> observer: state / progress / finish / error
```

manager 把父子每个任务都注册（`J/.../DownloadManager.java:54-60`）；父任务通过 observer 计数聚合（`J/.../AlbumDownloadTask.java:85-140`; `J/.../PhotoDownloadTask.java:85-135`）。该结构能提供细粒度进度，但当前 PHP 的预取是请求结束回调，不需要复制完整任务树；Task 6 只采纳逐页 lease、slot、预算与结构化指标。

## 6. 并发状态机与生命周期专项审计

### 6.1 初始化状态机

Java 的实际初始化顺序是：

```text
CONSTRUCTING
  -> domainManager.initialized=false
  -> 创建/借用 internalExecutor
  -> 另建 DownloadManager executor
  -> submit(captures this)
       -> updateDomains()
       -> probeAllDomains().join()
       -> startPeriodicProbe()
       -> initialized=true + countDown
       -> subclass initialize()
  -> 构造线程继续生成 memorySafeKey
  -> CONSTRUCTED
```

这里有四个独立问题。

1. **this-escape**：异步 lambda 在对象构造完成前调用抽象方法（`J/.../AbstractJmClient.java:78-113`）。子类字段、`memorySafeKey`（`:114-120`）和调用方可见性都没有 happens-before 契约。
2. **失败不释放**：`updateDomains/probe/startPeriodicProbe` 任一未捕获异常都会跳过 `setInitialized(true)`；`getBestDomain/getDomainStates` 永久 await（`J/.../JmDomainManager.java:55-59,89-95,267-275`）。`domainProbeIntervalMs(0)` 是仓库内可稳定触发路径（`J/.../JmConfiguration.java:254-257`; `J/.../JmDomainManager.java:222-246`）。
3. **初始化语义分裂**：latch 在 subclass `initialize()` 前释放（`J/.../AbstractJmClient.java:109-113`）。调用可能已开始，而 API `/setting` 尚未完成或已经失败，因此 `initialized=true` 实际只表示“域名探活走完”，不是“client ready”。
4. **close 竞态**：若 close 在线程执行到 `startPeriodicProbe` 前调用，`shutdown()` 看见 null 后返回；初始化线程随后创建 scheduler，client 关闭后仍泄漏 daemon task（`J/.../AbstractJmClient.java:1074-1096`; `J/.../JmDomainManager.java:212-258`）。

当前 PHP 是每请求构造上下文，不应引入后台对象初始化状态机。可采纳的是显式状态快照与 injectable probe，执行方式必须保持“立即 fallback、受限后台刷新”。

### 6.2 下载任务状态机

Java 声明的主要迁移是 `PENDING -> QUEUED -> RUNNING -> terminal`，并有 `PAUSED` 与 `CANCELLING`（`J/jmcomic-api/src/main/java/io/github/jukomu/jmcomic/api/download/task/BaseDownloadTask.java:225-278`）。确定性卡死序列如下：

```text
父 RUNNING
  -> 一个子 COMPLETED
  -> cancel(): 父 CANCELLING，其余子 CANCELLED
  -> aggregateTerminalState(): 混合终态 => COMPLETED_WITH_ERRORS
  -> doAggregateTerminalState(): 只尝试 RUNNING -> COMPLETED_WITH_ERRORS
  -> 迁移失败，父永久 CANCELLING
```

聚合证据在 `J/.../BaseDownloadTask.java:293-312`；`CANCELLING` 只允许到 `CANCELLED`（`:254-255`），而 mixed 只从 RUNNING 转（`:346-352`）；Album/Photo cancel 证据分别为 `J/.../AlbumDownloadTask.java:69-77`、`J/.../PhotoDownloadTask.java:69-77`。父任务空 children 时取消也没有后续 observer 触发；PENDING/QUEUED/PAUSED 直接变 CANCELLED 时未记录 end timestamp。

此外，observer 四类广播都无异常隔离（`J/.../BaseDownloadTask.java:357-381`）。例如 `ImageDownloadTask.start()` 在 try 块外先通知 RUNNING（`J/.../ImageDownloadTask.java:53-59`）；任一 observer 抛错会让任务停在 RUNNING，manager 收不到 terminal 并保留 active entry。当前 Task 6 指标回调必须是 best-effort：逐 observer/metric sink 隔离、记录异常、不得改变 owner 释放 lease/slot 的 finally。

### 6.3 线程与资源所有权

| 资源 | 创建处 | 关闭处 | 风险 |
|---|---|---|---|
| `internalExecutor` | 外部注入或 CPU 固定池，`J/.../AbstractJmClient.java:89-98` | 只有内部池在 `close` 关闭，`:1080-1096` | 既承担初始化又承担同步下载 async；外部池饥饿会阻塞初始化。 |
| DownloadManager pool | 无论是否有外部池都另建 CPU 固定池，`:99-103` | `DownloadManager.close`, `J/.../DownloadManager.java:105-123` | 配置声称统一并发，实际任务系统绕开外部 executor。 |
| album helper pool | 固定 2 线程，`J/.../AbstractJmClient.java:770-840` | 方法 finally | 每次构造 album task 都临时开池并阻塞 await。 |
| domain/latency commonPool | `CompletableFuture.runAsync` 无 executor，`J/.../JmDomainManager.java:155-182` 与 `J/.../AbstractJmClient.java:164-198` | 全局资源，不归 client | 首次探活和 `getDomainLatency` 占用 JVM commonPool；没有整体 deadline。 |
| probe scheduler | 每 client 单线程 daemon，`J/.../JmDomainManager.java:212-246` | 非 await 的 `shutdown()` | start/check 非原子，close/start 竞态。 |
| OkHttp dispatcher/pool/cache | `OkHttpBuilder` 创建；具体 client 构造器也是 public，可传共享实例 | client close 无条件关 dispatcher/cache，`J/.../AbstractJmClient.java:1098-1106` | 工厂路径可接受；直接共享构造路径缺少 ownership 标志。 |

Java 的“外部 executor 不关闭”原则值得保留，但必须扩大为所有资源的 owner/borrowed 标志。当前 PHP 没有 JVM executor；Task 6 的等价所有权是 page lease、global slot 与 shutdown callback，所有 token 必须在最外层 `finally` token-safe 释放。

### 6.4 Domain 状态一致性

`domains` 与 `failureCounts` 是两个独立可变容器（`J/.../JmDomainManager.java:32-35`）。`updateDomains` 依次 clear/add list、clear/rebuild map（`:101-106`）；并发 `getBestDomain`、probe 或 scheduler 可观察到 domain 已存在但 count 未存在并 NPE。`getDomains()` 还直接返回内部 `CopyOnWriteArrayList`（`:108-110`），外部无需调用 manager 就能破坏对应关系。

应采纳的不是这两个容器，而是**单个 immutable snapshot**：`{generation, orderedDomains, healthByDomain}` 原子替换；APCu 的当前 PHP 实现继续使用 schema/version key、lease 与 validator。domain failure score 和后台复探可保留，但复探必须使用独立短 deadline，不能延长当前请求预算。

## 7. 安全、资源、性能与正确性问题

严重度定义：**高**表示可造成永久挂起、内网访问、数据损坏/OOM 或发布失真；**中**表示需要特定输入/并发但会产生请求失败、错误结果、资源压力或有界泄漏；**低**表示主要影响 API 清晰度或文档可信度。本表不使用未定义的“中高”。

| ID | 严重度 | 类别 | 确认证据与影响 | 修复/采纳建议 | 归属 |
|---|---|---|---|---|---|
| J-01 | 高 | 生命周期 | 构造中 this-escape 且初始化异常不 countDown（`J/.../AbstractJmClient.java:78-113`; `J/.../JmDomainManager.java:267-275`），请求可永久挂起。 | Java 未来改显式 factory/start future，异常也完成 future；PHP 不采纳该状态机。 | 未来项 |
| J-02 | 中 | 正确性 | `Map.copyOf` 后对 entry `setValue`（`J/.../AbstractJmClient.java:139-147`），all-dead fallback 下调用诊断方法 `getDomainStates()` 确定抛 `UnsupportedOperationException`；它破坏公开诊断 API，但不直接证明业务请求挂起或数据损坏。 | 在构造 mutable result 前改值，最后一次 immutable copy；作为 Task 10 静态反例测试。 | Task 10 / 未来项 |
| J-03 | 高 | 状态机 | completed + cancelled 混合使父永久 CANCELLING（第 6.2 节证据）。 | cancel 聚合应定义“cancel requested wins”或显式 mixed-cancel terminal，并保证一次 terminal/finally。 | 未来项 |
| J-04 | 高 | 并发 | observer 不隔离，异常可阻断任务/后续 observer/manager 清理（`J/.../BaseDownloadTask.java:357-381`）。 | 指标和 callback best-effort 隔离；资源释放不依赖 observer。 | Task 6 / 未来项 |
| J-05 | 高 | 延迟 | 每个 client 首次对全部 domain 用 commonPool 并 join 后开放；无整体 deadline（`J/.../JmDomainManager.java:145-200`）。 | 拒绝同步门控；当前 PHP 保持 fallback first + deferred bounded refresh。 | 拒绝；Task 10 回归 |
| J-06 | 高 | 重试 | 默认 retryTimes=5，循环 `<=` 即最多 6 attempts；POST/body、5xx、403、IO 同策略且无 call/business deadline（`J/.../JmConfiguration.java:164-166`; `J/.../RetryAndDomainRedirectInterceptor.java:40-110`）。 | 只按 failure/method/idempotency 分类；所有同步工作共享总预算；副作用 POST 默认不重放。 | 拒绝；Task 10 回归 |
| J-07 | 高（边界） | 安全 | `downloadImage(String,Path)` 和 public `JmImage.url` 进入同一 client，无 scheme/host/IP allowlist且默认跟随 redirect（`J/.../AbstractJmClient.java:203-205,312-315`; `J/.../JmImage.java:11-35`）。默认/用户全局 headers 会无条件加到外部 host（`J/.../UserAgentInterceptor.java:40-53`）；CookieJar 被复用，但 cookie 只在 domain/path 匹配时发送，不能写成“任意主机必泄露 cookie”（`J/.../OkHttpBuilder.java:36-60`）。服务端接入不可信 URL 时可 SSRF；纯本地可信 SDK 输入时风险下降。 | 当前 PHP 只能从 CDN allowlist + 受检相对 media path materialize；解析每次重定向并拒绝私网/非 HTTPS（测试白名单除外）。 | Task 7 |
| J-08 | 高（边界） | 内存 | `body.bytes`、gzip 解压、BAOS、`toByteArray`、原/目标图/编码结果同时驻留，无 compressed/pixel 上限（`J/.../CommonResponse.java:117-170`; `J/.../ImageDownloadTask.java:178-210,256-261`; `J/.../AwtImageProcessor.java:33-76`）。无界整包峰值由代码确认；OOM 取决于输入/堆大小，本轮未实际制造 OOM。 | cURL write callback 限 compressed bytes，解码前校验尺寸/像素，记录 peak，当前页安全 502。 | Task 7/10 |
| J-09 | 高 | 文件正确性 | 同 final path 使用固定 `.tmp`，存在 TOCTOU、互相 truncate/move；异常/取消残留（同步 `J/.../AbstractJmClient.java:329-357`；任务固定名构造 `:862-864`、写入 `J/.../ImageDownloadTask.java:277-303`）。 | 若未来确有文件产物：同目录唯一 tmp、fsync（按需）、不覆盖或原子 replace 的明确契约、finally 清理。当前 decoded cache 禁止落盘。 | 拒绝本轮；未来项 |
| J-10 | 中 | 缓存 | 新 entry 会拒绝单项超容量，但更新 existing key 不检查/淘汰，`currentSize` 可超过 capacity（`J/.../CachePool.java:105-125`）；无 TTL/single-flight。key 只有 class + id/folder/page（`J/.../AbstractJmClient.java:972-1023`），而 cache 属于单 client：不是跨 client 全局泄露，但同一 client 切换账号或用户态字段变化时没有 identity/epoch 隔离。 | 采纳单项拒绝；当前 PHP 用 typed/schema canonical key、TTL、validator、single-flight，并把认证/locale等身份维度显式纳入或禁止缓存。 | Task 5/7/10 |
| J-11 | 中 | 并发 | domain list/map 非原子、内部 list 可外改，scheduler start 非原子（`J/.../JmDomainManager.java:101-110,212-220`）；可产生 NPE、错选或重复 scheduler，但单凭该路径不等同永久进程故障。 | immutable snapshot + generation；后台复探单 owner。 | Task 10 / 未来项 |
| J-12 | 高 | 生命周期 | 双 CPU executor、额外 helper/commonPool/scheduler；close/init 竞态，registry 终态不删除（`J/.../AbstractJmClient.java:89-113,770-840,1074-1106`; `J/.../DownloadManager.java:28-30,125-131`）。 | 显式 owner；closed gate；终态 retention/TTL/cap；当前 Task 6 用固定 slot 而非线程式扩张。 | Task 6 / 未来项 |
| J-13 | 中 | 确定性 | 每次解析随机 CDN（`J/.../ApiParser.java:220-222,378-384`）；非 placeholder 图片失败仍重试同 host。APP_VERSION/CDN list 为进程级可重绑（`J/.../JmConstants.java:35,121-136`）。 | 相对 path 入 cache，materialize 时 stable/health candidate；配置 snapshot + epoch，不允许请求中突变。 | Task 7/10 |
| J-14 | 中 | 初始化 | `/setting` 的 `img_host` 调用 `DEFAULT_IMAGE_DOMAINS.add`（`J/.../JmApiClient.java:102-118`）。在默认 list 未被外部重绑定且前序解析成功时，非 null host 确定对 unmodifiable list 抛错（`J/.../JmConstants.java:129-136`）；由于字段是 public static 非 final，不能无条件断言所有运行态都必抛。APP_VERSION 可能已先改变，造成半更新。 | 配置解析成新的受检 immutable snapshot，原子发布；失败不半更新。 | 未来项；Task 10 反例 |
| J-15 | 中 | 图片资源 | AWT Graphics 未 dispose，ImageIO.write false 被忽略（`J/.../AwtImageProcessor.java:65-76`），可能泄漏 native resource 或返回空 bytes。 | 单 Graphics2D try/finally dispose；检查 writer/boolean；golden vectors + 输出 decode 校验。 | Task 7/10 |
| J-16 | 中 | Android 资源 | Bitmap 只在成功路径主动 recycle，异常路径不及时 recycle；GC 最终可回收，准确风险是大图/旧 Android 的峰值与回收压力，不是静态可证永久泄漏。`compress` boolean 也被忽略（`J/jmcomic-android-support/.../AndroidImageProcessor.java:34-49,74-90`）。 | Java Android 未来项；PHP 不移植。 | 未来项 |
| J-17 | 中 | 错误语义 | API not-found 被同一 catch 吞掉（`J/.../JmApiResponse.java:51-63`）；空或全非 ASCII HTML 的直接 `getHtml()` 可在 `charAt(0)` 越界（`J/.../JmHtmlResponse.java:54-63`）；DOM 多处未空检。 | validator/error taxonomy 优先，禁止把 malformed/missing 静默转空成功。 | Task 5/7/10 |
| J-18 | 中 | API 正确性 | `downloadImage(String,Path)` 构造空 filename/photo/scramble（`J/.../AbstractJmClient.java:312-315`）；非 GIF 会在 processor `Long.parseLong("")`（`J/.../AwtImageProcessor.java:24-30`）。 | 删除该便利 API 或定义 raw-download 不解码路径；不作为 PHP 能力。 | 拒绝 / 未来项 |
| J-19 | 中 | 结果语义 | `DownloadResult` 不复制入参且 `isAllSuccess` 只看 failed map（`J/.../DownloadResult.java:20-22,45-46`）；取消可能被报告成功。 | immutable copy + completed/cancelled/failed 明细与 invariant。 | 未来项 |
| J-20 | 高 | 交付 | 0 tests；sample 排除且有错误 import；release 不构建 artifact（`J/pom.xml:11-17`; `J/jmcomic-sample/.../DownloaderSample.java:3-4`; `J/.github/workflows/release.yml:27-40`）。 | sample compile 纳入 reactor/独立 job；fixture tests；release 必须 build/checksum/upload。 | Java 未来项；本项目 Task 10 只把 Java 当参考 fixture |

## 8. API 与模型兼容差异

### 8.1 Chapter `images`：字符串与对象

Java parser 对两种容器 shape 的处理非常明确（协议期望 filename string；实现对 primitive 的 `getAsString()` 还会宽松接收 number/boolean，这一点不应移植）：

```json
["00001.jpg", {"image":"00002.jpg"}]
```

primitive 取自身，object 取 `image` primitive，其他形状抛 `ParseResponseException`（`J/jmcomic-core/src/main/java/io/github/jukomu/jmcomic/core/parser/ApiParser.java:440-468`）。当前 PHP 的 `PayloadNormalizer::listArray()` 只保证外层数组（`P/index.php:1172-1176`），`JmChapter::fromApiResponse()` 对每项调用只接受 PHP scalar 的 `scalarString()`；JSON object 解为关联数组后得到 `''` 并 `continue`（`P/index.php:2002-2033`）。PHP 8.3 复现确认关联数组 `is_scalar=false` 且进入 skip。因此：

- 全对象数组会产生 `page_count=0`，看起来像合法空章节；
- 混合数组会静默删页并重新编号，原第 3 页可能变输出第 2 页；
- 当前 fixture 只生成字符串（`P/tests/fixtures/upstream-router.php:223-228`），所以合同测试无法发现；
- Kotlin 只消费 PHP 规范化后的 `List<JmImageDto>`（`K/src/zh/jmapi/src/eu/kanade/tachiyomi/extension/zh/jmapi/Dto.kt:94-108`），修复应在 PHP upstream adapter，不应让 Kotlin 同时理解两种上游 shape。

Task 7 的强制要求：

1. fixture 增加 `chapter-images-strings`、`chapter-images-objects`、`chapter-images-mixed`、`chapter-images-malformed`、`chapter-images-empty` 五类场景；合法 object 覆盖非空 string `image`，malformed object 至少覆盖缺 key、空 string、null、number/boolean、array/nested object。Java 的 primitive 强制转字符串不是目标合同。
2. 独立 `normalizeChapterImages(mixed): list<string>`：只接受非空 string 或含非空 string `image` 的 object/assoc array；trim 后做 filename/path 校验；malformed 200 必须 502 或显式 validation failure，不能静默部分成功，更不能把“全对象被丢弃”伪装成合法零页章节。
3. validator 断言 `id/photoId`、images shape 和规范化后页数；合法显式空数组与 malformed 区分。
4. `chapter:v2`、`manifest:v2` 只缓存规范化相对 path；旧 v1 不再读取。
5. Task 10 断言 string/object 两种输入生成完全相同的对外 `JmChapterEnvelope`、Kotlin 页面数量和 decode segment；混合顺序不变、不得丢页。

### 8.2 `/comic_read?id=...`：一次返回 images + scramble_id

Java 证据表明 `/comic_read` 一次响应至少被设计为包含 `id/name/series_id/scramble_id/total_page/images/series`，parser 将它组装为带 images 的 `JmAlbum`（`J/.../ApiParser.java:174-263`），客户端一次 GET 后直接 parse（`J/.../JmApiClient.java:199-207`）。但它没有替代 Java `getPhoto` 的两请求实现（`J/.../JmApiClient.java:211-255`），而且返回类型借用 `JmAlbum`、许多字段填空/0，缺失 `scramble_id` 还会默认成 220980（`J/.../ApiParser.java:194-203,237-263`），说明协议与模型仍不稳定。当前 PHP 确实先取 template scramble、再取 chapter（`P/index.php:2758-2782,2914-2926`），这个 fallback 必须保留。

决策为**待 A/B 验证**，方案如下。

| 阶段 | 要求 | 通过条件 |
|---|---|---|
| Task 7 fixture | fixture 同时实现 `/comic_read` 与现有 `/chapter`、`/chapter_view_template`；为 valid、missing scramble、object images、bad JSON、business error、404/405、timeout 提供确定序列。 | adapter 能规范化为同一 `{photo_id,scramble_id,page_count,relative_images}`；所有失败回到现有路径且不污染 cache。 |
| Task 7 validator | `/comic_read` 必须有受检 photo id、非空数值 scramble、顺序保持的 images；`total_page` 与 images 不一致按明确定义处理，不能盲信。 | malformed/partial 不缓存，不生成空成功。 |
| Task 10 离线 A/B | 对同 fixture 输入比较两个路径的 normalized payload、segment golden vector、尝试次数和 deadline。 | 字段完全等价；fallback 场景尝试数/时间严格受总预算控制。 |
| Task 10 真实 A/B | 在不改变默认生产路径前，用受控测试脚本覆盖旧/新 photo id、单章/多章、不同图片 shape；记录成功率、p50/p95、上游 attempts、差异样本（不记录敏感 body）。 | 样本足够且 0 未解释字段差异后才允许 feature flag canary；否则保持 fallback。 |

生产边界：本轮只做 A/B 能力，不直接替换。先把它作为 test-only/disabled-by-default strategy；启用后优先 `/comic_read`，只有 validation/endpoint 支持类失败且预算剩余才执行旧两请求；业务 4xx/鉴权错误不得再 fallback 放大流量。cache key 必须包含 strategy/schema，避免两种 payload 串用。现有 `/chapter + /chapter_view_template` 始终保留，直到真实 A/B 和 Suwayomi 回归均通过。

### 8.3 其他模型差异

- Java `JmAlbum` 同时承担 album 与 comic-read partial view（`J/.../JmAlbum.java:11-113`），当前 PHP 分开 `JmAlbum/JmChapter` 更清晰，应保留。
- Java许多 list/map 只是浅 record；当前 PHP cache 应只保存规范化 array，不缓存 response/body/client object。Task 5 计划本身也要求 album/weekly 缓存规范化 payload（`Plan/:446-480`）。
- Java异常通过类型表达 network/parse/not-found，但实现吞掉或泄漏 NPE；当前 `ApiFailure::shouldRetry()` 已只允许 network/retryable HTTP（`P/index.php:1137-1140`），应作为主契约。
- Kotlin `pageListParse()` 当前取第一个 chapter 而不是 requested chapter（`K/.../JmApi.kt:174-188`）。Task 8 必须按 photoId 查找；这与 object-image 修复互补但不是同一层问题。

## 9. 与当前 PHP/Kotlin 设计的逐项对照

| 主题 | Java 参考 | 当前 PHP/Kotlin | 审计结论 |
|---|---|---|---|
| 总预算 | 无 call/business 总 deadline，最多 6 retry | `UpstreamBudget` 统一时间/attempt（`P/index.php:166-195`） | 保留 PHP；Java 作为反例。 |
| failure taxonomy | 5xx/403/IO 同域惩罚并重试 | `ApiFailure` 区分 hard/retryable（`P/index.php:1137-1140,1424-1436`） | 保留 PHP，Task 10 扩充 fixture。 |
| domain | list + count；同步全探活 | PHP APCu `DomainHealth` failure streak/EWMA/cooldown（`P/index.php:1200-1292`） | 保留 score，增加受限后台复探与原子 snapshot 断言。 |
| cache key | `Class + id` typed key | list source 已用 `<class>:v1:sha256(canonical)`（`P/index.php:2356-2374,2439-2448`） | PHP 更适合；Task 5 扩到 album/week defaults。 |
| cache policy | LFU capacity，无 TTL/single-flight | APCu TTL/waterline/page single-flight；list cache-through validator | 采纳“单项过大跳过”，不采纳 Java LFU。 |
| chapter image shape | string/object 都接受 | PHP 对 object 静默丢失；fixture 仅 string | Task 7 必修。 |
| chapter upstream | `/comic_read` 可一次取 images+scramble；`getPhoto`仍两请求 | PHP固定 `/chapter` + template | 待 A/B，保留 fallback。 |
| CDN | parser 随机完整 URL，进程 list 可变 | PHP当前也在 chapter/cover `array_rand`（`P/index.php:2018-2028,3633-3641`） | Task 7 改相对 path、稳定/健康 materialize。 |
| SSRF | public 任意 URL + shared client/cookies/headers | PHP生产 `imageRequestUrls` 只要求 HTTPS + `/media/photos/`，仍未限制 host（`P/index.php:1729-1745`） | Java风险在 PHP 仍部分存在；Task 7 必须 host allowlist。 |
| compressed body | 整包读，无上限 | cURL write callback仍直接拼接 `$bodyBuffer`（`P/index.php:373-406`） | Task 7 在接收阶段强制上限。 |
| image processor | ServiceLoader byte[] SPI | 静态 GD decoder | 改造为显式注入的受限 decoder/fixture decoder，不使用全局首 provider。 |
| local file | 固定 tmp -> final | 生产不落 decoded 文件 | 拒绝把 Java文件缓存引入本轮。 |
| prefetch | 本地任务树 | shutdown callback + APCu cache/lock | Task 6 用 page lease/global slot/budget，不移植任务树。 |
| extension | 无 Suwayomi 层 | Kotlin DTO + URL/setting/prefetch | Task 8 仍按现有计划修 requested chapter、base path、中文化和双向 prefetch。 |

## 10. 完整采纳矩阵

“归属”严格落在当前计划 Task 5/6/7/8/10 或未来项；“拒绝”也说明与现有目标契约的冲突。

| 候选 | 决策 | 额外边界/拒绝原因 | 归属 |
|---|---|---|---|
| typed/schema cache key | **采纳** | 用字符串 class + schema + canonical fields hash；不能用 JVM `Class<?>`；key 必须含 endpoint/identity/strategy。 | Task 5、7、10 |
| 单项超容量不缓存 | **采纳** | 检查真实 bytes；更新已有 key 同样检查；skip 不得影响当前响应。 | Task 7、10 |
| TTL + single-flight + 身份维度 | **改造后采纳** | Java没有；结合 PHP APCu token lease。认证数据默认不共享缓存，除非 key 明确含安全身份维度。 | Task 5、10 |
| domain failure score + 后台复探 | **改造后采纳** | PHP 已有 score；复探必须短 deadline、单 owner、失败负缓存、不可阻塞首请求。 | Task 10（保留/验证）；未来增强 |
| response body 作用域关闭 | **采纳** | Java try-with 读取后关闭是好模式；PHP transport/fixture 也必须在异常路径释放 handle/body，不把 body object 放 cache。 | Task 7、10 |
| 唯一 tmp -> final 原子落盘 | **拒绝本轮，未来改造后采纳** | 当前契约明确 APCu-only decoded cache、无 `/app/cache`；只有未来导出/下载产物才使用同目录唯一 tmp + 原子提交。 | 未来项 |
| 图片处理 SPI | **改造后采纳** | 显式依赖注入；输入含限制/预算，输出含 bytes/mime/codec/metrics；生产 GD 与 fixture fake 分开。拒绝 ServiceLoader 首个 provider/全局静态状态。 | Task 7、10 |
| scramble golden vectors | **采纳** | 覆盖 220980/268850/421926 阈值前后、带扩展/query 文件名、PHP 与 Java算法结果；输出图再 decode 校验。 | Task 7、10 |
| injectable probe | **改造后采纳** | 不只 boolean；返回 failure kind/status/latency，使用同一 request budget 或更短后台 budget。 | Task 10 fixture；未来增强 |
| executor 所有权 | **改造后采纳原则** | PHP等价物是 lease/slot/callback owner；owner/borrowed 资源只能由 owner release。Java双池不移植。 | Task 6、10；Java未来项 |
| 进度指标 | **采纳** | 指标 sink 异常隔离；记录 scheduled/attempted/hit/stored/bytes/wall/skip reason，不以 observer 驱动状态。 | Task 6、10 |
| chapter string/object 兼容 | **采纳** | 先 validator 再规范化；malformed 不得静默丢页；cache schema v2。 | Task 7、10 |
| `/comic_read` 合并请求 | **待 A/B 验证** | Java 自己仍保留两请求；需 fixture、真实多样本、strategy key、预算内 fallback、feature flag。本轮只记录实验合同，不实现默认生产适配器。 | Task 10 设计/验证；无充分证据则保持未来项 |
| immutable domain snapshot | **改造后采纳** | 单对象原子换代，不暴露内部可变 list/map；APCu payload validator + generation。 | Task 10 回归；未来增强 |
| 首次全域同步探活 | **拒绝** | 与当前“立即 fallback、响应后刷新”目标相反，会把配置源/网络故障放大到所有请求。 | Task 10 证明不存在回归 |
| commonPool 全域 future | **拒绝** | PHP无 JVM pool；即便 Java未来也应 bounded executor + overall deadline。 | 未来项 |
| POST/body 无差别重试 | **拒绝** | 评论、收藏、购买、签到等可能重复副作用；违反当前 failure matrix。 | Task 10 fault injection |
| 403 惩罚 domain 并重试 | **拒绝** | 认证/授权错误不是 domain 健康；与现有目标契约冲突。 | Task 10 |
| 任意外部 URL 共用 client/cookie/header | **拒绝** | SSRF、无条件全局 header 泄露、匹配域/path 的 cookie 泄露与 redirect 绕过；不能声称 cookie 会发送给每个任意 host。当前 PHP 必须只允许受检 CDN allowlist。 | Task 7、10 |
| 随机 CDN 固化进 cache | **拒绝** | 降低缓存确定性且坏域不可切换；改相对 path + stable/health candidate。 | Task 7、10 |
| 进程可变 APP_VERSION/CDN list | **拒绝** | 多请求/多 client 相互污染，且 Java实现存在不可变 list add 异常；改 immutable config snapshot/epoch。 | Task 7、10；Java未来项 |
| Java LFU + JSON size estimate | **拒绝** | APCu 已提供总容量/TTL；JSON size 不是堆大小，失败按 1 byte 更危险。 | 无；Task 10 仅验证 PHP waterline |
| Java父子下载任务树 | **拒绝** | 当前产品是 HTTP reader API，不是本地下载 SDK；引入会扩大线程/状态/磁盘面。 | 无；Java未来项 |
| AWT/Android具体处理器 | **拒绝** | PHP/GD 与 Kotlin宿主不使用 AWT/Bitmap；仅采纳 SPI/资源边界测试。Android 异常路径风险表述为未及时 recycle/峰值压力，不夸大为永久泄漏。 | Task 7、10；Java未来项 |
| Java异常类层级 | **拒绝直接移植** | 当前 `ApiFailure` 同时编码 retry/domain/cache 语义更适合；Java层级仍吞 not-found/NPE。 | Task 10 |
| Java浅 record 作为 cache payload | **拒绝** | List/Map 可外改，缺 schema/null 约束；缓存只存规范化 array。 | Task 5、7 |
| Java `getComicRead` partial `JmAlbum` 模型 | **拒绝模型，待 A/B 端点** | 当前 `JmAlbum/JmChapter` 分离更符合对外 JSON；只评估端点，不采用复用模型。 | Task 7、10 |

## 11. 纳入本轮计划的具体变更与测试

### Task 5：album 与 weekly defaults cache

对应计划入口 `Plan/:446-480`。Java typed key 和 cache 反例要求本 Task 增加：

- album key：`album:v1:<sha256({album_id, schema, auth_scope_if_any})>`；weekly defaults key：`week-defaults:v1:<sha256({endpoint,schema})>`。
- 只缓存规范化 array；每个 class 独立 validator；ResponseBody/JmAlbum/PHP client object 禁止入 cache。
- TTL=0 真正 bypass；fresh/stale physical TTL 与 schema 一致；失败、malformed、部分结果不缓存。
- owner 写入前 recheck，loser 有界等待，总是受 `UpstreamBudget` 限制；token-safe compare-delete。
- 测试：同 ID 10 并发严格一次、不同 ID 隔离、身份维度不串、malformed 不缓存、TTL=0、fresh/stale 边界、producer 异常后 lease 释放。

### Task 6：预取 lease、slot、预算与指标

对应 `Plan/:481-540`。从 Java进度/所有权审计补充：

- 指标固定字段：`scheduled, attempted, cache_hits, stored, bytes, wall_ms, skip_reason`；回调/日志异常不能改变 finally。
- page lease 与 global slot 的权威 owner 是从 schedule 持有到 callback `finally` 的固定 256-shard 进程级 flock；APCu token/TTL 仅作可刷新镜像。镜像 expunge、过期或 foreign token 不能产生第二 owner，释放匹配镜像后按 shard refcount 关闭 handle。
- close/shutdown 等价竞态测试：schedule 后 callback 前、callback 中异常、预算耗尽、APCu 不可用/整体 expunge、owner 超时、同批 shard 碰撞与 handle close；没有路径可永久占 slot，运行时不 unlink 零字节 shard 文件。
- 当前页不受低水位/后台预算失败影响；`prefetch=0` 在任何 schedule/lease 前返回。
- 10 并发重叠窗口断言每候选 owner <=1、active <=配置、上游调用有硬上限，同时记录利用率/浪费率。

### Task 7：CDN、图片边界、兼容 parser 与实验 adapter

对应 `Plan/:541-602`。本审计新增的硬要求：

- 实现第 8.1 节五类 chapter images fixture、validator、兼容 parser；必须显式覆盖 string、`{"image": string}` 与 malformed object，非法对象不静默丢弃或静默变成零页成功。
- `chapter:v2/manifest:v2` 保存受检相对 media path；filename 拒绝控制符、`/`、`\`、`..`、空值；photo id 必须验证。
- 生产 image URL 只能由配置 allowlist materialize；当前仅检查 HTTPS/path 的 `imageRequestUrls()` 必须收紧 host，并验证 redirect 每一跳。
- cURL write callback 在拼接前检查累计 compressed bytes；Content-Length 只做早拒绝；GD 前检查 width/height/pixels；所有异常返回安全 502 并释放资源。
- 显式注入 image decoder；返回 `bytes/mime/codec/input_bytes/pixels/decode_ms/encode_ms/peak_memory`；检查 encode boolean 和输出可重新 decode。
- 增加 scramble golden vectors；字符串/object images 必须生成相同 segments。
- 记录 `/comic_read` 的 test-only fixture/adapter 合同与 strategy-specific cache key 设计；本轮 Task 7 不新增默认生产适配器。Task 10 或未来实验实现也只能用于 A/B，现有 `/chapter + /chapter_view_template` fallback 必须保留且受同一总预算。
- 不创建 decoded file cache、不新增 `/app/cache`；唯一 tmp 规则只写入未来路线。

### Task 8：扩展合同

对应 `Plan/:603-716`。Java reference 不改变现有 Kotlin 产品契约，但增加两项联动断言：

- `pageListParse()` 必须按请求 chapter id 选择响应项，不能取 first（当前问题 `K/.../JmApi.kt:174-188`）。
- PHP object-image 修复后，Kotlin仍只接收规范化 `JmImageDto`；合同同时覆盖完整 image 列表和 server fallback page URL，页数/顺序一致。
- Base URL、中文化、双向 prefetch、ID 1-20 位和 versionCode 仍按原 Task 8，不因 Java enum/model 改映射。

### Task 10：完整验证和 A/B 决策

对应 `Plan/:773-861`。必须新增或明确执行：

1. chapter images 五类 fixture；string/object normalized output deep-equal；malformed 200 不缓存。
2. `/comic_read` 与旧两请求离线 A/B；真实 A/B 单独报告样本、字段差异、成功率、attempts、p50/p95，不能以单 ID 宣布替换。
3. scramble 阈值 golden vectors + 输出图片 decode 验证。
4. SSRF：任意公网 host、loopback、RFC1918、IPv6 local、userinfo、redirect-to-private、非 allowlist HTTPS 全拒绝；test-mode 仅显式 allowlist 通过。
5. compressed bytes、chunked 超限、伪造 Content-Length、pixel bomb、encoder false/exception；当前页安全失败且 APCu/lease/slot 无残留。
6. cache identity/schema/TTL/single-flight；domain score/reprobe 不延长 request deadline；metric sink 抛异常不改变请求结果。
7. 最终 bug audit 继续检查 cache key 污染、token 释放、错误缓存、随机 CDN、HEAD 测量污染和版本漂移。

## 12. 未来路线

1. **Java reference 自身修复线**：显式 async factory、ready future、immutable domain snapshot、bounded probe executor、资源 ownership、closed gate、registry retention cap、observer 隔离、cancel terminal invariant、unique tmp、stream/pixel limits、Android/AWT finally、sample compile 和 release artifact。它不属于当前 PHP/Kotlin 代码交付。
2. **`/comic_read` 可选生产线**：只有 Task 10 A/B 满足等价性和可靠性门槛后，才增加 disabled-by-default flag/canary；否则保留为 fixture/研究能力。
3. **未来文件导出线**：若产品以后增加 ZIP/PDF/离线下载，才引入唯一 tmp -> fsync/atomic final、目录沙箱、配额和清理器；不得借本审计恢复 decoded image file cache。
4. **统一 schema registry**：当 album/list/chapter/manifest cache class 继续增加时，把 canonical key、validator、TTL 和 metrics 注册成显式 class policy；仍保持 APCu，无需引入 Java LFU。
5. **持续协议语料**：保存脱敏的 string/object images、comic_read、scramble 阈值、bad encrypted、business error fixture，真实上游只做最终 smoke，不作唯一证据。

## 附录 A：审计覆盖统计与运行条件

### A.1 文件闭合统计

| 范围 | 文件数 | 生产 Java | 测试 Java | 审计状态 |
|---|---:|---:|---:|---|
| `jmcomic-api` | 86（含 POM） | 85 | 0 | 全部逐文件分类；89 个 public 类型，接口/模型/异常含 nested 类型完整列出 |
| `jmcomic-core` | 35（33 Java + POM + resource） | 33 | 0 | 全部逐文件分析 |
| `jmcomic-android-support` | 3（Java + POM + provider） | 1 | 0 | 全部逐行分析 |
| `jmcomic-sample` | 5（4 Java + POM） | 4 | 0 | 全部逐行分析；确认编译错误 |
| 根/CI/README/docs/metadata | 38 | 0 | 0 | 根 POM、2 workflow、2 README、30 docs、3 metadata 全部覆盖 |
| **合计** | **167** | **123** | **0** | **闭合** |

说明：上表“文件数”按每个文件只计一次；根 POM归最后一组，因此模块 POM 共 4、全仓 POM 共 5。生产 Java 模块和为 `85 + 33 + 1 + 4 = 123`，非 Java为 `167 - 123 = 44`。构建期 `target` 不属于该源码清单；未排除时会额外出现 573 个生成文件。

### A.2 Maven/测试运行条件

项目与 CI 声明的正常命令是：

```powershell
mvn -B -ntp "-Dgpg.skip=true" clean verify
```

引号只用于 Windows PowerShell 5.1 的 native-argument 传递；CI/bash 中原文 `-Dgpg.skip=true` 等价。项目要求 JDK 17，CI 也这样配置（`J/.github/workflows/ci.yml:17-35`）。本轮使用便携工具实际执行，不再把系统 PATH 状态误写为机器能力：

| 工具 | fresh 实际状态 | 影响 |
|---|---|---|
| JDK | Temurin `17.0.19+10`，`javac 17.0.19`，位于 `D:\jm\.tools\temurin-17.0.19+10\jdk-17.0.19+10` | 满足 `J/pom.xml:46-60`；系统默认 Java 8 不参与 Maven 命令。 |
| Maven | Apache Maven `3.9.11`，位于 `D:\jm\.tools\apache-maven-3.9.11` | 根 reactor 和 sample 均已实际运行。 |
| PHP | `8.3.32` CLI | `P/index.php` lint 与 object-image 分支复现已运行。 |
| Git | MinGit `2.55.0.windows.2` | 工具可用，但 `J/` 没有 `.git`，所以无 commit/status 可记录。 |
| Android/Gradle | Android SDK 已安装；便携 Gradle `9.6.1` 可定位 | 仓库没有 Android Gradle project/instrumentation 配置；只由 Maven 对 provided Android 4.1.1.4 stub 编译，未运行 APK/D8/Robolectric/device。 |
| Docker | 未找到 | 未运行容器；本审计不依赖 Docker 得出 Java 静态结论。 |

根 reactor 不含 sample（`J/pom.xml:11-17`）。sample 的独立验证命令是：

```powershell
mvn -B -ntp "-Dgpg.skip=true" -f .\jmcomic-sample\pom.xml clean verify
```

为排除 sample 首次从 Maven Central 解析已发布 `1.1.7` 的差异，本轮又把当前 reactor 仅 `install` 到本地仓库，再重复 sample 命令；两次都到达 javac 并以同样 11 个错误失败。`install` 不是 `deploy/release`，本轮未运行任何外部发布动作。Android 普通 Maven 编译也不证明设备可用。完整退出码见附录 F；没有运行的 Android runtime、Docker、真实 JM 上游验证均不声称通过。

## 附录 B：123 个生产 Java 文件完整清单

以下以模块 `src/main/java/` 为共同前缀；每个 basename 均由 `rg --files` 得到，数量与 A.1 对账。

### B.1 `jmcomic-api`（85）

**`client/`（4）**

`JmClient.java`, `JmCreatorClient.java`, `JmDownloadClient.java`, `JmNovelClient.java`。

**`download/`（8）**

`DownloadProgress.java`, `DownloadRequest.java`, `DownloadResult.java`, `IDownloadManager.java`, `enums/TaskState.java`, `enums/TaskType.java`, `task/BaseDownloadTask.java`, `task/TaskObserver.java`。

**`enums/`（10）**

`Category.java`, `ClientType.java`, `CommentStatus.java`, `FavoriteFolderType.java`, `ForumMode.java`, `OrderBy.java`, `SearchMainTag.java`, `SubCategory.java`, `TimeOption.java`, `VoteType.java`。

**`exception/`（7）**

- `JmComicException.java`：unchecked 根异常。
- `NetworkException.java`：IO/network 包装。
- `ParseResponseException.java`：JSON/HTML/图片 shape 解析。
- `ResponseException.java`：HTTP/业务响应，附可选 errorCode。
- `ResourceNotFoundException.java`：带 resourceId 的 not-found。
- `AlbumNotFoundException.java`、`PhotoNotFoundException.java`：资源特化。

**`strategy/`（3）**

`IAlbumPathGenerator.java`, `IDownloadPathGenerator.java`, `IPhotoPathGenerator.java`。

**`model/`（53 个源文件；50 个顶层 record + 3 query class；57 个 public 类型）**

- 查询：`FavoriteQuery.java`, `ForumQuery.java`, `SearchQuery.java`。
- 漫画/搜索/分类：`JmAlbum.java`, `JmAlbumDownloadInfo.java`, `JmAlbumMeta.java`, `JmCategoryBlock.java`, `JmCategoryList.java`, `JmCategoryListItem.java`, `JmCategoryMeta.java`, `JmImage.java`, `JmPhoto.java`, `JmPhotoMeta.java`, `JmSearchPage.java`, `JmSubCategoryItem.java`。
- 评论/投票：`JmComment.java`, `JmCommentExpInfo.java`, `JmCommentList.java`, `JmVoteResult.java`。
- 创作者：`JmCreatorAuthorWorksPage.java`, `JmCreatorMeta.java`, `JmCreatorPage.java`, `JmCreatorRelatedWork.java`, `JmCreatorSponsor.java`, `JmCreatorWorkDetail.java`, `JmCreatorWorkInfo.java`, `JmCreatorWorkMeta.java`, `JmCreatorWorkPage.java`。
- 用户/签到：`JmDailyCheckInRecordItem.java`, `JmDailyCheckInStatus.java`, `JmUserInfo.java`, `JmUserProfile.java`。
- 收藏：`JmFavoriteFolderResult.java`, `JmFavoritePage.java`, `JmTagFavorite.java`。
- 通知/追踪/任务：`JmNotification.java`, `JmNotificationPage.java`, `JmTrackingItem.java`, `JmTrackingPage.java`, `JmTaskItem.java`, `JmTaskList.java`。
- 小说：`JmNovelChapter.java`, `JmNovelChapterMeta.java`, `JmNovelComment.java`, `JmNovelDetail.java`, `JmNovelFavoritesPage.java`, `JmNovelMeta.java`, `JmNovelPage.java`, `JmRelatedNovel.java`。
- 每周必看：`JmWeeklyPicksCategory.java`, `JmWeeklyPicksDetail.java`, `JmWeeklyPicksList.java`, `JmWeeklyPicksType.java`。

嵌套 public 类型也计入公开面：`JmCreatorWorkDetail.Image` record（`J/.../JmCreatorWorkDetail.java:97`），以及 `FavoriteQuery.Builder`（`J/.../FavoriteQuery.java:48`）、`ForumQuery.Builder`（`J/.../ForumQuery.java:119`）、`SearchQuery.Builder`（`J/.../SearchQuery.java:102`）。因此 model package 实际为 51 个 public record + 3 query + 3 Builder = 57 public 类型；“50 record”只表示顶层 record 源文件。

模型审计共同结论：51 个 public model record 覆盖分页、详情、元数据和状态，但没有统一 compact constructor；容器字段通常没有 `List.copyOf/Map.copyOf`，因此不是深不可变。三个 query class 采用 Builder，`FavoriteQuery` 对负 folder/page 做夹取（`J/.../FavoriteQuery.java:13-23`），`ForumQuery` 把 entity type 编码成字符串参数（`J/.../ForumQuery.java:119-166`），`SearchQuery` 负责 main tag/order/time/category 等组合。它们适合做字段命名参考，不作为 PHP validator 的唯一 schema。

### B.2 `jmcomic-core`（33）

- 根（1）：`JmComic.java`。
- `cache/`（3）：`CacheKey.java`, `CacheObjectSizer.java`, `CachePool.java`。
- `client/`（3）：`AbstractJmClient.java`, `impl/JmApiClient.java`, `impl/JmHtmlClient.java`。
- `config/`（1）：`JmConfiguration.java`。
- `constant/`（1）：`JmConstants.java`。
- `crypto/`（2）：`JmCryptoTool.java`, `JmImageTool.java`。
- `download/`（4）：`DownloadManager.java`, `task/AlbumDownloadTask.java`, `task/ImageDownloadTask.java`, `task/PhotoDownloadTask.java`。
- `image/`（2）：`AwtImageProcessor.java`, `spi/ImageProcessor.java`。
- `net/`（9）：`OkHttpBuilder.java`, `interceptor/RetryAndDomainRedirectInterceptor.java`, `interceptor/UserAgentInterceptor.java`, `model/CommonResponse.java`, `model/JmApiResponse.java`, `model/JmHtmlResponse.java`, `model/JmResponse.java`, `provider/DomainProbe.java`, `provider/JmDomainManager.java`。
- `parser/`（3）：`ApiParser.java`, `HtmlParser.java`, `ParseHelper.java`。
- `strategy/impl/`（2）：`DefaultAlbumPathGenerator.java`, `DefaultPhotoPathGenerator.java`。
- `util/`（2）：`FileUtils.java`, `JsonUtils.java`。

上述 33 个文件均在第 4.2 节逐文件给出职责与结论；不存在只列清单未归类的 core 源文件。

core 另有两个 public nested 类型：`JmConfiguration.Builder`（`J/.../JmConfiguration.java:158`）与 `OkHttpBuilder.HttpClientContext`（`J/.../OkHttpBuilder.java:69`）；它们已计入 4.1.1 的全仓公开面，而不增加源文件数。

### B.3 Android（1）与 sample（4）

- Android：`jmcomic-android-support/src/main/java/io/github/jukomu/jmcomic/android/support/AndroidImageProcessor.java`。
- Sample：`jmcomic-sample/src/main/java/io/github/jukomu/jmcomic/sample/config/ConfigUsage.java`、`data/FetchDataSample.java`、`downloader/DownloaderSample.java`、`strategy/PathGeneratorSample.java`。

四个 sample 和 Android 文件均在第 4.3、4.4 节逐文件分析。

## 附录 C：44 个非 Java 文件完整清单

### C.1 构建、CI、README 与元数据（14）

- POM（5）：`pom.xml`, `jmcomic-api/pom.xml`, `jmcomic-core/pom.xml`, `jmcomic-android-support/pom.xml`, `jmcomic-sample/pom.xml`。
- workflow（2）：`.github/workflows/ci.yml`, `.github/workflows/release.yml`。
- README（2）：`README.md`, `README_en.md`。
- 运行时 resource（2）：`jmcomic-core/src/main/resources/jmcomic-config-example.properties`, `jmcomic-android-support/src/main/resources/META-INF/services/io.github.jukomu.jmcomic.core.image.spi.ImageProcessor`。
- 元数据（3）：`.gitignore`, `.readthedocs.yaml`, `LICENSE`。

### C.2 docs（30）

- 配置（2）：`docs/mkdocs.yml`, `docs/requirements.txt`。
- 首页/全局（4）：`docs/sources/index.md`, `docs/sources/configuration.md`, `docs/sources/android.md`, `docs/sources/changelog.md`。
- quickstart（3）：`installation.md`, `first-download.md`, `fetch-data.md`。
- API（7）：`enums.md`, `exceptions.md`, `jmclient.md`, `jmcreatorclient.md`, `jmdownloadclient.md`, `jmnovelclient.md`, `models.md`。
- features（9）：`comic.md`, `comment.md`, `creator.md`, `discovery.md`, `download.md`, `favorite.md`, `notification.md`, `novel.md`, `user-checkin.md`。
- advanced（5）：`custom-executor.md`, `custom-path.md`, `download-task-system.md`, `modular-integration.md`, `progress-callback.md`。

文档逐组结论：quickstart 正确强调 client 必须 close，但图片 jpg 与 domain URL 示例不准确；API 文档列出主要 typed 模型，却对 raw Map/List、shallow record 和 HTML unsupported 能力提示不足；features 基本与接口同名；advanced 正确说明外部 executor 不由 client 关闭（`J/docs/sources/advanced/custom-executor.md:20-24`），但没有披露 DownloadManager 仍另建内部池；task 文档声明严格状态机（`J/docs/sources/advanced/download-task-system.md:83-106`），实际存在 mixed-cancel 卡死；Android 文档缺真实构建/资源边界；changelog 落后当前 1.1.7。

## 附录 D：指定候选逐条独立核实闭环

| 指定候选 | 结论 | 独立证据 |
|---|---|---|
| 初始化异常不释放 latch | 确认 | `J/.../AbstractJmClient.java:106-113`; `J/.../JmDomainManager.java:267-275`；interval=0 是稳定触发链。 |
| 构造期间 this-escape | 确认 | 基类构造器 lambda 捕获 `this` 并调抽象方法，`J/.../AbstractJmClient.java:78-113`。 |
| `Map.copyOf` entry `setValue` | 确认，确定性异常 | `J/.../AbstractJmClient.java:139-147`。注意 BaseDownloadTask 的 Map.copyOf 快照没有此问题。 |
| completed + cancelled 父任务停 CANCELLING | 确认 | `J/.../BaseDownloadTask.java:293-352`; Album/Photo cancel `:69-77`。 |
| commonPool 全域探活/延迟查询且无总 deadline | 确认 | 首次探活 `J/.../JmDomainManager.java:145-200`，公开延迟查询 `J/.../AbstractJmClient.java:164-198`；单 probe 有 connect/read timeout，但 allOf 没 overall deadline/专属 executor。 |
| 图片整包内存/OOM | 无界峰值确认；OOM 为边界风险 | `J/.../CommonResponse.java:117-170`; `J/.../ImageDownloadTask.java:178-210,256-261`; AWT/Android 双图。本轮未制造 OOM。 |
| 固定 `.tmp` 并发竞争 | 确认 | 同步 `J/.../AbstractJmClient.java:329-357`；任务固定名构造 `:862-864` 与写入 `J/.../ImageDownloadTask.java:277-303`。 |
| cache 容量可突破、无 TTL/single-flight/身份 | 确认并收紧范围 | existing update 漏检 `J/.../CachePool.java:105-112`；类本身仅 get/put/remove/clear。cache 是 per-client，不是跨 client 全局泄露；同一 client 账号/用户态变化仍无 identity/epoch。 |
| domain list/map 非原子 | 确认 | `J/.../JmDomainManager.java:32-35,101-110`。 |
| 双 CPU executor、scheduler/close 竞态、registry 增长 | 确认 | `J/.../AbstractJmClient.java:89-113,1074-1106`; `J/.../DownloadManager.java:28-30,125-131`。 |
| observer 异常未隔离 | 确认 | `J/.../BaseDownloadTask.java:357-381`。 |
| POST/body 无差别重试最多 6 次、无业务总 deadline | 确认 | `J/.../RetryAndDomainRedirectInterceptor.java:36-110`; 默认 retry=5 `J/.../JmConfiguration.java:164-166`。 |
| 任意外部 URL 共用 client 导致 SSRF/header 泄露 | 边界确认 | public URL 路径 `J/.../AbstractJmClient.java:203-205,312-315` + shared builder `J/.../OkHttpBuilder.java:36-60`；全局 header 无条件附加，cookie 仅匹配域/path 时发送。可信本地 SDK 输入时风险降低，服务端接不可信输入时为高。 |
| 随机 CDN、进程可变 APP_VERSION/CDN | 确认 | `J/.../ApiParser.java:220-222,378-384`; `J/.../JmConstants.java:35,121-136`; setting 半更新/默认不可变 add `J/.../JmApiClient.java:102-118`。`DEFAULT_IMAGE_DOMAINS` 可被外部重绑定，故“必抛”只成立于默认引用。 |
| AWT graphics 未 dispose、编码返回值忽略 | 确认 | `J/.../AwtImageProcessor.java:65-76`。只影响需要重组的路径，segments=0 直接返回原 bytes。 |
| Android Bitmap 异常资源 | 未及时 recycle/峰值压力确认 | `J/jmcomic-android-support/.../AndroidImageProcessor.java:34-49,74-90`；不能静态断言永久泄漏。 |
| 0 tests | 确认并实测 | `rg --files` 无任何 `src/test`，生产 Java 123、测试 Java 0；root verify 对三个 jar 模块均输出 `No tests to run`，JUnit 依赖声明不等于测试。 |
| sample 不进 reactor | 确认并实测失败 | `J/pom.xml:11-17`；`DownloaderSample.java:3-4` import 错误，两次 sample javac 均为 11 errors。 |
| release 不构建 artifact | 确认 | `J/.github/workflows/release.yml:27-40` 只有 `softprops/action-gh-release`。 |
| chapter images 接受 string/object，PHP 静默丢对象 | 确认 | Java `J/.../ApiParser.java:447-457`；PHP `P/index.php:1158-1162,2020-2023`。 |
| `/comic_read` 一次 images + scramble | 代码能力确认、等价性待 A/B | `J/.../JmApiClient.java:199-207`; `J/.../ApiParser.java:174-263`；Java正式 getPhoto仍两请求 `J/.../JmApiClient.java:211-255`。 |

## 附录 E：审计自检清单

- [x] 根 POM、4 模块 POM、CI、release、ReadTheDocs、MkDocs、README 中英文全部纳入。
- [x] 123/123 生产 Java 文件逐模块清点；0 测试明确记录。
- [x] API 四 client 接口、11 个全仓 public interface、50 个顶层 model record + 1 个 nested model record、3 query + 3 Builder、10 enum、7 exception、8 download、3 strategy 全部归类。
- [x] core 33 文件逐文件职责/风险结论。
- [x] Android 1、sample 4、resource 2 全部分析。
- [x] 网络/domain/retry/cache/cookie/response body/下载/tmp/SPI/线程/close/observer/状态机全部有文件行号。
- [x] 两项跨项目差异分别给 fixture、validator、fallback、A/B 和 Task 归属。
- [x] 采纳/改造后采纳/拒绝/待 A/B 四类完整矩阵；拒绝项写明目标契约冲突。
- [x] Task 5/6/7/8/10 有具体变更和测试，不把 Java 未来修复混入当前代码。
- [x] fresh 执行最终统计、XML/nav/provider/import、Javadoc 公开面、Maven reactor/sample、PHP lint/shape 校验并把结果写入下节。

## 附录 F：实际执行验证记录

2026-07-14 在本地快照执行了以下验证。Maven 会生成 `target` 并在 `install` 时写本机 Maven 仓库；最终通过 root Maven `clean` 并删除失败 sample 的生成目录恢复源码树。没有运行 `deploy`、release 或任何外部发布命令。只有本节结果可视为本次实际运行证据。

| 验证 | 命令要点 | 新鲜结果 |
|---|---|---|
| 源树闭合计数 | 仓库根 `rg --files --hidden -g '!.git/**' -g '!**/target/**'`；最终 clean 后另跑不排 target 的原命令 | 稳定源码 167 files = 123 Java + 44 non-Java；0 `src/test`。构建中 raw 曾为 740 = 167 + 573 `target`；最终原命令 fresh 恢复 **167/123/0，target files=0**。模块 Java为 api 85/core 33/android 1/sample 4。 |
| 工具链 | 设置 `JAVA_HOME` 与便携 PATH 后执行 `java -version; javac -version; mvn -version; php -v; git --version` | Temurin/Javac 17.0.19、Maven 3.9.11、PHP 8.3.32、Git 2.55.0；Docker `NOT_FOUND`。 |
| 根 reactor，PowerShell 原样参数 | `mvn -B -ntp -Dgpg.skip=true clean verify` | exit 1，11.921s；PowerShell 5.1 把参数错传成 lifecycle `.skip=true`，尚未进入源码构建。此项是 shell 参数层证据，不是项目失败。 |
| 根 reactor，修正参数传递 | `mvn -B -ntp "-Dgpg.skip=true" clean verify` | **exit 0 / BUILD SUCCESS / 1:32**；reactor 4/4（parent、api、core、android）成功。编译 85 + 33 + 1 源；api/core/android 均 `No tests to run`；core 有 unchecked warning。GPG execution 出现但 skip 属性生效。 |
| 当前 reactor 本地 install | `mvn -B -ntp "-Dgpg.skip=true" -DskipTests -Dmaven.javadoc.skip=true install` | exit 0 / 4/4 SUCCESS / 6.123s；只写本机 `.m2`，用于让 sample 针对当前源码依赖复验，不是发布证据。 |
| sample 独立构建（Central 依赖） | `mvn -B -ntp "-Dgpg.skip=true" -f .\jmcomic-sample\pom.xml clean verify` | exit 1 / 4.670s；到达 javac，11 errors。`DownloaderSample.java:3-4` 找不到 `api.client.DownloadProgress/DownloadResult`，后续 9 个符号错误均由此派生。 |
| sample 独立构建（当前 reactor install 后） | 同一 sample 命令再跑 | exit 1 / 1.797s；仍为同样 11 个错误，排除“只因解析 Central artifact”假设。真实类位于 `api.download`。 |
| PHP 当前行为 | `php -l P/index.php`；PHP 8.3 一次性执行与 `PayloadNormalizer::scalarString` 同语义的 object-image 分支 | lint exit 0；shape 复现 exit 0，结果 `is_scalar=false, normalized='', would_skip=true`，与 `P/index.php:1158-1162,2020-2023` 一致。 |
| POM/文档/resource/import | 解析 5 个 POM XML；核对 MkDocs 28 个 nav target、30 个 Markdown 本地链接、ServiceLoader provider 与内部 FQCN import | POM 5/5；nav 28/28；missing local links=0；provider 唯一匹配 AndroidImageProcessor；内部 import 仅 sample 两个错包。远程 URL 未联网逐链验证。 |
| 公开面与清单 | fresh Javadoc type/member index + 源码声明；附录 basename/FQCN 对账 | 129 public types；1,664 public + 57 protected members。123 个 Java basename、44 个非 Java条目均 missing=0/extra=0。 |
| 最终生成物清理 | root `mvn -B -ntp clean`，并清理失败 sample 的 `target` | root clean exit 0 / 4/4 SUCCESS；sample target 不存在；随后原始 `rg` 结果如首行。 |
| 证据引用 | 解析本文所有带数字行号的 `J/` 引用，展开唯一 basename 后检查路径与范围上界 | 219/219 引用存在，158 unique，0 missing、0 ambiguous、0 out-of-range；其中 `.java` 引用 187 处、133 unique。关键候选另做语义复核，不能把“行存在”替代语义判断。 |

未执行项及原因：没有 Android Gradle project/instrumentation 测试定义，因此未执行 D8/APK/Robolectric/device；Docker 不存在；未对真实 JM 网络执行 `/comic_read` A/B，也未运行会发布 artifact 的 deploy/release。Android 模块仅证明 Maven + Android 4.1.1.4 provided stub 可编译。真实上游等价性、设备资源行为和远程文档链接仍是明确剩余项。

最终自审结论：附录清单与隔离生成物后的 `rg` 数量闭合，公开 nested 类型已补齐，指定候选均有独立且收紧边界的结论，两个跨项目差异都有当前 PHP/Kotlin 对照、测试要求和 fallback。根 Maven 成功只表述为“生产模块可编译/打包且无测试”，sample 失败单列；没有再把 PATH 缺失、CI workflow 存在或静态 XML 成功误写成测试通过。
