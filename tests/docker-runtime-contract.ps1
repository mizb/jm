$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot

function Read-ProjectFile {
    param([string] $RelativePath)
    $path = Join-Path $root $RelativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Missing file: $RelativePath"
    }
    return Get-Content -LiteralPath $path -Raw -Encoding UTF8
}

function Assert-Contains {
    param(
        [string] $Text,
        [string] $Pattern,
        [string] $Label
    )
    if ($Text -notmatch $Pattern) {
        throw "Missing expected Docker runtime contract snippet: $Label"
    }
}

function Assert-NotContains {
    param(
        [string] $Text,
        [string] $Pattern,
        [string] $Label
    )
    if ($Text -match $Pattern) {
        throw "Unexpected Docker runtime contract snippet: $Label"
    }
}

$dockerfile = Read-ProjectFile 'Dockerfile'
$compose = Read-ProjectFile 'docker-compose.yml'
$testCompose = Read-ProjectFile 'docker-compose.test.yml'
$readme = Read-ProjectFile 'README.md'
$runtimeVerify = Read-ProjectFile 'scripts/runtime-verify.ps1'
$entrypoint = Read-ProjectFile 'docker-entrypoint.sh'
$source = Read-ProjectFile 'index.php'
$workflow = Read-ProjectFile '.github\workflows\docker-build.yml'
$advancedDesign = Read-ProjectFile 'docs\advanced-reader-optimization-design.md'
$advancedPrompt = Read-ProjectFile 'docs\advanced-reader-optimization-ai-prompt.md'
$apiPrompt = Read-ProjectFile 'docs\ai-delivery-prompt.md'

$expectedApiVersion = '2026.07.17.7'

