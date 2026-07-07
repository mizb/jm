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
$readme = Read-ProjectFile 'README.md'
$runtimeVerify = Read-ProjectFile 'scripts/runtime-verify.ps1'
$entrypoint = Read-ProjectFile 'docker-entrypoint.sh'
$source = Read-ProjectFile 'index.php'

$expectedApiVersion = '2026.07.07.3'

Assert-Contains $dockerfile 'pecl\s+install\s+apcu' 'APCu installation'
Assert-Contains $dockerfile 'docker-php-ext-enable\s+apcu' 'APCu enablement'
Assert-Contains $dockerfile '127\.0\.0\.1:8088/\?health=1' 'healthcheck uses 8088'
Assert-Contains $dockerfile 'EXPOSE\s+8088' 'container exposes 8088'
Assert-Contains $dockerfile 'ENV\s+JM_API_VERSION=' 'Docker image declares JM API version'
Assert-Contains $dockerfile "ENV\s+JM_API_VERSION=$expectedApiVersion" 'Docker image declares current JM API version'
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
Assert-Contains $compose 'JM_PREFETCH_PAGES:\s*"10"' 'prefetch defaults to 10 pages'
Assert-Contains $compose 'JM_PREFETCH_HIGH_PRIORITY_PAGES:\s*"2"' 'high priority prefetch defaults to two pages'
Assert-Contains $compose 'JM_PREFETCH_MIN_FREE_BYTES:\s*"33554432"' 'prefetch free-memory waterline bytes'
Assert-Contains $compose 'JM_PREFETCH_MIN_FREE_RATIO:\s*"15"' 'prefetch free-memory waterline ratio'
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
Assert-NotContains $compose '/app/cache' 'file cache volume'
Assert-NotContains $dockerfile '8080' 'stale Dockerfile port 8080'
Assert-NotContains $compose '8080' 'stale compose port 8080'
Assert-NotContains $readme '8080' 'stale README port 8080'

Assert-Contains $readme 'JM_PAGE_CACHE_MAX_ITEM_BYTES' 'README documents max item cache setting'
Assert-Contains $readme 'APCu' 'README documents APCu memory cache'
Assert-Contains $readme 'scripts/runtime-verify.ps1' 'README documents runtime verifier script'
Assert-Contains $readme "JM API version $expectedApiVersion" 'README documents current startup version'
Assert-Contains $readme 'X-JM-API-Version' 'README documents version response header'
Assert-Contains $readme 'X-JM-Singleflight' 'README documents single-flight response header'
Assert-Contains $readme 'X-JM-Prefetch' 'README documents prefetch response header'
Assert-Contains $readme 'X-JM-Cache-Store' 'README documents cache store response header'
Assert-Contains $readme 'JM_PREFETCH_MIN_FREE_BYTES' 'README documents prefetch memory waterline'
Assert-Contains $readme 'JM_DOMAIN_COOLDOWN_SECONDS' 'README documents domain health cooldown'
$redisCacheAcceleration = -join @([char]0x7F13, [char]0x5B58, [char]0x52A0, [char]0x901F)
Assert-NotContains $readme $redisCacheAcceleration 'README must not describe Redis as cache acceleration'

Assert-Contains $runtimeVerify '& docker compose @Arguments' 'runtime verifier invokes docker compose through a checked wrapper'
Assert-Contains $runtimeVerify 'Invoke-DockerCompose -Arguments @\(''build''\)' 'runtime verifier builds compose image'
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
Assert-Contains $runtimeVerify '\?jmid=\$AlbumId&format=min' 'runtime verifier checks album metadata endpoint'
Assert-Contains $runtimeVerify 'X-JM-Cache' 'runtime verifier checks image cache header'
Assert-Contains $runtimeVerify 'X-JM-Image-Codec' 'runtime verifier checks image codec header'
Assert-Contains $runtimeVerify 'X-JM-Singleflight' 'runtime verifier checks single-flight header'
Assert-Contains $runtimeVerify 'X-JM-Prefetch' 'runtime verifier checks prefetch header'
Assert-Contains $runtimeVerify 'X-JM-Cache-Store' 'runtime verifier checks cache-store header'
Assert-Contains $runtimeVerify 'Assert-HeaderEquals \$firstImage.Headers ''X-JM-Cache'' ''MISS''' 'runtime verifier requires first image cache miss'
Assert-Contains $runtimeVerify 'Assert-HeaderEquals \$secondImage.Headers ''X-JM-Cache'' ''HIT''' 'runtime verifier requires second image cache hit'
Assert-Contains $runtimeVerify 'prefetch=0' 'runtime verifier checks prefetch can be disabled'
Assert-Contains $runtimeVerify 'N\+1 through N\+10' 'runtime verifier documents default prefetch range'
Assert-Contains $runtimeVerify 'Start-Sleep -Seconds \$PrefetchWaitSeconds' 'runtime verifier waits for shutdown prefetch before probing target pages'
Assert-Contains $runtimeVerify 'Assert-HeaderEquals \$prefetchResponse.Headers ''X-JM-Cache'' ''HIT''' 'runtime verifier checks prefetched pages with a single non-mutating assertion'
Assert-Contains $runtimeVerify 'Try-HeadImage -ImagePage \$candidatePage -DisablePrefetch \$true' 'runtime verifier disables cascading prefetch while probing prefetched pages'
Assert-NotContains $runtimeVerify 'Wait-ImageCacheHit' 'runtime verifier must not self-warm prefetch pages by polling'
Assert-Contains $runtimeVerify '/app/cache' 'runtime verifier checks no file cache volume'
Assert-Contains $runtimeVerify 'find /app -type f' 'runtime verifier checks no decoded image files are written'
Assert-NotContains $runtimeVerify '8080' 'runtime verifier has no stale port 8080'

Write-Output 'Docker runtime contract snippets found.'