Assert-Contains $dockerfile 'pecl\s+install\s+apcu' 'APCu installation'
Assert-Contains $dockerfile 'docker-php-ext-enable\s+apcu' 'APCu enablement'
Assert-Contains $dockerfile '127\.0\.0\.1:8088/\?health=1' 'healthcheck uses 8088'
Assert-Contains $dockerfile 'EXPOSE\s+8088' 'container exposes 8088'
Assert-Contains $dockerfile "ARG\s+JM_API_VERSION=$expectedApiVersion" 'Docker image accepts current JM API version build arg'
Assert-Contains $dockerfile 'ENV\s+JM_API_VERSION=\$JM_API_VERSION' 'Docker image exports JM API version build arg'
Assert-Contains $dockerfile 'org\.opencontainers\.image\.version=\$JM_API_VERSION' 'Docker image labels current JM API version'
Assert-Contains $dockerfile 'COPY\s+--chown=www-data:www-data\s+docker-entrypoint\.sh' 'Docker image copies startup entrypoint'
Assert-Contains $dockerfile 'CMD\s+\["/app/docker-entrypoint\.sh"\]' 'Docker image starts through version-printing entrypoint'
Assert-Contains $entrypoint 'JM API version' 'Docker startup entrypoint prints JM API version'
Assert-Contains $entrypoint $expectedApiVersion 'Docker startup entrypoint has current fallback version'
Assert-Contains $entrypoint 'exec php' 'Docker startup entrypoint execs PHP server'
Assert-Contains $entrypoint 'apc\.enable_cli=1' 'APCu enabled for PHP CLI server'
Assert-Contains $entrypoint 'apc\.shm_size=128M' 'APCu shared memory size'
Assert-Contains $entrypoint '0\.0\.0\.0:8088' 'PHP server listens on 8088'
Assert-Contains $source 'APP_VERSION' 'API source declares app version'
Assert-Contains $source "APP_VERSION\s*=\s*'$expectedApiVersion'" 'API source declares current app version'
Assert-Contains $source 'function\s+jmApiVersion' 'API source centralizes runtime version'
Assert-Contains $source 'X-JM-API-Version' 'API responses expose version header'
Assert-Contains $source '''version''\s*=>\s*jmApiVersion\(\)' 'health endpoint exposes top-level version'
Assert-Contains $source '''app_version''' 'health endpoint exposes app version'

Assert-Contains $compose '"8088:8088"' 'compose maps 8088 to 8088'
Assert-Contains $compose "JM_API_VERSION:\s*`"$expectedApiVersion`"" 'compose environment exposes current JM API version'
Assert-Contains $compose 'JM_PREFETCH_PAGES' 'prefetch environment setting'
Assert-Contains $compose 'JM_PREFETCH_PAGES:\s*"\$\{JM_PREFETCH_PAGES:-10\}"' 'prefetch defaults to 10 pages and accepts an override'
Assert-Contains $compose 'JM_PREFETCH_HIGH_PRIORITY_PAGES:\s*"\$\{JM_PREFETCH_HIGH_PRIORITY_PAGES:-2\}"' 'high priority prefetch defaults to two pages and accepts an override'
Assert-Contains $compose 'JM_PREFETCH_WALL_BUDGET_MS:\s*"\$\{JM_PREFETCH_WALL_BUDGET_MS:-5000\}"' 'prefetch wall budget defaults to five seconds and accepts an override'
Assert-Contains $compose 'JM_PREFETCH_BYTE_BUDGET:\s*"\$\{JM_PREFETCH_BYTE_BUDGET:-16777216\}"' 'prefetch byte budget defaults to 16 MiB and accepts an override'
Assert-Contains $compose 'JM_PREFETCH_MAX_ACTIVE:\s*"\$\{JM_PREFETCH_MAX_ACTIVE:-2\}"' 'prefetch global active slots default to two and accepts an override'
Assert-Contains $compose 'JM_PREFETCH_MIN_FREE_BYTES:\s*"\$\{JM_PREFETCH_MIN_FREE_BYTES:-33554432\}"' 'prefetch free-memory waterline bytes default and override'
Assert-Contains $compose 'JM_PREFETCH_MIN_FREE_RATIO:\s*"\$\{JM_PREFETCH_MIN_FREE_RATIO:-15\}"' 'prefetch free-memory waterline ratio default and override'
Assert-Contains $compose 'JM_PAGE_CACHE_TTL' 'page cache ttl environment setting'
Assert-Contains $compose 'JM_CHAPTER_CACHE_TTL' 'chapter cache ttl environment setting'
Assert-Contains $compose 'JM_PAGE_CACHE_MAX_ITEM_BYTES' 'page cache max item bytes environment setting'
Assert-Contains $compose 'JM_PAGE_CACHE_MIN_FREE_BYTES:\s*"16777216"' 'page cache free-memory waterline bytes'
Assert-Contains $compose 'JM_PAGE_CACHE_MIN_FREE_RATIO:\s*"8"' 'page cache free-memory waterline ratio'
Assert-Contains $compose 'JM_SINGLEFLIGHT_LOCK_TTL:\s*"30"' 'single-flight lock TTL'
Assert-Contains $compose 'JM_SINGLEFLIGHT_WAIT_MS:\s*"5000"' 'single-flight bounded wait'
Assert-Contains $compose 'JM_NEXT_CHAPTER_PREFETCH:\s*"1"' 'next chapter prefetch enabled by default'
Assert-Contains $compose 'JM_NEXT_CHAPTER_PREFETCH_PAGES:\s*"2"' 'next chapter prefetch page count'
Assert-Contains $compose 'JM_DOMAIN_COOLDOWN_SECONDS:\s*"120"' 'domain health cooldown'
Assert-Contains $compose 'JM_DOMAIN_STATS_TTL:\s*"21600"' 'domain health stats TTL'
Assert-Contains $compose 'PHP_CLI_SERVER_WORKERS:\s*"10"' 'PHP CLI server workers setting'
Assert-Contains $compose 'JM_REQUEST_BUDGET_MS:\s*"\$\{JM_REQUEST_BUDGET_MS:-12000\}"' 'shared upstream request budget default and override'
Assert-Contains $compose 'JM_MAX_UPSTREAM_ATTEMPTS:\s*"\$\{JM_MAX_UPSTREAM_ATTEMPTS:-15\}"' 'shared upstream attempt limit defaults to three bounded attempts per domain and accepts an override'
Assert-Contains $compose 'JM_LIST_CACHE_TTL:\s*"\$\{JM_LIST_CACHE_TTL:-60\}"' 'list source cache TTL default and override'
Assert-Contains $compose 'JM_SEARCH_CACHE_TTL:\s*"\$\{JM_SEARCH_CACHE_TTL:-30\}"' 'search source cache TTL default and override'
Assert-Contains $compose 'JM_WEEKLY_LIST_CACHE_TTL:\s*"\$\{JM_WEEKLY_LIST_CACHE_TTL:-60\}"' 'weekly list source cache TTL default and override'
Assert-Contains $compose 'JM_ALBUM_CACHE_TTL:\s*"\$\{JM_ALBUM_CACHE_TTL:-45\}"' 'album metadata cache TTL default and override'
Assert-Contains $compose 'JM_WEEK_DEFAULTS_CACHE_TTL:\s*"\$\{JM_WEEK_DEFAULTS_CACHE_TTL:-600\}"' 'weekly defaults fresh TTL default and override'
Assert-Contains $compose 'JM_WEEK_DEFAULTS_STALE_TTL:\s*"\$\{JM_WEEK_DEFAULTS_STALE_TTL:-3600\}"' 'weekly defaults stale TTL default and override'
Assert-Contains $compose 'JM_CACHE_FILL_WAIT_MS:\s*"\$\{JM_CACHE_FILL_WAIT_MS:-750\}"' 'metadata cache loser wait default and override'
Assert-Contains $compose 'JM_CACHE_FILL_LOCK_TTL:\s*"\$\{JM_CACHE_FILL_LOCK_TTL:-15\}"' 'metadata cache fill lease default and override'
Assert-Contains $compose 'JM_DOMAIN_FRESH_TTL:\s*"\$\{JM_DOMAIN_FRESH_TTL:-86400\}"' 'domain fresh TTL default and override'
Assert-Contains $compose 'JM_DOMAIN_STALE_TTL:\s*"\$\{JM_DOMAIN_STALE_TTL:-86400\}"' 'domain stale TTL default and override'
Assert-Contains $compose 'JM_DOMAIN_REFRESH_DEFERRED:\s*"\$\{JM_DOMAIN_REFRESH_DEFERRED:-1\}"' 'deferred domain refresh default and override'
Assert-Contains $compose 'JM_DOMAIN_SOURCE_TIMEOUT_MS:\s*"\$\{JM_DOMAIN_SOURCE_TIMEOUT_MS:-1500\}"' 'domain source timeout default and override'
Assert-Contains $compose 'JM_DOMAIN_REFRESH_BUDGET_MS:\s*"\$\{JM_DOMAIN_REFRESH_BUDGET_MS:-3000\}"' 'domain refresh total budget default and override'
Assert-Contains $compose 'JM_DOMAIN_REFRESH_FAILURE_TTL:\s*"\$\{JM_DOMAIN_REFRESH_FAILURE_TTL:-60\}"' 'domain refresh failure suppression default and override'
Assert-Contains $compose 'JM_IMAGE_MAX_COMPRESSED_BYTES:\s*"\$\{JM_IMAGE_MAX_COMPRESSED_BYTES:-33554432\}"' 'compressed image byte cap default and override'
Assert-Contains $compose 'JM_IMAGE_MAX_PIXELS:\s*"\$\{JM_IMAGE_MAX_PIXELS:-80000000\}"' 'decoded image pixel cap default and override'
Assert-Contains $compose 'JM_CDN_EPOCH:\s*"\$\{JM_CDN_EPOCH:-1\}"' 'stable cover CDN epoch default and override'
Assert-Contains $compose 'JM_TRUSTED_PROXY_CIDRS:\s*"\$\{JM_TRUSTED_PROXY_CIDRS:-\}"' 'forwarded headers are untrusted by default and accept an override'
Assert-NotContains $compose '/app/cache' 'file cache volume'
Assert-NotContains $compose 'JM_TEST_' 'production compose test-only variables'
Assert-Contains $testCompose 'PHP_CLI_SERVER_WORKERS:\s*"12"' 'test API uses enough CLI workers for prefetch concurrency'
Assert-Contains $testCompose 'JM_TEST_PREFETCH_STATS_DIR' 'test compose enables direct prefetch owner stats'
Assert-Contains $testCompose 'jm-fixture-stats:/tmp/jm-fixture-stats' 'API and fixture share owner stats volume'
Assert-NotContains $dockerfile '8080' 'stale Dockerfile port 8080'
Assert-NotContains $compose '8080' 'stale compose port 8080'
Assert-NotContains $readme '8080' 'stale README port 8080'

Assert-Contains $workflow "JM_API_VERSION:\s*$expectedApiVersion" 'GHCR workflow declares current JM API version'
Assert-Contains $workflow 'type=raw,value=\$\{\{\s*env\.JM_API_VERSION\s*\}\}' 'GHCR workflow publishes immutable version tag'
Assert-Contains $workflow 'build-args:' 'GHCR workflow passes Docker build args'
Assert-Contains $workflow "JM_API_VERSION=\$\{\{\s*env\.JM_API_VERSION\s*\}\}" 'GHCR workflow passes JM API version build arg'
Assert-Contains $workflow 'org\.opencontainers\.image\.version=\$\{\{\s*env\.JM_API_VERSION\s*\}\}' 'GHCR workflow labels image version'

Assert-Contains $readme 'JM_PAGE_CACHE_MAX_ITEM_BYTES' 'README documents max item cache setting'
Assert-Contains $readme 'APCu' 'README documents APCu memory cache'
Assert-Contains $readme 'scripts/runtime-verify.ps1' 'README documents runtime verifier script'
Assert-Contains $readme "JM API version $expectedApiVersion" 'README documents current startup version'
Assert-Contains $readme "ghcr\.io/[^\r\n]+:$([regex]::Escape($expectedApiVersion))" 'README documents immutable GHCR version tag'
Assert-Contains $readme 'docker image inspect' 'README documents image version label inspection'
Assert-Contains $readme 'X-JM-API-Version' 'README documents version response header'
Assert-Contains $readme 'X-JM-Singleflight' 'README documents single-flight response header'
Assert-Contains $readme 'X-JM-Prefetch' 'README documents prefetch response header'
Assert-Contains $readme 'X-JM-Cache-Store' 'README documents cache store response header'
Assert-Contains $readme 'JM_PREFETCH_MIN_FREE_BYTES' 'README documents prefetch memory waterline'
Assert-Contains $readme 'JM_DOMAIN_COOLDOWN_SECONDS' 'README documents domain health cooldown'
$requiredReadmeControls = @(
    'JM_REQUEST_BUDGET_MS',
    'JM_MAX_UPSTREAM_ATTEMPTS',
    'JM_LIST_CACHE_TTL',
    'JM_SEARCH_CACHE_TTL',
    'JM_WEEKLY_LIST_CACHE_TTL',
    'JM_ALBUM_CACHE_TTL',
    'JM_WEEK_DEFAULTS_CACHE_TTL',
    'JM_WEEK_DEFAULTS_STALE_TTL',
    'JM_DOMAIN_FRESH_TTL',
    'JM_DOMAIN_STALE_TTL',
    'JM_DOMAIN_REFRESH_DEFERRED',
    'JM_PREFETCH_WALL_BUDGET_MS',
    'JM_PREFETCH_BYTE_BUDGET',
    'JM_PREFETCH_MAX_ACTIVE',
    'JM_IMAGE_MAX_COMPRESSED_BYTES',
    'JM_IMAGE_MAX_PIXELS',
    'JM_TRUSTED_PROXY_CIDRS'
)
foreach ($control in $requiredReadmeControls) {
    Assert-Contains $readme ([regex]::Escape($control)) "README documents $control"
}
Assert-Contains $readme 'JM_LIST_CACHE_TTL=0' 'README provides immediate list cache rollback'
Assert-Contains $readme 'JM_ALBUM_CACHE_TTL=0' 'README provides immediate album cache rollback'
Assert-Contains $readme 'JM_DOMAIN_REFRESH_DEFERRED=0' 'README provides immediate domain refresh rollback'
Assert-Contains $readme 'JM_PREFETCH_PAGES=0' 'README provides immediate prefetch rollback'
Assert-Contains $readme 'docker compose build --no-cache' 'README provides clean image build command'
Assert-Contains $readme 'performance-baseline\.ps1' 'README provides performance measurement command'
Assert-Contains $readme '\u7248\u672c\u56de\u9000|version rollback|version/commit rollback' 'README distinguishes correctness rollback from tuning controls'
$redisCacheAcceleration = -join @([char]0x7F13, [char]0x5B58, [char]0x52A0, [char]0x901F)
Assert-NotContains $readme $redisCacheAcceleration 'README must not describe Redis as cache acceleration'

Assert-Contains $advancedDesign $expectedApiVersion 'advanced API design documents current API version'
Assert-Contains $advancedDesign 'list=promote' 'advanced API design documents promote list mode'
Assert-Contains $advancedDesign 'list=weekly' 'advanced API design documents weekly list mode'
Assert-Contains $advancedPrompt $expectedApiVersion 'advanced AI prompt documents current API version'
Assert-Contains $apiPrompt $expectedApiVersion 'AI delivery prompt documents current API version'
Assert-Contains $apiPrompt 'D:\\jm\\jmcomic-api-main' 'AI delivery prompt uses the current API path'
Assert-NotContains $apiPrompt 'D:\\jm\\jm-boom-master\\jmcomic-api-main' 'AI delivery prompt has no obsolete API path'
Assert-NotContains $advancedDesign 'D:\\jm\\jm-boom-master\\jmcomic-api-main' 'advanced design has no obsolete API path'
Assert-NotContains $advancedPrompt 'D:\\jm\\jm-boom-master\\jmcomic-api-main' 'advanced AI prompt has no obsolete API path'

Assert-Contains $runtimeVerify '& docker compose @Arguments' 'runtime verifier invokes docker compose through a checked wrapper'
Assert-Contains $runtimeVerify 'Invoke-DockerCompose -Arguments @\(''build'', ''--no-cache''\)' 'runtime verifier builds a fresh compose image without layer reuse'
Assert-Contains $runtimeVerify 'Invoke-DockerCompose -Arguments @\(''up'', ''-d'', ''--force-recreate''\)' 'runtime verifier recreates service for cold cache'
Assert-Contains $runtimeVerify 'Invoke-DockerCompose -Arguments @\(''exec'', ''-T'', ''jmcomic-api''' 'runtime verifier checks container filesystem through checked compose exec'
Assert-Contains $runtimeVerify '\$LASTEXITCODE' 'runtime verifier checks native Docker command exit codes'
Assert-Contains $runtimeVerify 'function Invoke-DockerCompose' 'runtime verifier wraps docker compose command execution'
Assert-NotContains $runtimeVerify 'ValueFromRemainingArguments' 'runtime verifier must not swallow dash-prefixed docker compose arguments'
Assert-Contains $runtimeVerify '\?health=1' 'runtime verifier checks health endpoint'
Assert-Contains $runtimeVerify 'X-JM-API-Version' 'runtime verifier checks API version header'
Assert-Contains $runtimeVerify 'top-level version' 'runtime verifier checks health top-level version'
Assert-Contains $runtimeVerify 'diagnostics.app_version' 'runtime verifier checks app version diagnostics'
Assert-Contains $runtimeVerify 'apcu_details' 'runtime verifier checks APCu diagnostics'
Assert-Contains $runtimeVerify '\?list=latest&page=1&format=min' 'runtime verifier checks latest list starts at page 1'
Assert-Contains $runtimeVerify '\?list=promote&page=1&format=min' 'runtime verifier checks original homepage recommendations'
Assert-Contains $runtimeVerify '\?list=weekly&page=1&format=min' 'runtime verifier checks original weekly picks'
Assert-Contains $runtimeVerify '\?jmid=\$AlbumId&format=min' 'runtime verifier checks album metadata endpoint'
Assert-Contains $runtimeVerify 'X-JM-Cache' 'runtime verifier checks image cache header'
Assert-Contains $runtimeVerify 'X-JM-Image-Codec' 'runtime verifier checks image codec header'
Assert-Contains $runtimeVerify 'X-JM-Singleflight' 'runtime verifier checks single-flight header'
Assert-Contains $runtimeVerify 'X-JM-Prefetch' 'runtime verifier checks prefetch header'
Assert-Contains $runtimeVerify 'X-JM-Cache-Store' 'runtime verifier checks cache-store header'
Assert-Contains $runtimeVerify 'Assert-HeaderEquals \$firstImage.Headers ''X-JM-Cache'' ''MISS''' 'runtime verifier requires first image cache miss'
Assert-Contains $runtimeVerify 'Assert-HeaderEquals \$secondImage.Headers ''X-JM-Cache'' ''HIT''' 'runtime verifier requires second image cache hit'
Assert-Contains $runtimeVerify 'prefetch=0' 'runtime verifier checks prefetch can be disabled'
Assert-Contains $runtimeVerify 'bounded HIT subset' 'runtime verifier accepts the budget-bounded prefetch subset'
Assert-Contains $runtimeVerify 'Start-Sleep -Seconds \$PrefetchWaitSeconds' 'runtime verifier waits for shutdown prefetch before probing target pages'
Assert-Contains $runtimeVerify 'Try-GetImage -ImagePage \$candidatePage -DisablePrefetch \$true' 'runtime verifier disables cascading prefetch while probing prefetched pages'
Assert-Contains $runtimeVerify 'prefetch.aggregate' 'runtime verifier checks prefetch stats consistency'
Assert-Contains $runtimeVerify 'page_count -ge 4' 'runtime verifier requires confirmed adjacent page3+ inputs'
Assert-Contains $runtimeVerify 'confirmed next_chapter' 'runtime verifier requires a real next chapter for prefetch=0'
Assert-Contains $runtimeVerify 'X-JM-Prefetch'' ''disabled' 'runtime verifier proves prefetch=0 exits before scheduling'
Assert-NotContains $runtimeVerify '-Method\s+''HEAD''' 'runtime verifier must not use HEAD for decoded image work'
Assert-NotContains $runtimeVerify 'Write-Warning "Could not check prefetch=0' 'runtime verifier must not turn an out-of-range prefetch=0 case into a zero-assertion pass'
Assert-NotContains $runtimeVerify 'Wait-ImageCacheHit' 'runtime verifier must not self-warm prefetch pages by polling'
Assert-Contains $runtimeVerify '/app/cache' 'runtime verifier checks no file cache volume'
Assert-Contains $runtimeVerify 'function Get-OnDiskImageArtifacts' 'runtime verifier defines image-artifact signature scanning'
Assert-Contains $runtimeVerify '\$roots = \[''/app'', ''/tmp''\]' 'runtime verifier scans both application and temporary filesystems'
Assert-Contains $runtimeVerify '89504e470d0a1a0a' 'runtime verifier detects images by file magic rather than filename extension'
Assert-Contains $runtimeVerify '\$imageArtifactsBefore = @\(Get-OnDiskImageArtifacts\)' 'runtime verifier captures image artifacts before requests'
Assert-Contains $runtimeVerify '\$imageArtifactsAfter = @\(Get-OnDiskImageArtifacts\)' 'runtime verifier captures image artifacts after requests'
Assert-Contains $runtimeVerify '\$newImageArtifacts\.Count -eq 0' 'runtime verifier rejects newly written decoded image artifacts'
Assert-NotContains $runtimeVerify '8080' 'runtime verifier has no stale port 8080'

Write-Output 'Docker runtime contract snippets found.'
