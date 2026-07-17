param(
    [ValidateSet('RequestBudget', 'Domain', 'CacheList', 'CacheMetadata', 'Cache', 'Prefetch', 'Resources', 'Verification', 'All')]
    [string] $Area = 'All'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$source = Get-Content -LiteralPath (Join-Path $root 'index.php') -Raw -Encoding UTF8
$dockerfile = Get-Content -LiteralPath (Join-Path $root 'Dockerfile') -Raw -Encoding UTF8
$compose = Get-Content -LiteralPath (Join-Path $root 'docker-compose.yml') -Raw -Encoding UTF8
$runtime = Get-Content -LiteralPath (Join-Path $root 'scripts\runtime-verify.ps1') -Raw -Encoding UTF8
$baseline = Get-Content -LiteralPath (Join-Path $root 'scripts\performance-baseline.ps1') -Raw -Encoding UTF8
$performanceEvidencePath = Join-Path $root 'scripts\performance-evidence.ps1'
$performanceEvidence = Get-Content -LiteralPath $performanceEvidencePath -Raw -Encoding UTF8
$faultRuntime = Get-Content -LiteralPath (Join-Path $root 'tests\fault-injection-runtime.ps1') -Raw -Encoding UTF8
$prefetchRuntime = Get-Content -LiteralPath (Join-Path $root 'tests\prefetch-policy-runtime.php') -Raw -Encoding UTF8
$upstreamRuntime = Get-Content -LiteralPath (Join-Path $root 'tests\upstream-policy-runtime.php') -Raw -Encoding UTF8
$testCompose = Get-Content -LiteralPath (Join-Path $root 'docker-compose.test.yml') -Raw -Encoding UTF8
$fixture = Get-Content -LiteralPath (Join-Path $root 'tests\fixtures\upstream-router.php') -Raw -Encoding UTF8
$resourceRuntime = Get-Content -LiteralPath (Join-Path $root 'tests\resource-policy-runtime.php') -Raw -Encoding UTF8
$resourceFixtureContract = Get-Content -LiteralPath (Join-Path $root 'tests\resource-fixture-contract.ps1') -Raw -Encoding UTF8
$resourceHttpRuntime = Get-Content -LiteralPath (Join-Path $root 'tests\resource-http-runtime.ps1') -Raw -Encoding UTF8
$redisRuntime = Get-Content -LiteralPath (Join-Path $root 'tests\redis-rate-limit-runtime.php') -Raw -Encoding UTF8
$redisGate = Get-Content -LiteralPath (Join-Path $root 'tests\redis-rate-limit-runtime.ps1') -Raw -Encoding UTF8

function Assert-Contains {
    param([string] $Text, [string] $Snippet, [string] $Label)
    if (-not $Text.Contains($Snippet)) { throw "Missing performance policy contract: $Label ($Snippet)" }
}

function Assert-Matches {
    param([string] $Text, [string] $Pattern, [string] $Label)
    if ($Text -notmatch $Pattern) { throw "Missing performance policy contract: $Label" }
}

function Get-PhpFunctionBlock {
    param([string] $Text, [string] $Name)
    $declarationPattern = '(?m)^[ \t]*(?:(?:public|private|protected)[ \t]+)?(?:static[ \t]+)?function[ \t]+{0}[ \t]*\(' -f [regex]::Escape($Name)
    $declaration = [regex]::Match($Text, $declarationPattern)
    if (-not $declaration.Success) {
        throw "Missing performance policy contract: PHP function declaration $Name"
    }

    $searchStart = $declaration.Index + $declaration.Length
    $remaining = $Text.Substring($searchStart)
    $nextDeclaration = [regex]::Match(
        $remaining,
        '(?m)^[ \t]*(?:(?:public|private|protected)[ \t]+)?(?:static[ \t]+)?function[ \t]+[A-Za-z_][A-Za-z0-9_]*[ \t]*\('
    )
    $end = if ($nextDeclaration.Success) {
        $searchStart + $nextDeclaration.Index
    } else {
        $Text.Length
    }
    return $Text.Substring($declaration.Index, $end - $declaration.Index)
}

function Test-Area {
    param([string] $Name)
    return $Area -eq 'All' -or $Area -eq $Name
}

if (Test-Area 'RequestBudget') {
    foreach ($snippet in @(
        'final class RequestContext',
        'final class UpstreamBudget',
        'final readonly class HttpResult',
        'interface UpstreamTransport',
        'JM_REQUEST_BUDGET_MS',
        'JM_MAX_UPSTREAM_ATTEMPTS',
        'X-JM-Upstream-Attempts',
        'X-JM-Upstream-Ms',
        'X-JM-Request-Id',
        'X-JM-Test-Scenario',
        'X-JM-Test-Run-Id',
        'Retry-After'
    )) { Assert-Contains $source $snippet $snippet }
    Assert-Matches $source 'new\s+JmService\(\$requestContext\)' 'routes pass one request context to JmService'
    Assert-Matches $source 'final class JmService[\s\S]*?function\s+__construct\(\s*RequestContext\s+\$context,[\s\S]*?\$this->api\s*=\s*\$api\s*\?\?\s*new\s+JmApiClient\(\$context\)' 'service passes the same request context to the API client'
    Assert-Matches $source 'function\s+beginUpstreamAttempt\([\s\S]*?budget\(\)->beginAttempt\(\)' 'API client has one shared budget gate'
    Assert-Matches $source 'callJson[\s\S]*?beginUpstreamAttempt\(\)[\s\S]*?\$ts\s*=\s*\(string\)\s*\$this->context->unixTime\(\)[\s\S]*?\$this->http->get' 'callJson uses the shared budget and regenerates tokens per attempt'
    Assert-Matches $source 'fetchScrambleId[\s\S]*?beginUpstreamAttempt\(\)[\s\S]*?\$ts\s*=\s*\(string\)\s*\$this->context->unixTime\(\)[\s\S]*?\$this->http->get' 'fetchScrambleId uses the shared budget and regenerates tokens per attempt'
    Assert-Matches $source 'function\s+initialBaseUrls\(' 'API client defines initial base URL selection'
    Assert-Matches $source 'function\s+normalizeBaseUrls\(' 'API client validates explicit base URLs'
    Assert-Matches $source "getenv\('JM_TEST_MODE'\)[\s\S]*?===\s*'1'" 'test mode requires the exact JM_TEST_MODE=1 value'
    Assert-Matches $source 'fromGlobals[\s\S]*?\$testMode\s*\?\s*self::safeTestToken\(\$_GET\[''test_scenario''\]' 'test scenario is ignored outside test mode'
    Assert-Matches $source 'initialBaseUrls[\s\S]*?isTestMode\(\)[\s\S]*?JM_TEST_API_BASE_URLS' 'production ignores explicit test upstream URLs'
    Assert-Matches $source 'normalizeBaseUrls[\s\S]*?testAllowedHosts\(\)[\s\S]*?\$allowedHosts\[\$host\]' 'test upstream URLs require an allowed host'
    Assert-Matches $source 'CURLOPT_HEADERFUNCTION|CURLOPT_HEADERFUNCTION' 'transport captures response headers'
    Assert-Matches $source 'CURLOPT_WRITEFUNCTION' 'transport resets the response write target'
    Assert-Matches $source 'CURLINFO_NAMELOOKUP_TIME|CURLINFO_NAMELOOKUP_TIME_T' 'transport captures DNS timing'
    Assert-Matches $source 'function\s+sendJson[\s\S]*?RequestContext::current\(\)\?->emitResponseHeaders\(\)' 'JSON responses emit request diagnostics'
    Assert-Matches $source 'function\s+sendError[\s\S]*?RequestContext::current\(\)\?->emitResponseHeaders\(\)' 'error responses emit request diagnostics'
    Assert-Matches $source 'function\s+sendBinaryImage[\s\S]*?RequestContext::current\(\)\?->emitResponseHeaders\(\)' 'binary responses emit request diagnostics'
    Assert-Matches $source 'RequestContext::fromGlobals\(''bootstrap''\)[\s\S]*?foreach\s*\(\[''curl'',\s*''openssl'',\s*''json'',\s*''mbstring''\][\s\S]*?sendError\(500' 'missing-extension bootstrap errors use the common diagnostic response path'
    Assert-Matches $faultRuntime 'BootstrapDiagnosticsSelfTest[\s\S]*?X-JM-Request-Id[\s\S]*?X-JM-Upstream-Attempts[\s\S]*?X-JM-Upstream-Ms[\s\S]*?X-JM-Deadline-Exhausted' 'missing-extension bootstrap diagnostics have a real HTTP self-test'
    Assert-Matches $source "'test_mode'\s*=>[\s\S]*?'test_api_source'\s*=>" 'health exposes safe test upstream diagnostics'
    if ($source -match '\[\s*\$ok\s*,[^\]]*\]\s*=\s*\$this->http->get') {
        throw 'Legacy tuple destructuring remains on an upstream transport call.'
    }
    if ($source -match 'for\s*\([^\)]*JmConfig::MAX_RETRIES') {
        throw 'Legacy domains x MAX_RETRIES loop remains in the request path.'
    }
    if ($source -match '\$this->domains\b') {
        throw 'Legacy API domain field remains after base URL migration.'
    }
}

if (Test-Area 'Domain') {
    foreach ($snippet in @(
        'final class DomainResolver',
        'JM_DOMAIN_FRESH_TTL',
        'JM_DOMAIN_STALE_TTL',
        'JM_DOMAIN_REFRESH_DEFERRED',
        'domain-refresh-lease:v1',
        'domain-refresh-failed:v1',
        'fresh_until',
        'stale_until'
    )) { Assert-Contains $source $snippet $snippet }
    Assert-Matches $source 'resolveForRequest[\s\S]*?JmConfig::API_DOMAINS' 'domain requests can immediately use fallback'
    Assert-Matches $source "JM_DOMAIN_SOURCE_TIMEOUT_MS',\s*1500,\s*1000,\s*2000" 'domain source timeout is configurable only within the 1-2 second contract'
    Assert-Matches $source 'function\s+sendJson[\s\S]*?\$body\s*=\s*json_encode[\s\S]*?Content-Length:[^\r\n]*strlen\(\$body\)[\s\S]*?echo\s+\$body;[\s\S]*?flush\(\)' 'JSON publishes a framed complete body before deferred shutdown work'
    Assert-Matches $source 'function\s+sendBinaryImage[\s\S]*?Content-Length:[^\r\n]*strlen\(\$bytes\)[\s\S]*?echo\s+\$bytes;[\s\S]*?flush\(\)' 'binary responses flush the framed body before deferred shutdown work'
    Assert-Matches $faultRuntime 'LocalDomainRefresh[\s\S]*?ElapsedMs\s+-lt\s+750[\s\S]*?domain-config-timeout' 'local runtime proves domain refresh starts after the complete client response'
    if ($faultRuntime -notmatch '\$domainFallback\.ElapsedMs\s+-lt\s+750') {
        throw 'Docker domain-source runtime must not tolerate a full 1.5-second refresh in the client response.'
    }
    if ($source -match 'resolveDomains[\s\S]{0,2500}file_get_contents[\s\S]{0,500}timeout\s*''?\s*=>\s*10') {
        throw 'Business request construction must not synchronously probe three domain sources with 10 second timeouts.'
    }
}

if ((Test-Area 'CacheList') -or $Area -eq 'Cache') {
    foreach ($snippet in @(
        'JM_LIST_CACHE_TTL',
        'JM_SEARCH_CACHE_TTL',
        'JM_WEEKLY_LIST_CACHE_TTL',
        'cacheThroughArray',
        'canonicalCacheValue',
        'testCacheNamespace',
        'X-JM-Source-Cache',
        'source_cache_hits',
        'source_cache_misses'
    )) { Assert-Contains $source $snippet $snippet }
    Assert-Matches $source '\$this->cacheNamespace\s*\.\s*\$class\s*\.\s*'':v1:''\s*\.\s*hash\(''sha256'',\s*json_encode' 'list source cache keys use an explicit schema and SHA-256 canonical JSON'
    Assert-Matches $source 'canonicalCacheValue[\s\S]*?!is_array\(\$value\)[\s\S]*?array_is_list\(\$value\)[\s\S]*?array_map[\s\S]*?ksort\(\$value,\s*SORT_STRING\)' 'cache key canonicalizer preserves scalars/lists and recursively sorts maps'
    Assert-Matches $source 'if\s*\(\$ttl\s*<=\s*0\s*\|\|\s*!\$this->cache->isAvailable\(\)\)[\s\S]*?produceValidatedArray\(\$validator,\s*\$producer\)' 'TTL zero bypasses source cache and calls the validated producer'
    Assert-Matches $source 'tryAdd\(\$leaseKey,\s*\$token,\s*\$lockTtl\)[\s\S]*?\$this->cache->get\(\$key\)[\s\S]*?produceValidatedArray[\s\S]*?\$this->cache->set[\s\S]*?finally[\s\S]*?compareAndDelete\(\$leaseKey,\s*\$token\)' 'cache fill owner double-checks and token-safely releases its lease'
    Assert-Matches $source '\$lockTtl\s*=\s*min\(90,\s*max\([\s\S]*?ceil\(max\(1,\s*\$remainingMs\)\s*/\s*1000\)\s*\+\s*2\)\)' 'cache fill lease covers producer budget with a bounded cap'
    Assert-Matches $source 'JM_CACHE_FILL_WAIT_MS[\s\S]*?usleep\(random_int\([\s\S]*?\$this->cache->get\(\$key\)[\s\S]*?remainingMs\(\)\s*<=\s*100[\s\S]*?Source cache fill exceeded request deadline' 'cache fill losers jitter, reread, and honor the request deadline'
    $listItemNormalizer = Get-PhpFunctionBlock $source 'normalizeListItemPayload'
    $listItemsNormalizer = Get-PhpFunctionBlock $source 'normalizeListItemsPayload'
    $listItemsContract = '(?s)^\s*private\s+static\s+function\s+normalizeListItemsPayload\s*\(\s*mixed\s+\$payload\s*\)\s*:\s*array\s*\{\s*if\s*\(\s*!is_array\(\$payload\)\s*\|\|\s*!array_is_list\(\$payload\)\s*\)\s*\{\s*throw\s+new\s+JmException\(''Invalid upstream list payload'',\s*502\)\s*;\s*\}\s*\$normalized\s*=\s*\[\s*\]\s*;\s*foreach\s*\(\s*\$payload\s+as\s+\$item\s*\)\s*\{\s*\$normalized\[\]\s*=\s*self::normalizeListItemPayload\(\$item\)\s*;\s*\}\s*return\s+\$normalized\s*;\s*\}\s*$'
    Assert-Matches $listItemsNormalizer $listItemsContract 'list payload normalizer requires a true list and maps a valid empty list to an empty list'
    Assert-Matches $listItemNormalizer 'if\s*\(\s*!is_array\(\$item\)\s*\|\|\s*array_is_list\(\$item\)\s*\)' 'list item validator requires an object/map shape'
    Assert-Matches $listItemNormalizer 'trim\(PayloadNormalizer::scalarString\(\$item\[''id''\]\s*\?\?\s*\$item\[''aid''\]\s*\?\?\s*\$item\[''AID''\]\s*\?\?\s*''''\)\)[\s\S]*?preg_match\(''/\^\\d\{1,20\}\$/'',\s*\$id\)\s*!==\s*1[\s\S]*?Invalid upstream list item id' 'list item validator canonicalizes id aliases and requires a complete 1-to-20 digit JM id'
    foreach ($projection in @(
        @('''id''\s*=>\s*\$id', 'id'),
        @('''name''\s*=>\s*PayloadNormalizer::scalarString', 'name'),
        @('''author''\s*=>\s*PayloadNormalizer::scalarString', 'author'),
        @('''description''\s*=>\s*PayloadNormalizer::scalarString', 'description'),
        @('''image''\s*=>\s*PayloadNormalizer::scalarString', 'image'),
        @('\$normalized\[''tags''\]\s*=\s*self::normalizeStringListPayload', 'tags'),
        @('\$normalized\[''works''\]\s*=\s*self::normalizeStringListPayload', 'works'),
        @('\$normalized\[''actors''\]\s*=\s*self::normalizeStringListPayload', 'actors'),
        @('\$normalized\[''likes''\]\s*=\s*PayloadNormalizer::scalarInt', 'likes'),
        @('\$item\[''total_views''\]\s*\?\?\s*\$item\[''totalViews''\]', 'total_views alias'),
        @('\$item\[''updated_at''\][\s\S]*?\$item\[''update_at''\]', 'updated_at alias'),
        @('foreach\s*\(\s*\[''category'',\s*''category_sub''\]\s+as\s+\$field\s*\)', 'category projections'),
        @('return\s+\$normalized\s*;', 'canonical return')
    )) {
        Assert-Matches $listItemNormalizer $projection[0] "list item canonical projection includes $($projection[1])"
    }
    $projectionInitializer = [regex]::Match($listItemNormalizer, '(?s)\$normalized\s*=\s*\[(?<fields>.*?)\]\s*;')
    if (-not $projectionInitializer.Success) {
        throw 'List item normalizer must build a fresh canonical projection.'
    }
    $initializerFields = @(
        [regex]::Matches($projectionInitializer.Groups['fields'].Value, '(?m)^\s*''(?<field>[a-z_]+)''\s*=>') |
            ForEach-Object { $_.Groups['field'].Value }
    )
    $assignedFields = @(
        [regex]::Matches($listItemNormalizer, '(?m)^\s*\$normalized\[''(?<field>[a-z_]+)''\]\s*=') |
            ForEach-Object { $_.Groups['field'].Value }
    )
    $actualFields = @($initializerFields) + @($assignedFields)
    $expectedFields = @('id', 'name', 'author', 'description', 'image', 'tags', 'works', 'actors', 'likes', 'total_views', 'updated_at')
    if (($actualFields -join ',') -ne ($expectedFields -join ',')) {
        throw "List item canonical projection changed: $($actualFields -join ', ')"
    }
    $writeIndexes = @(
        [regex]::Matches($listItemNormalizer, '(?m)^\s*\$normalized\[(?<index>[^\]]+)\]\s*=') |
            ForEach-Object { $_.Groups['index'].Value }
    )
    $dynamicWrites = @($writeIndexes | Where-Object { $_ -notmatch '^''[a-z_]+''$' })
    if (($dynamicWrites -join ',') -ne '$field' -or
        $listItemNormalizer -notmatch 'foreach\s*\(\s*\[''category'',\s*''category_sub''\]\s+as\s+\$field\s*\)[\s\S]*?\$normalized\[\$field\]\s*=\s*\[\s*''title''\s*=>') {
        throw 'Optional category projection must allow only category/category_sub title.'
    }
    if ($listItemNormalizer -match 'return\s+\$item\s*;|\$normalized\s*=\s*\$item\b|\$normalized\s*\+=|array_(?:merge|replace)\s*\([^;]*\$item|\.\.\.\s*\$item') {
        throw 'List item canonical projection must not retain arbitrary upstream fields.'
    }
    foreach ($validator in @(
        @('isListItemsPayload', 'return\s+self::normalizeListItemsPayload\(\$payload\)\s*===\s*\$payload\s*;'),
        @('isPagedListPayload', 'return\s+self::normalizePagedListPayload\(\$payload,\s*\$itemsKey,\s*\$allowRedirect\)\s*===\s*\$payload\s*;'),
        @('isPromoteHomePayload', 'return\s+self::normalizePromoteHomePayload\(\$payload\)\s*===\s*\$payload\s*;')
    )) {
        $validatorBody = Get-PhpFunctionBlock $source $validator[0]
        Assert-Matches $validatorBody $validator[1] "$($validator[0]) rejects noncanonical cached payloads"
    }
    $pagedListNormalizer = Get-PhpFunctionBlock $source 'normalizePagedListPayload'
    $promoteHomeNormalizer = Get-PhpFunctionBlock $source 'normalizePromoteHomePayload'
    Assert-Matches $pagedListNormalizer 'array_key_exists\(\$itemsKey,\s*\$payload\)[\s\S]*?\$redirectAid[\s\S]*?\$totalRaw' 'paged payload validator requires list shape or a legal search redirect'
    Assert-Matches $promoteHomeNormalizer 'array_is_list\(\$payload\)[\s\S]*?array_key_exists\(''content'',\s*\$section\)[\s\S]*?normalizeListItemsPayload' 'promote-home validator requires normalized list sections'
    Assert-Matches $source 'fetchLatestList[\s\S]*?ENDPOINT_LATEST[\s\S]*?source_page[\s\S]*?JM_LIST_CACHE_TTL[\s\S]*?normalizeListItemsPayload' 'latest caches normalized endpoint/source_page arrays'
    Assert-Matches $source 'fetchPopularList[\s\S]*?ENDPOINT_CATEGORY_FILTER[\s\S]*?source_page[\s\S]*?category[\s\S]*?order[\s\S]*?JM_LIST_CACHE_TTL' 'popular cache key includes endpoint/source_page/category/order'
    Assert-Matches $source 'fetchPromoteList[\s\S]*?ENDPOINT_PROMOTE_LIST[\s\S]*?source_page[\s\S]*?section_id[\s\S]*?JM_LIST_CACHE_TTL' 'promote-list cache key includes endpoint/source_page/section_id'
    Assert-Matches $source 'fetchPromoteHomeList[\s\S]*?ENDPOINT_PROMOTE[\s\S]*?JM_LIST_CACHE_TTL[\s\S]*?normalizePromoteHomePayload' 'promote-home caches normalized endpoint sections'
    Assert-Matches $source 'searchAlbums[\s\S]*?upstream_page[\s\S]*?order[\s\S]*?query_sha256[\s\S]*?JM_SEARCH_CACHE_TTL' 'search cache key hashes normalized query and has independent TTL'
    Assert-Matches $source 'fetchWeeklyList[\s\S]*?ENDPOINT_WEEK_FILTER[\s\S]*?page[\s\S]*?category_id[\s\S]*?type_id[\s\S]*?JM_WEEKLY_LIST_CACHE_TTL' 'weekly cache key isolates page/category/type and has independent TTL'
    Assert-Matches $source 'sourceCacheStatus[\s\S]*?sourceCacheHits\s*>\s*0\s*&&\s*\$this->sourceCacheMisses\s*>\s*0[\s\S]*?''mixed''' 'multi-source requests report mixed cache status'
}

if ((Test-Area 'CacheMetadata') -or $Area -eq 'Cache') {
    foreach ($snippet in @(
        'JM_ALBUM_CACHE_TTL',
        'JM_WEEK_DEFAULTS_CACHE_TTL',
        'JM_WEEK_DEFAULTS_STALE_TTL',
        'DEFAULT_ALBUM_CACHE_TTL',
        'DEFAULT_WEEK_DEFAULTS_CACHE_TTL',
        'DEFAULT_WEEK_DEFAULTS_STALE_TTL',
        'album:v1',
        'week-defaults:v1',
        'metadata_cache',
        'stale_fallback_count'
    )) { Assert-Contains $source $snippet $snippet }
    Assert-Matches $source 'DEFAULT_ALBUM_CACHE_TTL\s*=\s*45\s*;' 'album cache default TTL is 45 seconds'
    Assert-Matches $source 'DEFAULT_WEEK_DEFAULTS_CACHE_TTL\s*=\s*600\s*;' 'weekly defaults fresh TTL is 600 seconds'
    Assert-Matches $source 'DEFAULT_WEEK_DEFAULTS_STALE_TTL\s*=\s*3600\s*;' 'weekly defaults stale TTL is 3600 seconds'
    Assert-Matches $source 'fetchAlbum[\s\S]*?JM_ALBUM_CACHE_TTL[\s\S]*?album:v1:[\s\S]*?hash\(''sha256'',\s*\$canonicalAlbumId\)[\s\S]*?normalizeAlbumPayload[\s\S]*?\$resp\s*=\s*\[''data''\s*=>\s*\$payload\][\s\S]*?JmAlbum::fromApiResponse\(\$resp\[''data''\],\s*\$jmid\)' 'album cache uses a SHA-256 ID key, normalized arrays, and reconstructs JmAlbum per request'
    Assert-Matches $source 'normalizeAlbumPayload[\s\S]*?Invalid upstream album payload[\s\S]*?Invalid upstream album id[\s\S]*?Invalid upstream album name' 'album payload validation rejects malformed critical fields'
    Assert-Matches $source 'normalizeAlbumPayload[\s\S]*?\$rawSeries\s*!==\s*null[\s\S]*?array_is_list\(\$rawSeries\)[\s\S]*?Invalid upstream album series item[\s\S]*?\^\\d\{1,20\}\$[\s\S]*?Invalid upstream album series id' 'album permits absent series but rejects every malformed present series item atomically'
    Assert-Matches $source 'metadataCacheThroughArray[\s\S]*?is_array\(\$cached\)[\s\S]*?produceValidatedArray[\s\S]*?\$this->cache->set\(\$key,\s*\$value,\s*\$ttl\)' 'metadata cache only stores producer arrays after validation'
    Assert-Matches $source 'fetchWeekDefaults[\s\S]*?JM_WEEK_DEFAULTS_CACHE_TTL[\s\S]*?JM_WEEK_DEFAULTS_STALE_TTL[\s\S]*?fresh_until[\s\S]*?stale_until' 'weekly defaults implement bounded stale-if-error'
    Assert-Matches $source 'normalizeWeekDefaultsPayload[\s\S]*?preg_match\(''/\^\\d\{1,20\}\$/''[\s\S]*?Weekly defaults unavailable' 'weekly defaults enforce the public numeric ID contract before caching'
    Assert-Matches $source 'validatedWeekDefaultsEntry[\s\S]*?count\(\$candidate\)\s*!==\s*4[\s\S]*?\$candidate\[''value''\][\s\S]*?\$candidate\[''fetched_at''\][\s\S]*?\$candidate\[''fresh_until''\][\s\S]*?\$candidate\[''stale_until''\]' 'weekly defaults entry rejects every extra top-level envelope key'
    Assert-Matches $source 'if\s*\(\$freshTtl\s*<=\s*0\s*\|\|\s*!\$this->cache->isAvailable\(\)\)[\s\S]*?produceWeekDefaults\(\)' 'weekly fresh TTL zero and unavailable APCu bypass all cache state'
    Assert-Matches $source '\$now\s*<\s*\$entry\[''fresh_until''\][\s\S]*?return\s+\$entry\[''value''\]' 'weekly fresh entries avoid upstream refreshes'
    Assert-Matches $source '\$staleTtl\s*>\s*0[\s\S]*?\$now\s*<=\s*\$entry\[''stale_until''\][\s\S]*?recordWeekDefaultsStaleFallback' 'weekly stale fallback is enabled explicitly and bounded inclusively'
    Assert-Matches $source '\$freshTtl\s*\+\s*\$staleTtl[\s\S]*?fresh_until[\s\S]*?stale_until' 'weekly physical TTL and logical freshness windows are derived from configured TTLs'
    Assert-Matches $source 'week-defaults-fill-lease:[\s\S]*?tryAdd[\s\S]*?remainingMs\(\)[\s\S]*?finally[\s\S]*?compareAndDelete' 'weekly refresh uses a bounded token-owned lease under the request deadline'
    $weekDefaultsFunction = [regex]::Match(
        $source,
        'private function fetchWeekDefaults\(\): array\s*\{(?<body>[\s\S]*?)\r?\n    \}\r?\n\r?\n    public function searchAlbums'
    ).Groups['body'].Value
    if ([string]::IsNullOrWhiteSpace($weekDefaultsFunction)) {
        throw 'Missing performance policy contract: fetchWeekDefaults function body'
    }
    $weekLoser = [regex]::Match(
        $weekDefaultsFunction,
        '\$waitStartedNs\s*=\s*hrtime\(true\);(?<body>[\s\S]*?)\$token\s*=\s*random_int\(1,\s*PHP_INT_MAX\);\s*if\s*\(\$this->cache->tryAdd\(\$leaseKey,\s*\$token,\s*\$lockTtl\)\)\s*return\s*\$refreshAsOwner\(\$token\);'
    ).Groups['body'].Value
    if ([string]::IsNullOrWhiteSpace($weekLoser)) {
        throw 'Missing performance policy contract: weekly refresh loser path'
    }
    if ($weekLoser -match 'canUseStaleWeekDefaults|recordWeekDefaultsStaleFallback') {
        throw 'Weekly refresh loser must reacquire the lease instead of inferring a stale-eligible exception.'
    }
    if ($weekLoser -notmatch 'get\(\$leaseKey\)\s*===\s*null\)\s*\{\s*break;\s*\}') {
        throw 'Weekly refresh loser must retry ownership after the prior lease disappears.'
    }
    Assert-Matches $source 'metadata_cache[\s\S]*?fresh_ttl_seconds[\s\S]*?stale_ttl_seconds[\s\S]*?entry_status[\s\S]*?stale_fallback_count' 'health exposes low-cardinality metadata cache diagnostics'
    Assert-Matches $source 'metadataCacheDiagnostics[\s\S]*?elseif\s*\(\$staleTtl\s*>\s*0\s*&&\s*\$now\s*<=\s*\$entry\[''stale_until''\]\)' 'health never labels a stale-disabled weekly entry as stale'
    Assert-Matches $source 'runtimeDiagnostics\(MemoryCache\s+\$cache,\s*\?RequestContext\s+\$context[\s\S]*?metadataCacheDiagnostics' 'health diagnostics inspect metadata state without constructing JmService'
    Assert-Matches $source 'weekDefaultsCacheKey[\s\S]*?''endpoint''\s*=>\s*JmConfig::ENDPOINT_WEEK[\s\S]*?''schema''\s*=>\s*''category-type-defaults''[\s\S]*?\$namespace\s*\.\s*''week-defaults:v1:''\s*\.\s*hash\(''sha256'',\s*json_encode\([\s\S]*?canonicalCacheValue\(\$fields\)' 'weekly defaults key uses canonical endpoint/schema fields behind a versioned SHA-256 key'
    Assert-Matches $source 'fetchWeekDefaults[\s\S]*?\$key\s*=\s*self::weekDefaultsCacheKey\(\$this->cacheNamespace\)' 'weekly defaults fetch uses the shared namespaced key builder'
    Assert-Matches $source 'metadataCacheDiagnostics[\s\S]*?\$rawEntry\s*=\s*\$cache->get\(self::weekDefaultsCacheKey\(\$namespace\)\)' 'health inspects the same weekly defaults key without upstream access'
    Assert-Matches $faultRuntime 'if\s*\(-not\s+\$SkipComposeUp\)\s*\{[\s\S]*?docker compose[\s\S]*?--force-recreate[\s\S]*?\}' 'initial Docker compose mutation is guarded by SkipComposeUp'
    Assert-Matches $faultRuntime 'if\s*\(\$SkipComposeUp\)\s*\{\s*Write-Output\s+''Skipping domain-source timeout scenario[^'']*''\s*\}\s*else\s*\{[\s\S]*?\$dockerEnvironmentNames\s*=\s*@\([\s\S]*?JM_TEST_API_BASE_URLS[\s\S]*?JM_TEST_DOMAIN_SOURCE_URLS[\s\S]*?JM_TEST_FALLBACK_API_BASE_URLS' 'SkipComposeUp explicitly skips the domain scenario before any environment or compose mutation'
    Assert-Matches $faultRuntime '\$savedDockerEnvironment\[\$name\]\s*=\s*\[Environment\]::GetEnvironmentVariable\(\$name,\s*''Process''\)[\s\S]*?finally[\s\S]*?\[Environment\]::SetEnvironmentVariable\(\$name,\s*\$savedDockerEnvironment\[\$name\],\s*''Process''\)' 'Docker domain scenario snapshots and restores caller environment values'
    Assert-Matches $faultRuntime '\$savedDockerEnvironment\[\$name\]\s*=\s*\[Environment\]::GetEnvironmentVariable\(\$name,\s*''Process''\)\s*\r?\n\s*\}\s*\r?\n\s*try\s*\{\s*\r?\n\s*\$env:JM_TEST_API_BASE_URLS\s*=\s*''disabled''\s*\r?\n\s*\$env:JM_TEST_DOMAIN_SOURCE_URLS[\s\S]*?\$env:JM_TEST_FALLBACK_API_BASE_URLS' 'Docker temporary environment assignment is covered by the restoring try/finally'
    if ($faultRuntime -match 'Remove-Item\s+Env:JM_TEST_(?:API_BASE_URLS|DOMAIN_SOURCE_URLS|FALLBACK_API_BASE_URLS)') {
        throw 'Docker fault runtime must restore caller environment values instead of deleting them.'
    }
    if ($source -match 'cache->set\([^\n]*new\s+JmAlbum|cache->set\([^\n]*JmAlbum::fromApiResponse') {
        throw 'JmAlbum objects must never enter metadata cache storage.'
    }
}

if (Test-Area 'Prefetch') {
    foreach ($snippet in @(
        'JM_PREFETCH_WALL_BUDGET_MS',
        'JM_PREFETCH_BYTE_BUDGET',
        'JM_PREFETCH_MAX_ACTIVE',
        'prefetch-page-lease:v1',
        'prefetch-slot:',
        'jmapi-prefetch-mutation-lock-v1',
        'skipped-pages-covered',
        'skipped-busy',
        'budget-attempts',
        'final class PrefetchCoordinator',
        'compareAndRefresh',
        'withSecondaryCap',
        '''upstream_bytes''',
        '''prefetch_manifest'''
    )) { Assert-Contains $source $snippet $snippet }
    Assert-Matches $source 'DEFAULT_PREFETCH_WALL_BUDGET_MS\s*=\s*5000' 'prefetch wall budget defaults to 5000 ms'
    Assert-Matches $source 'DEFAULT_PREFETCH_BYTE_BUDGET\s*=\s*16777216' 'prefetch byte budget defaults to 16 MiB'
    Assert-Matches $source 'DEFAULT_PREFETCH_MAX_ACTIVE\s*=\s*2' 'prefetch global active slots default to two'
    Assert-Matches $source 'leaseTtlSeconds[\s\S]*?scheduleDelayMs[\s\S]*?wallBudgetMs[\s\S]*?LEASE_SAFETY_MARGIN_MS[\s\S]*?min\(300,\s*max\(5' 'lease TTL covers delay, wall budget, safety margin, and clamps to 5..300'
    Assert-Matches $source 'interface\s+PrefetchLeaseStore[\s\S]*?tryAcquire[\s\S]*?owns[\s\S]*?compareAndRefresh[\s\S]*?release' 'prefetch lease store exposes ownership-specific acquire, verify, refresh, and release operations'
    Assert-Matches $source 'PREFETCH_AUTHORITY_LOCK_SHARDS\s*=\s*256' 'prefetch authority flock uses a fixed 256-shard global bound'
    Assert-Matches $source 'tryAcquire[\s\S]*?acquirePrefetchAuthority[\s\S]*?prefetchAuthorityByKey' 'prefetch acquisition retains authoritative ownership beyond the APCu mirror operation'
    Assert-Matches $source 'acquirePrefetchAuthority[\s\S]*?sys_get_temp_dir\(\)[\s\S]*?fopen\([^\r\n]*''c\+b''[\s\S]*?LOCK_EX\s*\|\s*LOCK_NB' 'prefetch authority acquires one bounded non-blocking flock shard'
    Assert-Matches $source 'prefetchAuthorityShard[\s\S]*?hash\(''sha256'',\s*\$this->prefix\s*\.\s*\$key,\s*true\)[\s\S]*?ord\(\$digest\[0\]\)' 'prefetch authority hashes the complete APCu key and maps its first byte to a shard'
    Assert-Matches $source 'releasePrefetchAuthority[\s\S]*?LOCK_UN[\s\S]*?fclose' 'last shard refcount releases and closes authoritative flock'
    Assert-Matches $source '__destruct[\s\S]*?releaseAllPrefetchAuthorities' 'cache destruction releases every retained prefetch authority handle'
    if ($source -match 'apcu_add\([^\r\n]*prefetch-mutation-lock') {
        throw 'Prefetch mutation mutex must not depend on an expiring APCu entry.'
    }
    $prefetchCoordinatorSource = [regex]::Match($source, 'final class PrefetchCoordinator[\s\S]*?(?=final class PrefetchTestObserver)').Value
    if ($prefetchCoordinatorSource -match '\$this->cache->(?:get|tryAdd|compareAndDelete)\(') {
        throw 'PrefetchCoordinator must use only the ownership-specific lease API.'
    }
    Assert-Matches $source 'candidateLeaseKey[\s\S]*?''schema''[\s\S]*?''photo_id''[\s\S]*?''page''' 'page lease identity is canonical schema/photo/page'
    Assert-Matches $source 'for\s*\(\$index\s*=\s*0;\s*\$index\s*<\s*\$maxActive[\s\S]*?tryAcquire\(\$key,\s*\$token,\s*\$leaseTtl\)' 'global slots use atomic fixed-slot acquisition'
    Assert-Matches $source '\$callback\s*=\s*function[\s\S]*?finally[\s\S]*?releaseClaims[\s\S]*?release\(\$slot' 'prefetch callback releases every lease and slot in finally'
    Assert-Matches $source 'maybePrefetchPages[\s\S]*?new\s+PrefetchCoordinator[\s\S]*?\$coordinator->schedule[\s\S]*?PrefetchCoordinator::planCandidates' 'service plans from the known manifest and delegates scheduling'
    Assert-Matches $source 'withSecondaryCap\(\s*\$remainingMs[\s\S]*?fetchDecodedPage' 'prefetch only shortens the existing upstream budget'
    Assert-Matches $source 'catch\s*\(UpstreamBudgetExhaustedException\s+\$error\)[\s\S]*?prefetchSkipReason\(\)[\s\S]*?catch\s*\(\\Throwable\)' 'typed upstream budget exhaustion is classified before generic executor failures'
    Assert-Contains $performanceEvidence "'budget-attempts'" 'performance evidence accepts typed attempt-budget exhaustion'
    $realFailurePrecedenceChecks = [regex]::Matches(
        $source,
        'if\s*\(\$budgetDenied\s*&&\s*\$lastFailure\s*===\s*null\)\s*throw\s+\$this->budgetExhaustedException\(\)'
    )
    if ($realFailurePrecedenceChecks.Count -ne 2) {
        throw 'CallJson and image download must only classify a denied attempt as budget exhaustion when no real upstream failure exists.'
    }
    foreach ($snippet in @(
        'deadline denial after a real transport failure preserves that failure',
        'attempt denial after a real upstream failure is not reclassified as budget exhaustion',
        'image retry denial after a real upstream failure is not reclassified as budget exhaustion'
    )) { Assert-Contains $upstreamRuntime $snippet "upstream budget failure precedence: $snippet" }

    $phpReasonsMatch = [regex]::Match(
        $source,
        'public\s+const\s+SKIP_REASONS\s*=\s*\[(?<body>[\s\S]*?)\];'
    )
    $evidenceReasonsMatch = [regex]::Match(
        $performanceEvidence,
        '\$allowedReasons\s*=\s*@\((?<body>[\s\S]*?)\)'
    )
    if (-not $phpReasonsMatch.Success -or -not $evidenceReasonsMatch.Success) {
        throw 'Unable to extract complete PHP and PowerShell prefetch skip-reason sets.'
    }
    $phpReasons = @([regex]::Matches($phpReasonsMatch.Groups['body'].Value, "'(?<reason>[^']+)'") | ForEach-Object { $_.Groups['reason'].Value })
    $evidenceReasons = @([regex]::Matches($evidenceReasonsMatch.Groups['body'].Value, "'(?<reason>[^']+)'") | ForEach-Object { $_.Groups['reason'].Value })
    if (($phpReasons | Select-Object -Unique).Count -ne $phpReasons.Count -or
        ($evidenceReasons | Select-Object -Unique).Count -ne $evidenceReasons.Count
    ) {
        throw 'Prefetch skip-reason sets must not contain duplicate values.'
    }
    $phpReasonSet = @($phpReasons | Sort-Object -CaseSensitive)
    $evidenceReasonSet = @($evidenceReasons | Sort-Object -CaseSensitive)
    if (($phpReasonSet -join "`n") -cne ($evidenceReasonSet -join "`n")) {
        throw "PHP and performance-evidence prefetch skip-reason sets differ.`nPHP: $($phpReasonSet -join ', ')`nEvidence: $($evidenceReasonSet -join ', ')"
    }
    Assert-Matches $source 'singleFlight[\s\S]*?JM_SINGLEFLIGHT_WAIT_MS[\s\S]*?budget\(\)->remainingMs\(\)' 'single-flight wait obeys the active secondary deadline'
    Assert-Matches $source 'maybePrefetchPages[\s\S]*?budget\(\)->remainingMs\(\)[\s\S]*?10000' 'schedule delay conservatively covers request remainder and prior deferred refresh'
    Assert-Matches $source 'isPrefetchEnabled[\s\S]*?prefetch' 'prefetch=0 remains supported'
    foreach ($snippet in @(
        'stats have the exact seven-field shape',
        'exits before candidate generation',
        'busy cleanup releases the first page claim',
        'high-priority candidates execute before low-priority candidates',
        'old-owner cleanup cannot delete a replacement page token',
        'a callback delayed beyond lease ownership validates its token and executes zero pages',
        'a rival cannot acquire a physically expired mirror while the owner holds its authority flock',
        'a flock held beyond five seconds still blocks a rival owner',
        'closing a lock handle recovers ownership without a TTL',
        'terminating a lock-holder process releases authority without TTL recovery',
        'refresh store failure prevents every executor call',
        'a busy page authority flock prevents every executor call',
        'a busy slot authority flock prevents every executor call',
        'APCu expunge cannot transfer a page authority flock to a rival',
        'APCu expunge cannot transfer the global slot authority flock to a rival',
        'stats sink exceptions cannot prevent callback cleanup',
        'schedule-time ownership sink exceptions cannot block callback registration',
        'callback-time ownership sink exceptions cannot block executor work or cleanup',
        'ownership sink exception cleanup permits a rival owner',
        'attempt budget exhaustion has a fixed reason',
        'attempt budget exhaustion releases every authority handle',
        'crash-test marker cleanup leaves no residual file',
        'observer cleanup leaves no residual directory',
        'secondary work continues the original attempt counter'
    )) { Assert-Contains $prefetchRuntime $snippet "prefetch runtime coverage: $snippet" }
    foreach ($pattern in @(
        'JM_PREFETCH_WALL_BUDGET_MS:\s*"\$\{JM_PREFETCH_WALL_BUDGET_MS:-5000\}"',
        'JM_PREFETCH_BYTE_BUDGET:\s*"\$\{JM_PREFETCH_BYTE_BUDGET:-16777216\}"',
        'JM_PREFETCH_MAX_ACTIVE:\s*"\$\{JM_PREFETCH_MAX_ACTIVE:-2\}"'
    )) { Assert-Matches $compose $pattern "compose prefetch default and override: $pattern" }
    Assert-Matches $testCompose 'PHP_CLI_SERVER_WORKERS:\s*"12"' 'test compose provisions enough CLI workers for concurrency observation'
    Assert-Contains $testCompose 'JM_TEST_PREFETCH_STATS_DIR' 'test compose shares direct prefetch owner observations'
    Assert-Matches $testCompose 'chmod\s+0777\s+/tmp/jm-fixture-stats[\s\S]*?umask\s+000[\s\S]*?exec\s+php\s+-S' 'fixture startup makes the shared stats volume writable before readiness'
    Assert-Contains $fixture 'prefetch-page-owner-acquire' 'fixture stats expose direct page-owner acquisition'
    Assert-Contains $fixture 'prefetch-slot-peak' 'fixture stats expose direct slot peak'
    Assert-Contains $fixture 'prefetch-media|' 'fixture stats distinguish actual prefetch media attempts from direct client downloads'
    Assert-Matches $fixture '\$locked\s*=\s*flock\([\s\S]*?if\s*\(!\$locked\)' 'fixture checks flock acquisition results'
    Assert-Matches $fixture 'if\s*\(!ftruncate\(\$fp,\s*0\)[\s\S]*?\$written\s*=\s*fwrite\([\s\S]*?\$written\s*===\s*false' 'fixture checks truncate and write results'
    Assert-Matches $fixture 'fopen\(\$path,\s*''c\+''\)[\s\S]*?chmod\(\$path,\s*0666\)' 'fixture makes shared owner stats writable by the unprivileged API container'
    Assert-Matches $fixture 'statsPath[\s\S]*?chmod\(\$directory,\s*0777\)' 'fixture makes the shared stats directory writable before API owner events'
    Assert-Contains $faultRuntime 'prefetch_owners' 'fault runtime uses direct owner observations'
    Assert-Contains $faultRuntime 'prefetch slots were still held after callbacks completed' 'fault runtime asserts final held slots are zero'
    foreach ($snippet in @(
        'BarrierSelfTest',
        'New-ControlledConcurrentJobBarrier',
        'Wait-ConcurrentJobBarrierReady',
        'Release-ConcurrentJobBarrier',
        'Stop-AndRemoveConcurrentJobs',
        'Remove-ControlledConcurrentJobBarrier',
        'Invoke-ConcurrentJobBarrierSelfTest',
        'Concurrent job barrier self-test passed.'
    )) { Assert-Contains $faultRuntime $snippet "concurrent job barrier: $snippet" }
    Assert-Matches $faultRuntime 'Release-ConcurrentJobBarrier[\s\S]*?release-[^\r\n]*\.tmp[\s\S]*?WriteAllText\(\$temporaryPath,\s*\$Barrier\.Token[\s\S]*?\[IO\.File\]::Move\(\$temporaryPath,\s*\$Barrier\.ReleasePath\)[\s\S]*?finally[\s\S]*?Remove-Item\s+-LiteralPath\s+\$temporaryPath' 'barrier release is fully written before an atomic same-directory publication and cleans failed temporary files'
    Assert-Contains $faultRuntime 'Concurrent barrier self-test rejects a foreign release token.' 'barrier self-test proves foreign release tokens fail closed'
    Assert-Matches $faultRuntime '\$releaseWait\s*=\s*\[Diagnostics\.Stopwatch\]::StartNew\(\)[\s\S]*?\$releaseWait\.ElapsedMilliseconds\s*-ge\s*\$ReleaseTimeoutMs' 'barrier release timeout uses monotonic elapsed time'
    Assert-Matches $faultRuntime '\$readyWait\s*=\s*\[Diagnostics\.Stopwatch\]::StartNew\(\)[\s\S]*?\$readyWait\.ElapsedMilliseconds\s*-ge\s*\$ReadyTimeoutMs' 'barrier ready timeout uses monotonic elapsed time'
    if ($faultRuntime -match '\[DateTime\]::UtcNow\.AddMilliseconds\(') {
        throw 'Concurrent barrier timeouts must not use wall-clock DateTime deadlines.'
    }
    Assert-Matches $faultRuntime '\$apiProcess\s*=\s*if\s*\(\$LocalMetadataCache\)[\s\S]*?&\s*\$startApi\s+60\s+60\s+0\s+300\s+2\s+3' 'metadata harness uses a test-only 300-second album TTL'
    Assert-Matches $faultRuntime '\$albumBarrier\s*=\s*New-ControlledConcurrentJobBarrier[\s\S]*?-JobCount\s+10[\s\S]*?\$albumBarrier\.ReadyPaths\[\$jobIndex\][\s\S]*?Wait-ConcurrentJobBarrierReady\s+-Barrier\s+\$albumBarrier\s+-Jobs\s+\$jobs[\s\S]*?Release-ConcurrentJobBarrier\s+-Barrier\s+\$albumBarrier[\s\S]*?finally\s*\{[\s\S]*?Stop-AndRemoveConcurrentJobs[\s\S]*?Remove-ControlledConcurrentJobBarrier' 'metadata clients use an all-ready/release barrier with strict finally cleanup'
    Assert-Matches $faultRuntime '\$imageBarrier\s*=\s*New-ControlledConcurrentJobBarrier[\s\S]*?-JobCount\s+10[\s\S]*?\$imageBarrier\.ReadyPaths\[\$jobIndex\][\s\S]*?Wait-ConcurrentJobBarrierReady\s+-Barrier\s+\$imageBarrier\s+-Jobs\s+\$imageJobs[\s\S]*?Release-ConcurrentJobBarrier\s+-Barrier\s+\$imageBarrier[\s\S]*?finally\s*\{[\s\S]*?Stop-AndRemoveConcurrentJobs[\s\S]*?Remove-ControlledConcurrentJobBarrier' 'Docker prefetch clients use the same all-ready/release barrier and strict cleanup'
    $dockerAlbumMatch = [regex]::Match(
        $faultRuntime,
        '(?s)# Album single-flight: deterministic fixture count must be exactly one\.(?<block>.*?)# Error responses must retain safe request diagnostics\.'
    )
    if (-not $dockerAlbumMatch.Success) {
        throw 'Missing bounded Docker album single-flight contract section.'
    }
    $dockerAlbumBlock = $dockerAlbumMatch.Groups['block'].Value
    Assert-Matches $dockerAlbumBlock '\$dockerAlbumBarrier\s*=\s*New-ControlledConcurrentJobBarrier\s+-JobCount\s+10[\s\S]*?for\s*\(\$jobIndex\s*=\s*0;\s*\$jobIndex\s*-lt\s*10;\s*\$jobIndex\+\+\)[\s\S]*?Start-Job\s+-ScriptBlock\s+\$script:ConcurrentRequestJob[\s\S]*?valid-album[\s\S]*?\$dockerAlbumBarrier\.ReadyPaths\[\$jobIndex\][\s\S]*?\$dockerAlbumBarrier\.ReleasePath[\s\S]*?\$dockerAlbumBarrier\.Token[\s\S]*?\$dockerAlbumBarrier\.ReleaseTimeoutMs[\s\S]*?30' 'Docker album starts ten bounded clients on its own all-ready barrier'
    Assert-Matches $dockerAlbumBlock 'Wait-ConcurrentJobBarrierReady\s+-Barrier\s+\$dockerAlbumBarrier\s+-Jobs\s+\$dockerAlbumJobs[\s\S]*?Release-ConcurrentJobBarrier\s+-Barrier\s+\$dockerAlbumBarrier[\s\S]*?\$dockerAlbumStatuses\s*=\s*@\(\$dockerAlbumJobs\s*\|\s*Receive-Job\s+-Wait\s+-ErrorAction\s+Stop\)' 'Docker album releases only after all clients are ready and receives every bounded request'
    Assert-Matches $dockerAlbumBlock 'finally\s*\{[\s\S]*?Stop-AndRemoveConcurrentJobs\s+-Jobs\s+\$dockerAlbumJobs[\s\S]*?Remove-ControlledConcurrentJobBarrier\s+-Barrier\s+\$dockerAlbumBarrier' 'Docker album always removes jobs and barrier artifacts'
    Assert-Matches $dockerAlbumBlock 'Assert-True\s*\(\$dockerAlbumStatuses\.Count\s*-eq\s*10\)[\s\S]*?Assert-True\s*\(\(\$dockerAlbumStatuses\s*\|\s*Where-Object\s*\{\s*\$_\s*-ne\s*200\s*\}\)\.Count\s*-eq\s*0\)[\s\S]*?Count-Key\s+\$counts\s+''api-good\|/album\|\|valid-album''\)\s*-eq\s*1' 'Docker album retains exactly ten HTTP 200 results and exact-one upstream assertion'
    $tenClientBarrierCount = [regex]::Matches($faultRuntime, 'New-ControlledConcurrentJobBarrier\s+-JobCount\s+10').Count
    if ($tenClientBarrierCount -ne 3) {
        throw "Fault runtime must have exactly three explicit ten-client barriers (metadata, Docker album, Docker prefetch); got $tenClientBarrierCount."
    }
    foreach ($legacyPattern in @(
        '\$albumStartTicks\b',
        '\$imageStartTicks\b',
        '\bStartAtTicks\b',
        'Receive-Job\s+-Wait\s+-AutoRemoveJob\b'
    )) {
        if ($faultRuntime -match $legacyPattern) {
            throw "Fault runtime retains legacy unsynchronized concurrent-job code matching: $legacyPattern"
        }
    }
    Assert-Matches $faultRuntime 'ReadyTimeoutMs[\s\S]*?ReleaseTimeoutMs' 'barrier readiness and release waits are both bounded'
    Assert-Matches $faultRuntime 'Assert-True\s*\(\[int\]\s*\$owners\.slots\.peak\s*-eq\s*2\)' 'Docker overlap requires both configured prefetch slots to overlap'
    if ($faultRuntime -match '\$owners\.slots\.peak\s*-ge\s*1') {
        throw 'Docker prefetch overlap must not accept a serial peak of one.'
    }
    Assert-Matches $fixture 'if\s*\(\$uriPath\s*===\s*''/__reset''\)[\s\S]*?if\s*\(!@unlink\(\$path\)\)\s*\{\s*sendJsonResponse\([^\r\n]*500' 'fixture reset suppresses only the unlink warning while checking failure and emitting JSON 500'
    if ($fixture -match '!\s*unlink\(') {
        throw 'Fixture reset must not permit an unsuppressed unlink warning to send headers early.'
    }
    Assert-Contains $faultRuntime 'FixtureResetSelfTest' 'fixture reset failure has an executable local test mode'
    Assert-Contains $faultRuntime 'Invoke-FixtureResetFailureSelfTest' 'fixture reset failure test is callable without Docker or the production API'
    Assert-Matches $faultRuntime '\[IO\.File\]::Open\([^\r\n]*\[IO\.FileShare\]::None\)' 'fixture reset failure test holds the stats file with a Windows-exclusive handle'
    $fixtureResetSelfTestMatch = [regex]::Match(
        $faultRuntime,
        '(?s)function Invoke-FixtureResetFailureSelfTest\s*\{(?<block>.*?)\r?\n\}\r?\n\r?\nif \(\$BarrierSelfTest\)'
    )
    if (-not $fixtureResetSelfTestMatch.Success) {
        throw 'Fixture reset failure self-test must be a bounded standalone test function.'
    }
    $fixtureResetSelfTestBlock = $fixtureResetSelfTestMatch.Groups['block'].Value
    Assert-Contains $fixtureResetSelfTestBlock 'tests\fixtures\upstream-router.php' 'fixture reset failure self-test starts only the fixture router'
    if ($fixtureResetSelfTestBlock -match '(?i)index\.php|\$BaseUrl|docker\s+compose') {
        throw 'Fixture reset failure self-test must not start or call the production API or Docker.'
    }
    Assert-Matches $faultRuntime 'if\s*\(\$FixtureResetSelfTest\)\s*\{\s*Invoke-FixtureResetFailureSelfTest\s+return\s*\}[\s\S]*?function Invoke-BootstrapDiagnosticsSelfTest[\s\S]*?if\s*\(\$BootstrapDiagnosticsSelfTest\)[\s\S]*?return[\s\S]*?function Invoke-LocalListCacheVerification' 'fixture reset and bootstrap self-tests return before any production API or Docker verification path'
    foreach ($snippet in @(
        'fixture reset under an exclusive Windows file lock must return HTTP 500',
        'fixture reset unlink failure must return JSON ok=false',
        'fixture reset must succeed after releasing the file lock',
        'Fixture reset failure self-test passed.',
        'locked_http=',
        'released_http='
    )) { Assert-Contains $faultRuntime $snippet "fixture reset runtime behavior: $snippet" }
}

if (Test-Area 'Resources') {
    Assert-Contains $baseline "`$scriptVersion = '2026.07.16.3'" 'performance measurement schema version'
    Assert-Matches $runtime '\[void\]\s*\$stream\.CopyToAsync\(\[System\.IO\.Stream\]::Null\)\.GetAwaiter\(\)\.GetResult\(\)' 'runtime image stream copy suppresses the completed task result'
    if (-not (Test-Path -LiteralPath $performanceEvidencePath -PathType Leaf)) {
        throw 'Missing performance policy contract: scripts\performance-evidence.ps1 pure evidence helper.'
    }
    . $performanceEvidencePath
    if ($null -eq (Get-Command Resolve-PrefetchAttributionEvidence -CommandType Function -ErrorAction SilentlyContinue)) {
        throw 'Missing performance policy contract: Resolve-PrefetchAttributionEvidence function.'
    }
    function New-PrefetchAggregateContractValue {
        param([int64] $Events = 0, [int64] $Scheduled = 0, [int64] $Attempted = 0,
            [int64] $CacheHits = 0, [int64] $Stored = 0, [int64] $Bytes = 0,
            [int64] $WallMs = 0, $SkipCounts = $null)
        if ($null -eq $SkipCounts) { $SkipCounts = [pscustomobject]@{} }
        return [pscustomobject]@{
            events = $Events; scheduled = $Scheduled; attempted = $Attempted
            cache_hits = $CacheHits; stored = $Stored; bytes = $Bytes; wall_ms = $WallMs
            skip_counts = $SkipCounts
        }
    }
    $zeroAggregate = New-PrefetchAggregateContractValue -SkipCounts ([object[]]@())
    $oneEventAggregate = New-PrefetchAggregateContractValue -Events 1 -Scheduled 1 -Attempted 3 -Stored 2 -Bytes 2048 -WallMs 17 -SkipCounts ([object[]]@())
    $validSkippedEvent = New-PrefetchAggregateContractValue -Events 1 -Scheduled 1 -Attempted 3 -Stored 2 -Bytes 2048 -WallMs 17 `
        -SkipCounts ([pscustomobject]@{ 'budget-wall' = 1 })
    $validSkippedEvidence = Resolve-PrefetchAttributionEvidence `
        -MeasurementRunIdGenerated $true -TestCacheScoped $true -TriggerSucceeded $true `
        -AggregateBefore $zeroAggregate -AggregateAfter $validSkippedEvent -FollowupHits 1
    if (-not $validSkippedEvidence.attribution_verified -or
        $validSkippedEvidence.utilization_ratio -ne 0.5 -or
        $validSkippedEvidence.waste_ratio -ne 0.5
    ) {
        throw 'Pure prefetch attribution helper rejected a complete event with a valid bounded skip counter.'
    }
    foreach ($invalidSkip in @(
        [pscustomobject]@{ name = 'unknown corrupt key/value'; value = [pscustomobject]@{ busy = 'corrupt' } },
        [pscustomobject]@{ name = 'negative counter'; value = [pscustomobject]@{ 'skipped-busy' = -1 } },
        [pscustomobject]@{ name = 'single counter exceeds completed events'; value = [pscustomobject]@{ 'budget-wall' = 2 } },
        [pscustomobject]@{ name = 'two skip counters for one event'; value = [pscustomobject]@{ 'budget-wall' = 1; 'budget-bytes' = 1 } },
        [pscustomobject]@{ name = 'zero-valued present counter'; value = [pscustomobject]@{ 'budget-wall' = 0 } },
        [pscustomobject]@{ name = 'null counter'; value = [pscustomobject]@{ 'skipped-busy' = $null } },
        [pscustomobject]@{ name = 'nested counter'; value = [pscustomobject]@{ 'skipped-busy' = [pscustomobject]@{ count = 1 } } },
        [pscustomobject]@{ name = 'nonempty array'; value = [object[]]@('skipped-busy') }
    )) {
        $invalidAfter = New-PrefetchAggregateContractValue -Events 1 -Stored 2 -SkipCounts $invalidSkip.value
        $invalidEvidence = Resolve-PrefetchAttributionEvidence `
            -MeasurementRunIdGenerated $true -TestCacheScoped $true -TriggerSucceeded $true `
            -AggregateBefore $zeroAggregate -AggregateAfter $invalidAfter -FollowupHits 1
        if ($invalidEvidence.attribution_verified -or
            $null -ne $invalidEvidence.utilization_ratio -or
            $null -ne $invalidEvidence.waste_ratio -or
            $invalidEvidence.reason -ne 'final-aggregate-invalid'
        ) {
            throw "Pure prefetch attribution helper accepted invalid final skip_counts: $($invalidSkip.name)."
        }
    }

    if ($null -eq (Get-Command Resolve-PerformanceComparisonEvidence -CommandType Function -ErrorAction SilentlyContinue)) {
        throw 'Missing performance policy contract: Resolve-PerformanceComparisonEvidence function.'
    }
    $comparisonEqualityEnvironmentFields = @(
        'base_url', 'album_id', 'chapter_id', 'warmup_iterations', 'iterations', 'concurrency',
        'script_version', 'script_sha256', 'asserted_runtime_kind', 'asserted_runtime_source_binding',
        'asserted_actual_worker_count', 'request_timeout_seconds', 'runtime_apcu_total_memory_bytes',
        'powershell', 'php_version', 'asserted_network_condition_id', 'asserted_resource_profile_id',
        'runtime_prefetch_policy_sha256', 'runtime_cache_policy_sha256'
    )
    $comparisonRequiredEnvironmentFields = @(
        'base_url', 'album_id', 'chapter_id', 'powershell', 'warmup_iterations', 'iterations', 'concurrency',
        'script_version', 'script_sha256', 'local_source_performance_evidence_sha256',
        'local_source_compose_sha256', 'local_source_index_sha256', 'local_source_dockerfile_sha256',
        'local_source_entrypoint_sha256', 'asserted_runtime_kind', 'asserted_runtime_source_binding',
        'asserted_runtime_image_digest', 'measurement_run_id', 'measurement_run_id_origin',
        'request_timeout_seconds', 'local_source_compose_declared_worker_count',
        'asserted_actual_worker_count', 'local_source_entrypoint_apc_shm_size',
        'runtime_apcu_total_memory_bytes', 'api_version', 'php_version',
        'asserted_network_condition_id', 'asserted_resource_profile_id',
        'runtime_prefetch_policy', 'runtime_prefetch_policy_sha256',
        'runtime_cache_policy', 'runtime_cache_policy_sha256',
        'prefetch_config', 'cache_policy'
    )
    $comparisonRoutes = @(
        'health', 'latest_1', 'latest_2', 'latest_3', 'latest_4',
        'popular_1', 'popular_2', 'popular_3', 'popular_4', 'album', 'image_no_prefetch'
    )
    $comparisonMetrics = @(
        'samples', 'successful', 'failed', 'median_ms', 'p95_ms', 'p99_ms', 'max_ms', 'upstream_calls'
    )
    function New-ComparisonContractReport {
        param(
            [string] $RuntimeKind = 'local-fixture',
            [string] $SourceBinding = 'local-process',
            [AllowNull()] $WorkerCount = 1,
            [AllowNull()] $ApcuBytes = 33554344,
            [AllowNull()] $ImageDigest = $null,
            [string] $NetworkConditionId = 'loopback-fixture-v1',
            [string] $ResourceProfileId = 'windows-local-single-worker-v1'
        )
        $prefetchPolicy = [pscustomobject]@{
            default_pages = 10; high_priority_pages = 2; next_chapter_pages = 2
            wall_budget_ms = 5000; byte_budget = 16777216; max_active = 2
            low_memory_policy = 'stop-all-priorities'
        }
        $cachePolicy = [pscustomobject]@{
            page_cache_enabled = $true; page_cache_ttl_seconds = 3600
            max_item_bytes = 104857600; page_cache_min_free_bytes = 16777216
            page_cache_min_free_ratio = 8; prefetch_min_free_bytes = 33554432
            prefetch_min_free_ratio = 15
        }
        $warmSummary = [pscustomobject]@{}
        $warmSamples = New-Object 'System.Collections.Generic.List[object]'
        foreach ($route in $comparisonRoutes) {
            $warmSummary | Add-Member -MemberType NoteProperty -Name $route -Value ([pscustomobject]@{
                samples = 120; successful = 120; failed = 0; median_ms = 10
                p95_ms = 15; p99_ms = 18; max_ms = 20; upstream_calls = 0
            })
            for ($sampleIndex = 0; $sampleIndex -lt 120; $sampleIndex++) {
                $elapsedMs = if ($sampleIndex -lt 60) {
                    10
                } elseif ($sampleIndex -lt 114) {
                    15
                } elseif ($sampleIndex -lt 119) {
                    18
                } else {
                    20
                }
                $warmSamples.Add([pscustomobject]@{
                    name = $route; ok = $true; status = 200; elapsed_ms = $elapsedMs
                    api_calls = 0; request_id = 'contract-request'
                    upstream_attempts = '0'; upstream_ms = '0'; source_cache = 'disabled'
                    image_cache = ''; prefetch = ''
                })
            }
        }
        return [pscustomobject]@{
            environment = [pscustomobject]@{
                base_url = 'http://127.0.0.1:18878'; album_id = '350234'; chapter_id = '350234'
                powershell = '5.1'
                warmup_iterations = 10; iterations = 120; concurrency = 10
                script_version = 'contract'; script_sha256 = ('0123456789abcdef' * 4)
                local_source_performance_evidence_sha256 = ('123456789abcdef0' * 4)
                local_source_compose_sha256 = ('23456789abcdef01' * 4); local_source_index_sha256 = ('3456789abcdef012' * 4)
                local_source_dockerfile_sha256 = ('456789abcdef0123' * 4); local_source_entrypoint_sha256 = ('56789abcdef01234' * 4)
                asserted_runtime_kind = $RuntimeKind
                asserted_runtime_source_binding = $SourceBinding
                asserted_actual_worker_count = $WorkerCount
                measurement_run_id = '0123456789abcdef0123456789abcdef'; measurement_run_id_origin = 'script-generated'
                request_timeout_seconds = 10
                local_source_compose_declared_worker_count = 10
                local_source_entrypoint_apc_shm_size = '128M'
                runtime_apcu_total_memory_bytes = $ApcuBytes
                asserted_runtime_image_digest = $ImageDigest
                api_version = 'test'; php_version = '8.3.32'
                asserted_network_condition_id = $NetworkConditionId
                asserted_resource_profile_id = $ResourceProfileId
                runtime_prefetch_policy = $prefetchPolicy
                runtime_prefetch_policy_sha256 = Get-PerformanceEvidenceObjectSha256 -Value $prefetchPolicy
                runtime_cache_policy = $cachePolicy
                runtime_cache_policy_sha256 = Get-PerformanceEvidenceObjectSha256 -Value $cachePolicy
                prefetch_config = $prefetchPolicy; cache_policy = $cachePolicy
            }
            warm_summary = $warmSummary
            warm_samples = $warmSamples.ToArray()
        }
    }
    function Copy-ComparisonContractReport {
        param($Report)
        return $Report | ConvertTo-Json -Depth 10 | ConvertFrom-Json
    }
    $validComparisonBefore = New-ComparisonContractReport
    $validComparisonAfter = New-ComparisonContractReport
    $validComparison = Resolve-PerformanceComparisonEvidence `
        -BeforeReport $validComparisonBefore -AfterReport $validComparisonAfter `
        -RequiredEnvironmentFields $comparisonRequiredEnvironmentFields `
        -EqualityEnvironmentFields $comparisonEqualityEnvironmentFields `
        -ExpectedRoutes $comparisonRoutes -RequiredMetricFields $comparisonMetrics
    if (-not $validComparison.comparable -or -not $validComparison.evidence_complete) {
        throw "Pure comparison evidence helper rejected valid local-fixture reports: $($validComparison.reason)."
    }
    foreach ($comparisonField in @(
        'before_successful', 'after_successful', 'successful_delta',
        'before_failed', 'after_failed', 'failed_delta',
        'before_success_rate', 'after_success_rate', 'success_rate_delta'
    )) {
        Assert-Contains $baseline $comparisonField "comparison output $comparisonField"
    }
    $validPartialReport = Copy-ComparisonContractReport $validComparisonBefore
    $partialFailures = @($validPartialReport.warm_samples | Where-Object { $_.name -eq 'latest_1' } | Select-Object -Last 20)
    foreach ($sample in $partialFailures) {
        $sample.ok = $false
        $sample.status = 500
        $sample.api_calls = $null
        $sample | Add-Member -MemberType NoteProperty -Name error -Value 'contract failure'
    }
    $validPartialReport.warm_summary.latest_1.successful = 100
    $validPartialReport.warm_summary.latest_1.failed = 20
    $validPartialReport.warm_summary.latest_1.p99_ms = 15
    $validPartialReport.warm_summary.latest_1.max_ms = 15
    $validPartialEvidence = Resolve-PerformanceReportComparisonEvidence `
        -Report $validPartialReport -RequiredEnvironmentFields $comparisonRequiredEnvironmentFields `
        -ExpectedRoutes $comparisonRoutes -RequiredMetricFields $comparisonMetrics -Label 'PARTIAL'
    if (-not $validPartialEvidence.evidence_complete) {
        throw "Pure comparison evidence helper rejected 100 successful samples with 20 recorded failures: $($validPartialEvidence.reason)."
    }

    $unknownBefore = New-ComparisonContractReport -RuntimeKind 'unspecified' -SourceBinding 'unverified'
    $unknownAfter = New-ComparisonContractReport -RuntimeKind 'unspecified' -SourceBinding 'unverified'
    $nullWorkerBefore = New-ComparisonContractReport -WorkerCount $null
    $nullWorkerAfter = New-ComparisonContractReport -WorkerCount $null
    $zeroWorkerBefore = New-ComparisonContractReport -WorkerCount 0
    $zeroWorkerAfter = New-ComparisonContractReport -WorkerCount 0
    $nullApcuBefore = New-ComparisonContractReport -ApcuBytes $null
    $nullApcuAfter = New-ComparisonContractReport -ApcuBytes $null
    $zeroApcuBefore = New-ComparisonContractReport -ApcuBytes 0
    $zeroApcuAfter = New-ComparisonContractReport -ApcuBytes 0
    $unverifiedNetworkBefore = New-ComparisonContractReport -NetworkConditionId 'unverified'
    $unverifiedNetworkAfter = New-ComparisonContractReport -NetworkConditionId 'unverified'
    $differentNetworkAfter = New-ComparisonContractReport -NetworkConditionId 'different-network'
    $unverifiedResourceBefore = New-ComparisonContractReport -ResourceProfileId 'unverified'
    $unverifiedResourceAfter = New-ComparisonContractReport -ResourceProfileId 'unverified'
    $differentResourceAfter = New-ComparisonContractReport -ResourceProfileId 'different-resource'
    $invalidBindingBefore = New-ComparisonContractReport -RuntimeKind 'local-fixture' -SourceBinding 'docker-image' -ImageDigest ('sha256:' + ('a' * 64))
    $invalidBindingAfter = New-ComparisonContractReport -RuntimeKind 'local-fixture' -SourceBinding 'docker-image' -ImageDigest ('sha256:' + ('b' * 64))
    $invalidOriginBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidOriginBefore.environment.measurement_run_id_origin = 'caller-provided'
    $invalidHashBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidHashBefore.environment.local_source_index_sha256 = 'INDEX'
    $placeholderHashBefore = Copy-ComparisonContractReport $validComparisonBefore
    $placeholderHashBefore.environment.local_source_index_sha256 = ('a' * 64)
    $invalidSamplesBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidSamplesBefore.warm_summary.latest_1.samples = 1
    $invalidSamplesBefore.warm_summary.latest_1.successful = 1
    $invalidNullPercentileBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidNullPercentileBefore.warm_summary.latest_1.p99_ms = $null
    $invalidCounterRelationBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidCounterRelationBefore.warm_summary.latest_1.failed = 1
    $invalidPercentileOrderBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidPercentileOrderBefore.warm_summary.latest_1.p95_ms = 9
    $invalidMetricTypeBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidMetricTypeBefore.warm_summary.latest_1.samples = '120'
    $invalidNegativeMetricBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidNegativeMetricBefore.warm_summary.latest_1.upstream_calls = -1
    $forgedPolicyBefore = Copy-ComparisonContractReport $validComparisonBefore
    $forgedPolicyBefore.environment.runtime_prefetch_policy.default_pages = 0
    $emptyPolicyBefore = Copy-ComparisonContractReport $validComparisonBefore
    $emptyPolicyBefore.environment.runtime_prefetch_policy = [pscustomobject]@{}
    $emptyPolicyBefore.environment.runtime_prefetch_policy_sha256 = Get-PerformanceEvidenceObjectSha256 -Value $emptyPolicyBefore.environment.runtime_prefetch_policy
    $emptyPolicyAfter = Copy-ComparisonContractReport $emptyPolicyBefore
    $emptyCachePolicyBefore = Copy-ComparisonContractReport $validComparisonBefore
    $emptyCachePolicyBefore.environment.runtime_cache_policy = [pscustomobject]@{}
    $emptyCachePolicyBefore.environment.runtime_cache_policy_sha256 = Get-PerformanceEvidenceObjectSha256 -Value $emptyCachePolicyBefore.environment.runtime_cache_policy
    $emptyCachePolicyAfter = Copy-ComparisonContractReport $emptyCachePolicyBefore
    $invalidDeclaredWorkersBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidDeclaredWorkersBefore.environment.local_source_compose_declared_worker_count = 'ten'
    $invalidDeclaredWorkersAfter = Copy-ComparisonContractReport $invalidDeclaredWorkersBefore
    $invalidBaseUrlBefore = Copy-ComparisonContractReport $validComparisonBefore
    $invalidBaseUrlBefore.environment.base_url = 'not-a-url'
    $invalidBaseUrlAfter = Copy-ComparisonContractReport $invalidBaseUrlBefore
    $nullPrefetchConfigBefore = Copy-ComparisonContractReport $validComparisonBefore
    $nullPrefetchConfigBefore.environment.prefetch_config = $null
    $nullPrefetchConfigAfter = Copy-ComparisonContractReport $nullPrefetchConfigBefore
    $mismatchedPrefetchConfigBefore = Copy-ComparisonContractReport $validComparisonBefore
    $mismatchedPrefetchConfigBefore.environment.prefetch_config.default_pages = 0
    $mismatchedPrefetchConfigAfter = Copy-ComparisonContractReport $mismatchedPrefetchConfigBefore
    $extraEnvironmentBefore = Copy-ComparisonContractReport $validComparisonBefore
    $extraEnvironmentBefore.environment | Add-Member -MemberType NoteProperty -Name forged_runtime -Value 'yes'
    $extraEnvironmentAfter = Copy-ComparisonContractReport $extraEnvironmentBefore
    $extraMetricBefore = Copy-ComparisonContractReport $validComparisonBefore
    $extraMetricBefore.warm_summary.latest_1 | Add-Member -MemberType NoteProperty -Name forged_metric -Value 1
    $extraMetricAfter = Copy-ComparisonContractReport $extraMetricBefore
    $regressedSuccessAfter = Copy-ComparisonContractReport $validComparisonAfter
    $regressedSuccessAfter.warm_summary.latest_1.successful = 119
    $regressedSuccessAfter.warm_summary.latest_1.failed = 1
    $regressedRawSample = @($regressedSuccessAfter.warm_samples | Where-Object {
        $_.name -eq 'latest_1' -and $_.elapsed_ms -eq 18
    })[0]
    $regressedRawSample.ok = $false
    $regressedRawSample.status = 500
    $regressedRawSample.api_calls = $null
    $regressedRawSample | Add-Member -MemberType NoteProperty -Name error -Value 'contract failure'
    $summaryOnlyBefore = Copy-ComparisonContractReport $validComparisonBefore
    $summaryOnlyBefore.PSObject.Properties.Remove('warm_samples')
    $forgedWarmSampleBefore = Copy-ComparisonContractReport $validComparisonBefore
    $forgedWarmSampleBefore.warm_samples[0].elapsed_ms = 999
    $extraWarmSampleFieldBefore = Copy-ComparisonContractReport $validComparisonBefore
    $extraWarmSampleFieldBefore.warm_samples[0] | Add-Member -MemberType NoteProperty -Name forged_sample -Value 1
    $missingWarmSampleFieldBefore = Copy-ComparisonContractReport $validComparisonBefore
    $missingWarmSampleFieldBefore.warm_samples[0].PSObject.Properties.Remove('request_id')
    $missingEnvironmentBefore = Copy-ComparisonContractReport $validComparisonBefore
    $missingEnvironmentBefore.environment.PSObject.Properties.Remove('local_source_index_sha256')
    $missingRouteBefore = Copy-ComparisonContractReport $validComparisonBefore
    $missingRouteBefore.warm_summary.PSObject.Properties.Remove('latest_1')
    $missingMetricBefore = Copy-ComparisonContractReport $validComparisonBefore
    $missingMetricBefore.warm_summary.latest_1.PSObject.Properties.Remove('median_ms')
    $missingRouteAfter = Copy-ComparisonContractReport $validComparisonAfter
    $missingRouteAfter.warm_summary.PSObject.Properties.Remove('latest_1')
    $missingMetricAfter = Copy-ComparisonContractReport $validComparisonAfter
    $missingMetricAfter.warm_summary.latest_1.PSObject.Properties.Remove('median_ms')
    foreach ($invalidComparison in @(
        [pscustomobject]@{ name = 'both runtime assertions unknown'; before = $unknownBefore; after = $unknownAfter },
        [pscustomobject]@{ name = 'both worker counts null'; before = $nullWorkerBefore; after = $nullWorkerAfter },
        [pscustomobject]@{ name = 'both worker counts zero'; before = $zeroWorkerBefore; after = $zeroWorkerAfter },
        [pscustomobject]@{ name = 'both APCu capacities null'; before = $nullApcuBefore; after = $nullApcuAfter },
        [pscustomobject]@{ name = 'both APCu capacities zero'; before = $zeroApcuBefore; after = $zeroApcuAfter },
        [pscustomobject]@{ name = 'unverified network condition'; before = $unverifiedNetworkBefore; after = $unverifiedNetworkAfter },
        [pscustomobject]@{ name = 'different network condition'; before = $validComparisonBefore; after = $differentNetworkAfter },
        [pscustomobject]@{ name = 'unverified resource profile'; before = $unverifiedResourceBefore; after = $unverifiedResourceAfter },
        [pscustomobject]@{ name = 'different resource profile'; before = $validComparisonBefore; after = $differentResourceAfter },
        [pscustomobject]@{ name = 'runtime/source binding mismatch'; before = $invalidBindingBefore; after = $invalidBindingAfter },
        [pscustomobject]@{ name = 'caller-provided measurement origin'; before = $invalidOriginBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'invalid source hash'; before = $invalidHashBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'obvious placeholder source hash'; before = $placeholderHashBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'insufficient successful samples'; before = $invalidSamplesBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'null p99'; before = $invalidNullPercentileBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'sample counter contradiction'; before = $invalidCounterRelationBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'percentile order contradiction'; before = $invalidPercentileOrderBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'string metric'; before = $invalidMetricTypeBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'negative upstream calls'; before = $invalidNegativeMetricBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'policy fingerprint mismatch'; before = $forgedPolicyBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'empty prefetch policy'; before = $emptyPolicyBefore; after = $emptyPolicyAfter },
        [pscustomobject]@{ name = 'empty cache policy'; before = $emptyCachePolicyBefore; after = $emptyCachePolicyAfter },
        [pscustomobject]@{ name = 'invalid declared worker count'; before = $invalidDeclaredWorkersBefore; after = $invalidDeclaredWorkersAfter },
        [pscustomobject]@{ name = 'invalid base URL'; before = $invalidBaseUrlBefore; after = $invalidBaseUrlAfter },
        [pscustomobject]@{ name = 'null prefetch config'; before = $nullPrefetchConfigBefore; after = $nullPrefetchConfigAfter },
        [pscustomobject]@{ name = 'prefetch config disagrees with policy'; before = $mismatchedPrefetchConfigBefore; after = $mismatchedPrefetchConfigAfter },
        [pscustomobject]@{ name = 'extra environment field'; before = $extraEnvironmentBefore; after = $extraEnvironmentAfter },
        [pscustomobject]@{ name = 'extra warm metric'; before = $extraMetricBefore; after = $extraMetricAfter },
        [pscustomobject]@{ name = 'summary without raw warm samples'; before = $summaryOnlyBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'warm summary disagrees with raw samples'; before = $forgedWarmSampleBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'extra raw warm sample field'; before = $extraWarmSampleFieldBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'missing raw warm sample field'; before = $missingWarmSampleFieldBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'success rate regressed'; before = $validComparisonBefore; after = $regressedSuccessAfter },
        [pscustomobject]@{ name = 'BEFORE environment property missing'; before = $missingEnvironmentBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'BEFORE warm route missing'; before = $missingRouteBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'BEFORE used metric missing'; before = $missingMetricBefore; after = $validComparisonAfter },
        [pscustomobject]@{ name = 'AFTER warm route missing'; before = $validComparisonBefore; after = $missingRouteAfter },
        [pscustomobject]@{ name = 'AFTER used metric missing'; before = $validComparisonBefore; after = $missingMetricAfter }
    )) {
        $comparisonEvidence = Resolve-PerformanceComparisonEvidence `
            -BeforeReport $invalidComparison.before -AfterReport $invalidComparison.after `
            -RequiredEnvironmentFields $comparisonRequiredEnvironmentFields `
            -EqualityEnvironmentFields $comparisonEqualityEnvironmentFields `
            -ExpectedRoutes $comparisonRoutes -RequiredMetricFields $comparisonMetrics
        if ($comparisonEvidence.comparable -or $comparisonEvidence.evidence_complete) {
            throw "Pure comparison evidence helper accepted invalid reports: $($invalidComparison.name)."
        }
    }

    foreach ($measurementField in @(
        'local_source_index_sha256',
        'local_source_dockerfile_sha256',
        'local_source_entrypoint_sha256',
        'local_source_entrypoint_apc_shm_size',
        'local_source_compose_declared_worker_count',
        'asserted_actual_worker_count',
        'asserted_runtime_kind',
        'asserted_runtime_source_binding',
        'asserted_runtime_image_digest',
        'asserted_network_condition_id',
        'asserted_resource_profile_id',
        'runtime_prefetch_policy',
        'runtime_prefetch_policy_sha256',
        'runtime_cache_policy',
        'runtime_cache_policy_sha256',
        'runtime_apcu_total_memory_bytes',
        'measurement_run_id_origin',
        'comparison_evidence_complete',
        'comparison_evidence_reason',
        'attribution_measurement_run_id',
        'client_occupancy_ratio',
        'client_occupancy_ratio_raw',
        'apcu_before',
        'apcu_after',
        'expunges_delta',
        'prefetch_probe',
        'attribution_origin',
        'attribution_reason',
        'utilization_ratio',
        'waste_ratio'
    )) {
        Assert-Contains $baseline $measurementField "performance baseline records $measurementField"
    }
    Assert-Matches $baseline 'image_prefetch_trigger[\s\S]*?prefetch_followup[\s\S]*?prefetch=0' 'performance baseline measures enabled prefetch then reads follow-up pages without recursion'
    Assert-Matches $baseline 'MeasurementRunId[\s\S]*?test_run_id=[\s\S]*?test_cache_scoped' 'performance baseline uses and verifies an isolated test-mode cache namespace when available'
    Assert-Matches $baseline '\$prefetchMeasurementRunId\s*=\s*\$MeasurementRunId[\s\S]*?if\s*\(\$measurementRunIdGenerated\)[\s\S]*?\[guid\]::NewGuid\(\)\.ToString\(''N''\)' 'script-generated measurements allocate a distinct fresh prefetch attribution namespace'
    Assert-Matches $baseline 'Get-HealthSnapshot\s+-RunId\s+\$prefetchMeasurementRunId[\s\S]*?image_prefetch_trigger[\s\S]{0,300}?-RunId\s+\$prefetchMeasurementRunId[\s\S]*?prefetch_followup_[\s\S]{0,300}?-RunId\s+\$prefetchMeasurementRunId' 'all prefetch attribution requests use only the fresh probe namespace'
    $testCacheScopedPattern = [regex]::Escape("'test_cache_scoped'") + '\s*=>\s*\$healthContext->isTestMode\(\)\s*&&\s*\$healthContext->testRunId\(\)\s*!==\s*' + [regex]::Escape("''")
    Assert-Matches $source $testCacheScopedPattern 'health proves whether the requested test cache namespace is scoped'
    Assert-Matches $source 'prefetchAggregateDiagnostics\(MemoryCache \$cache, \?RequestContext \$context\)[\s\S]*?testCacheNamespace\(\)[\s\S]*?\$namespace \.[\s\S]*?diagnostics:prefetch:' 'prefetch aggregate diagnostics are isolated by the test run namespace'
    Assert-Matches $source 'recordPrefetchStats\(array \$stats\)[\s\S]*?testCacheNamespace\(\)[\s\S]*?foreach \(\[''attempted''[\s\S]*?diagnostics:prefetch:events:v1''[\s\S]*?\n\s*\}' 'prefetch completion event is published after all per-event aggregate counters'
    Assert-Matches $source 'FRAGMENTATION_CACHE_TTL\s*=\s*5\s*;' 'APCu fragmentation snapshot cache is exactly five seconds'
    Assert-Matches $source 'FRAGMENTATION_LEASE_TTL\s*=\s*1\s*;' 'APCu fragmentation sampler lease is exactly one second'
    $fragmentationState = [regex]::Match(
        $source,
        'private function fragmentationState\(\): array[\s\S]*?(?=\r?\n\s*public function diagnostics\(\): array)'
    ).Value
    if ([string]::IsNullOrWhiteSpace($fragmentationState)) {
        throw 'Missing performance policy contract: APCu fragmentationState implementation.'
    }
    Assert-Matches $fragmentationState 'apcu_add\([^\r\n]*FRAGMENTATION_LEASE_KEY[^\r\n]*FRAGMENTATION_LEASE_TTL\)[\s\S]*?\}\s*\r?\n\s*try\s*\{\s*\r?\n\s*\$sma\s*=\s*@apcu_sma_info\(false\)' 'only the fragmentation lease owner performs the detailed SMA scan'
    if ([regex]::Matches($fragmentationState, 'apcu_sma_info\(false\)').Count -ne 1) {
        throw 'APCu fragmentationState must contain exactly one detailed apcu_sma_info(false) owner scan.'
    }
    Assert-Matches $source 'memoryState\(\)[\s\S]*?apcu_sma_info\(true\)' 'ordinary APCu memory diagnostics use the allocation-free summary path'

    $verifiedEvidence = Resolve-PrefetchAttributionEvidence `
        -MeasurementRunIdGenerated $true -TestCacheScoped $true -TriggerSucceeded $true `
        -AggregateBefore $zeroAggregate -AggregateAfter $oneEventAggregate -FollowupHits 1
    if (-not $verifiedEvidence.attribution_verified -or
        $verifiedEvidence.origin -ne 'script-generated' -or
        $verifiedEvidence.reason -ne 'verified-single-event' -or
        $verifiedEvidence.events_delta -ne 1 -or
        $verifiedEvidence.utilization_ratio -ne 0.5 -or
        $verifiedEvidence.waste_ratio -ne 0.5
    ) {
        throw 'Pure prefetch attribution helper rejected or miscomputed the isolated single-event positive case.'
    }
    $attributionNegativeCases = @(
        [pscustomobject]@{
            name = 'caller-provided run id'; generated = $false; before = $zeroAggregate; after = $oneEventAggregate
            reason = 'caller-provided-run-id'
        },
        [pscustomobject]@{
            name = 'zero completed events'; generated = $true; before = $zeroAggregate
            after = (New-PrefetchAggregateContractValue -Stored 2); reason = 'completed-event-delta-not-one'
        },
        [pscustomobject]@{
            name = 'two completed events'; generated = $true; before = $zeroAggregate
            after = (New-PrefetchAggregateContractValue -Events 2 -Stored 2); reason = 'completed-event-delta-not-one'
        },
        [pscustomobject]@{
            name = 'nonzero initial aggregate'; generated = $true
            before = (New-PrefetchAggregateContractValue -Attempted 1); after = $oneEventAggregate
            reason = 'initial-aggregate-not-zero'
        },
        [pscustomobject]@{
            name = 'initial skip aggregate'; generated = $true
            before = (New-PrefetchAggregateContractValue -SkipCounts ([pscustomobject]@{ disabled = 1 }))
            after = $oneEventAggregate; reason = 'initial-skip-counts-not-empty'
        },
        [pscustomobject]@{
            name = 'nonempty JSON skip array'; generated = $true
            before = (New-PrefetchAggregateContractValue -SkipCounts ([object[]]@('disabled')))
            after = $oneEventAggregate; reason = 'initial-skip-counts-not-empty'
        },
        [pscustomobject]@{
            name = 'incomplete final aggregate'; generated = $true; before = $zeroAggregate
            after = [pscustomobject]@{ events = 1; stored = 2; skip_counts = [object[]]@() }
            reason = 'final-aggregate-invalid'
        },
        [pscustomobject]@{
            name = 'regressed final counter'; generated = $true; before = $zeroAggregate
            after = (New-PrefetchAggregateContractValue -Events 1 -Attempted -1 -Stored 2 -SkipCounts ([object[]]@()))
            reason = 'final-aggregate-counter-regressed'
        },
        [pscustomobject]@{
            name = 'malformed nonempty final skip array'; generated = $true; before = $zeroAggregate
            after = (New-PrefetchAggregateContractValue -Events 1 -Stored 2 -SkipCounts ([object[]]@('disabled')))
            reason = 'final-aggregate-invalid'
        }
    )
    foreach ($case in $attributionNegativeCases) {
        $evidence = Resolve-PrefetchAttributionEvidence `
            -MeasurementRunIdGenerated $case.generated -TestCacheScoped $true -TriggerSucceeded $true `
            -AggregateBefore $case.before -AggregateAfter $case.after -FollowupHits 1
        if ($evidence.attribution_verified -or
            $null -ne $evidence.utilization_ratio -or
            $null -ne $evidence.waste_ratio -or
            $evidence.reason -ne $case.reason
        ) {
            throw "Pure prefetch attribution helper accepted invalid evidence: $($case.name)."
        }
    }
    foreach ($gate in @(
        [pscustomobject]@{ name = 'unscoped test cache'; scoped = $false; trigger = $true; reason = 'test-cache-not-scoped' },
        [pscustomobject]@{ name = 'failed trigger'; scoped = $true; trigger = $false; reason = 'trigger-failed' }
    )) {
        $evidence = Resolve-PrefetchAttributionEvidence `
            -MeasurementRunIdGenerated $true -TestCacheScoped $gate.scoped -TriggerSucceeded $gate.trigger `
            -AggregateBefore $zeroAggregate -AggregateAfter $oneEventAggregate -FollowupHits 1
        if ($evidence.attribution_verified -or
            $null -ne $evidence.utilization_ratio -or
            $null -ne $evidence.waste_ratio -or
            $evidence.reason -ne $gate.reason
        ) {
            throw "Pure prefetch attribution helper accepted invalid evidence: $($gate.name)."
        }
    }
    Assert-Matches $baseline '\$concurrentReadyPaths[\s\S]*?\$concurrentReleasePath[\s\S]*?WriteAllText\(\$ReadyPath[\s\S]*?Test-Path\s+-LiteralPath\s+\$ReleasePath[\s\S]*?WriteAllText\(\$concurrentReleasePath' 'performance baseline releases concurrent clients only after every job is ready'
    Assert-Matches $baseline 'finally\s*\{[\s\S]*?Performance concurrency barrier cleanup' 'performance baseline always removes its concurrency barrier files'
    Assert-Matches $baseline 'max_ms\s*=\s*\$\(if\s*\(\$times\.Count\s*-gt\s*0\)\s*\{\s*\[int64\]\s*\(\(\$times\s*\|\s*Measure-Object\s+-Maximum\)\.Maximum\)' 'performance max_ms casts Measure-Object Double output back to an integer before in-memory evidence validation'
    if ($baseline -match 'UtcNow\.AddSeconds\(5\)\.Ticks') {
        throw 'Performance baseline still uses the racy fixed-delay concurrency barrier.'
    }
    Assert-Contains $baseline 'RequestTimeoutSeconds' 'performance baseline exposes a bounded HTTP timeout'
    Assert-Matches $baseline 'Invoke-WebRequest[\s\S]*?-TimeoutSec\s+\$RequestTimeoutSeconds' 'performance samples apply the bounded HTTP timeout'
    Assert-Matches $baseline '\$concurrentBatchWatch\s*=\s*\[System\.Diagnostics\.Stopwatch\]::StartNew\(\)[\s\S]*?Wait-Job[\s\S]*?\$concurrentBatchWatch\.Stop\(\)[\s\S]*?finally' 'concurrency wall uses monotonic request-only timing and bounded job waiting'
    if ($baseline -match 'Receive-Job\s+-Wait') {
        throw 'Performance baseline still has an unbounded Receive-Job wait.'
    }
    if ($baseline -match '(?m)^\s*worker_count\s*=') {
        throw 'Performance baseline still emits the ambiguous legacy worker_count field.'
    }
    if ($baseline -match '(?m)^\s*local_compose_declared_worker_count\s*=') {
        throw 'Performance baseline still emits a locally derived field without the local_source_ prefix.'
    }
    $compareFields = [regex]::Match($baseline, '\$comparisonEqualityEnvironmentFields\s*=\s*@\((?<fields>[\s\S]*?)\)')
    if (-not $compareFields.Success) {
        throw 'Performance baseline ComparePath equality field list was not found.'
    }
    foreach ($requiredComparable in @(
        'asserted_runtime_kind', 'asserted_runtime_source_binding',
        'asserted_actual_worker_count', 'runtime_apcu_total_memory_bytes',
        'powershell', 'php_version', 'asserted_network_condition_id',
        'asserted_resource_profile_id', 'runtime_prefetch_policy_sha256',
        'runtime_cache_policy_sha256'
    )) {
        if ($compareFields.Groups['fields'].Value -notmatch [regex]::Escape("'$requiredComparable'")) {
            throw "Performance baseline does not require comparable runtime condition '$requiredComparable' to match BEFORE."
        }
    }
    Assert-Matches $baseline 'param\([\s\S]*?NetworkConditionId[\s\S]*?ResourceProfileId' 'performance baseline requires explicit network and resource profile assertions'
    Assert-Matches $baseline '\$runtimePrefetchPolicy[\s\S]*?high_priority_pages[\s\S]*?\$runtimePrefetchPolicyHash' 'performance baseline fingerprints only the static prefetch policy'
    Assert-Matches $baseline '\$runtimeCachePolicy[\s\S]*?page_cache_enabled[\s\S]*?\$runtimeCachePolicyHash' 'performance baseline fingerprints the runtime cache policy'
    foreach ($provenanceOnly in @('local_source_', 'asserted_runtime_image_digest')) {
        if ($compareFields.Groups['fields'].Value -match [regex]::Escape($provenanceOnly)) {
            throw "Performance baseline incorrectly requires code provenance field '$provenanceOnly' to match BEFORE."
        }
    }
    Assert-Matches $baseline 'comparison_provenance[\s\S]*?asserted_runtime_image_digest' 'comparison provenance uses the asserted runtime image digest name'
    Assert-Matches $baseline '\$comparisonRequiredEnvironmentFields[\s\S]*?local_source_index_sha256[\s\S]*?Resolve-PerformanceComparisonEvidence' 'ComparePath requires the complete expected environment schema independently of equality gates'
    Assert-Matches $baseline 'Resolve-PerformanceComparisonEvidence[\s\S]*?ComparePath evidence is incomplete[\s\S]*?comparison_preconditions' 'ComparePath fails closed through the pure evidence helper and records complete preconditions'
    Assert-Matches $baseline '\$initialPerformanceEvidenceHash\s*=\s*\(Get-FileHash[^\r\n]*\r?\n\s*\.\s+\$performanceEvidencePath\s*\r?\n\s*\$performanceEvidenceHash\s*=\s*\(Get-FileHash[\s\S]*?Performance evidence helper changed while it was being loaded' 'pure evidence helper hash is stable across loading'
    Assert-Matches $baseline '\$finalPerformanceEvidenceHash[\s\S]*?Performance evidence helper changed while the performance measurement was running' 'pure evidence helper provenance is stable through report generation'
    foreach ($sourceStabilityContract in @(
        @('\$finalScriptHash[\s\S]*?performance-baseline\.ps1 changed while the performance measurement was running', 'baseline script'),
        @('\$finalIndexHash[\s\S]*?index\.php changed while the performance measurement was running', 'index'),
        @('\$finalDockerfileHash[\s\S]*?Dockerfile changed while the performance measurement was running', 'Dockerfile'),
        @('\$finalComposeHash[\s\S]*?docker-compose\.yml changed while the performance measurement was running', 'compose'),
        @('\$finalEntrypointHash[\s\S]*?docker-entrypoint\.sh changed while the performance measurement was running', 'entrypoint')
    )) {
        Assert-Matches $baseline $sourceStabilityContract[0] "performance source stability: $($sourceStabilityContract[1])"
    }
    if ($baseline -match '(?m)^\s{8}(?:runtime_kind|source_binding|runtime_image_digest|apc_shm_size)\s*=') {
        throw 'Performance baseline still emits an unqualified caller assertion or local APC setting field.'
    }
    foreach ($snippet in @(
        'JM_IMAGE_MAX_COMPRESSED_BYTES',
        'JM_IMAGE_MAX_PIXELS',
        'JM_TRUSTED_PROXY_CIDRS',
        'X-JM-Test-Client-Ip',
        'test_trusted_proxy',
        'chapter:v2:',
        'manifest:v2:',
        'JM_CDN_EPOCH',
        'getimagesizefromstring',
        'CURLOPT_WRITEFUNCTION'
    )) { Assert-Contains $source $snippet $snippet }
    foreach ($snippet in @(
        'chapter-images-strings', 'chapter-images-objects', 'chapter-images-mixed',
        'chapter-images-empty', 'chapter-images-object-empty', 'chapter-images-object-zero',
        'chapter-images-malformed',
        'comic-read-valid', 'comic-read-objects', 'comic-read-missing-scramble'
    )) { Assert-Contains $fixture $snippet "resource fixture $snippet" }
    foreach ($snippet in @(
        'chapter-cache-v2', 'manifest-cache-v2', 'manifest-cache-forgery',
        'manifest-cache-unsegmented-mime',
        'chapter-object-mixed', 'chapter-malformed-empty', 'chapter-malformed-not-cached',
        'chapter-json-object-containers', 'json-root-list-provenance', 'json-nested-list-provenance',
        'json-list-item-field-provenance', 'json-album-list-field-provenance',
        'json-chapter-root-series-provenance',
        'json-chapter-extra-metadata-forward-compat',
        'json-list-item-extra-metadata-forward-compat',
        'json-album-extra-metadata-forward-compat',
        'cdn-failover-policy', 'cdn-health-secondary', 'compressed-body-collector',
        'compressed-body-limit', 'image-pixel-policy', 'image-encoder-failures',
        'image-encoder-failure-metrics',
        'image-passthrough-validation', 'image-container-tail-truncation',
        'image-gif-lzw-integrity', 'image-gif-lzw-boundaries',
        'image-gif-frame-bounds', 'image-gif-streaming-memory',
        'image-segmented-raw-container', 'image-container-structure',
        'image-container-webp-animation', 'image-decoder-failure-cleanup',
        'image-encoder-output-validation',
        'scramble-cache-poison', 'scramble-golden', 'scramble-decimal-20',
        'page-cache-poison', 'page-cache-attestation', 'page-cache-production-write-once', 'page-cache-digest-binding',
        'page-cache-attestation-disabled', 'page-cache-attestation-unavailable',
        'unicode-control-cache-poison', 'page-ttl-zero-prefetch', 'trusted-proxy-cidr',
        'trusted-proxy-client-ip', 'trusted-proxy-base-url', 'redis-lazy-breaker',
        'redis-timeouts', 'redis-lua-results', 'redis-failure-circuit',
        'chapter-ttl-zero-bypass', 'chapter-ttl-zero-failure',
        'manifest-ttl-zero-bypass', 'manifest-ttl-zero-failure',
        'page-ttl-zero-bypass', 'page-ttl-zero-failure'
    )) { Assert-Contains $resourceRuntime $snippet "resource runtime $snippet" }
    Assert-Contains $resourceFixtureContract 'Production must not enable /comic_read' 'disabled comic_read production gate'
    foreach ($snippet in @(
        'chapter-images-objects', 'chapter-images-object-empty', 'chapter-images-object-zero',
        'chapter-images-malformed', 'image-body-exact',
        'image-body-over', 'image-body-no-length', 'image-body-forged-length',
        'image-body-chunked-exact', 'image-body-chunked-over',
        'image-pixel-over', 'image-http-302', 'image-http-408', 'image-http-429',
        'cdn-502', 'X-JM-Test-Client-Ip', 'Resource HTTP runtime passed.'
    )) { Assert-Contains $resourceHttpRuntime $snippet "resource HTTP runtime $snippet" }
    Assert-Matches $resourceHttpRuntime 'PortCollisionSelfTest[\s\S]*?TcpListener[\s\S]*?fixtureResetCalls\s*-eq\s*0' 'resource HTTP gate exposes an executable occupied-port self-test before fixture reset'
    Assert-Matches $resourceHttpRuntime 'Assert-LoopbackPortAvailable[\s\S]*?ExclusiveAddressUse\s*=\s*\$true[\s\S]*?\.Start\(\)' 'resource HTTP gate preflights loopback ports with an exclusive bind'
    Assert-Matches $resourceHttpRuntime 'Assert-LoopbackPortAvailable\s+-Port\s+\$ApiPort[\s\S]*?Assert-LoopbackPortAvailable\s+-Port\s+\$FixturePort[\s\S]*?Start-Process' 'resource HTTP gate checks both fixed ports before starting either server'
    Assert-Matches $resourceHttpRuntime 'Wait-Endpoint[\s\S]*?Invoke-WebRequest[\s\S]*?\.Refresh\(\)[\s\S]*?\.HasExited[\s\S]*?return' 'resource HTTP readiness rechecks the launched process after a successful response'
    Assert-Matches $resourceHttpRuntime 'image-http-408[\s\S]*?\$requestTimeout\.Status -eq 502[\s\S]*?Count-MediaHost \$requestTimeoutCounts ''127\.0\.0\.1'' ''image-http-408''\) -eq 1[\s\S]*?Count-MediaHost \$requestTimeoutCounts ''localhost'' ''image-http-408''\) -eq 0' 'resource HTTP gate verifies HTTP 408 fails without a secondary CDN attempt'
    Assert-Matches $fixture '\$scenario === ''image-http-408'' && \$host === ''127\.0\.0\.1''' 'HTTP 408 fixture fails only the primary CDN host'
    Assert-Contains $faultRuntime '[switch] $LocalResources' 'fault runtime exposes local resource verification mode'
    Assert-Matches $faultRuntime 'if\s*\(\$LocalResources\)[\s\S]*?resource-http-runtime\.ps1[\s\S]*?return' 'fault runtime delegates local resource verification and returns'
    Assert-Matches $source 'DEFAULT_IMAGE_MAX_COMPRESSED_BYTES\s*=\s*33554432' 'compressed image cap defaults to 32 MiB'
    Assert-Matches $source 'DEFAULT_IMAGE_MAX_PIXELS\s*=\s*80000000' 'image pixel cap defaults to 80 MP'
    Assert-Matches $source 'validatedAbsoluteCoverUrl[\s\S]*?FILTER_VALIDATE_URL[\s\S]*?\[''user''\][\s\S]*?\[''pass''\][\s\S]*?\[''fragment''\][\s\S]*?buildCoverUrl[\s\S]*?validatedAbsoluteCoverUrl' 'absolute upstream cover compatibility is guarded by URL, credential, fragment, and control validation'
    Assert-Matches $source 'interface UpstreamTransport[\s\S]*?\?int \$bodyLimitBytes = null' 'transport exposes an explicit optional body limit'
    Assert-Matches $source 'consumeChunk[\s\S]*?\$chunkBytes > \$this->limitBytes - strlen\(\$this->body\)[\s\S]*?\$this->body \.= \$chunk' 'write callback checks the limit before appending'
    Assert-Matches $source 'downloadImage[\s\S]*?bodyLimitExceeded[\s\S]*?break;' 'body-limit status stops without CDN failover'
    Assert-Matches $source 'downloadImage[\s\S]*?if\s*\(\s*!\$result->ok\s*\)[\s\S]*?candidateIndex === 0[\s\S]*?continue[\s\S]*?status >= 500 && \$result->status <= 599[\s\S]*?candidateIndex === 0[\s\S]*?continue[\s\S]*?status < 200 \|\| \$result->status >= 300[\s\S]*?break;' 'only transport errors and HTTP 5xx can fail over once'
    if ($source -match 'downloadImage[\s\S]*?status\s*===\s*408[\s\S]*?candidateIndex\s*===\s*0[\s\S]*?continue') {
        throw 'HTTP 408 must not trigger CDN failover.'
    }
    Assert-Matches $source 'final class GdImageDecoder[\s\S]*?getimagesizefromstring\(\$bytes\)[\s\S]*?ImagePixelPolicy::checkedPixels[\s\S]*?imagecreatefromstring\(\$bytes\)' 'pixel validation occurs before GD decode'
    Assert-Matches $source 'interface ImagePayloadValidator[\s\S]*?isCompleteDecode\(string \$bytes, int \$width, int \$height\): bool[\s\S]*?hasCompleteDecodeAttestation\(string \$bytes, int \$width, int \$height\): bool' 'complete image validation and request-scoped proof are injectable production dependencies'
    Assert-Matches $source 'final class GdImagePayloadValidator implements ImagePayloadValidator[\s\S]*?imagecreatefromstring\(\$bytes\)[\s\S]*?finally[\s\S]*?imagedestroy\(\$decoded\)' 'passthrough/cache complete-decode validator always releases GD images'
    Assert-Matches $source 'GdImagePayloadValidator[\s\S]*?private \?string \$lastAttestation[\s\S]*?hash\(''sha256'', \$bytes\) \. '':'' \. \$width \. '':'' \. \$height[\s\S]*?hash_equals' 'request-scoped complete-decode proof binds digest, width, and height without static accumulation'
    Assert-Matches $source 'final class GdImageDecoder[\s\S]*?ImagePixelPolicy::checkedPixels[\s\S]*?\$segments === 0 \|\| \$mime === ''image/gif''[\s\S]*?payloadValidator->isCompleteDecode' 'passthrough images are fully decoded after pixel checks and before return'
    Assert-Matches $source 'GdImageDecoder[\s\S]*?new GdImageOutputEncoder\([\s\S]*?payloadValidator:\s*\$this->payloadValidator' 'default GD decoder and output encoder share one complete-decode validator'
    $containerPolicy = [regex]::Match(
        $source,
        'final class ImageContainerPolicy[\s\S]*?(?=\r?\ninterface ImagePayloadValidator)'
    ).Value
    if ([string]::IsNullOrWhiteSpace($containerPolicy)) {
        throw 'Missing performance policy contract: image container policy'
    }
    Assert-Matches $containerPolicy 'return match \(\$mime\)[\s\S]*?image/jpeg[\s\S]*?isCompleteJpeg[\s\S]*?image/png[\s\S]*?isCompletePng[\s\S]*?image/gif[\s\S]*?isCompleteGif[\s\S]*?image/webp[\s\S]*?isCompleteWebp' 'container policy dispatches every supported image MIME to a structural parser'
    Assert-Matches $containerPolicy 'isCompleteJpeg[\s\S]*?str_starts_with\(\$bytes, "\\xFF\\xD8"\)[\s\S]*?\$segmentLength > \$length - \$offset' 'JPEG parser requires SOI and bounds every length-bearing marker segment'
    Assert-Matches $containerPolicy 'isJpegStartOfFrameMarker\(\$marker\)[\s\S]*?\$segmentLength !== 8 \+ \(3 \* \$componentCount\)[\s\S]*?\$segmentLength !== 6 \+ \(2 \* \$scanComponentCount\)' 'JPEG parser requires structurally valid SOF and SOS headers'
    Assert-Matches $containerPolicy '\$marker === 0xD9\) return \$seenFrame && \$seenScan && \$offset \+ 1 === \$length' 'JPEG parser accepts only a real terminal EOI after frame and scan data'
    Assert-Matches $containerPolicy 'isCompletePng[\s\S]*?\\x89PNG\\r\\n\\x1A\\n[\s\S]*?\$chunkLength > \$length - \$offset - 12[\s\S]*?crc32\(\$type \. \$data\)[\s\S]*?IHDR[\s\S]*?IDAT[\s\S]*?IEND[\s\S]*?\$nextOffset === \$length' 'PNG parser bounds every chunk, verifies CRCs, and requires terminal IEND after image data'
    Assert-Matches $containerPolicy 'isCompleteGif[\s\S]*?GIF87a[\s\S]*?GIF89a[\s\S]*?\(\$packed & 0x80\)[\s\S]*?\$blockType === 0x3B\) return \$seenImage && \$offset \+ 1 === \$length' 'GIF parser requires a complete header/color table and one terminal trailer'
    Assert-Matches $containerPolicy '\$logicalWidth[\s\S]*?\$logicalHeight[\s\S]*?\$frameWidth > \$logicalWidth[\s\S]*?\$frameLeft > \$logicalWidth - \$frameWidth[\s\S]*?\$frameHeight > \$logicalHeight[\s\S]*?\$frameTop > \$logicalHeight - \$frameHeight' 'GIF frames use subtraction-safe bounds inside the positive logical screen'
    Assert-Matches $containerPolicy '\$blockType === 0x21[\s\S]*?skipGifSubBlocks[\s\S]*?\$blockType !== 0x2C[\s\S]*?\$minimumCodeSize[\s\S]*?validatedGifLzwNextOffset' 'GIF parser skips extensions and stream-validates every image frame'
    Assert-Matches $containerPolicy 'validatedGifLzwNextOffset[\s\S]*?\$clearCode\s*=\s*1 << \$minimumCodeSize[\s\S]*?\$endCode\s*=\s*\$clearCode \+ 1[\s\S]*?\$nextCode[\s\S]*?\$codeWidth' 'GIF LZW parser initializes Clear, EOI, dictionary, and code-width state'
    Assert-Matches $containerPolicy '\$currentBlockRemaining[\s\S]*?\$bitBuffer[\s\S]*?ord\(\$bytes\[\$cursor\]\)[\s\S]*?\$currentBlockRemaining--' 'GIF LZW parser streams bytes directly across data sub-blocks'
    Assert-Matches $containerPolicy '\$code === \$clearCode[\s\S]*?\$previousLength = null[\s\S]*?\$code === \$endCode[\s\S]*?\$outputCount !== \$expectedIndexes[\s\S]*?\$currentBlockRemaining !== 0[\s\S]*?ord\(\$bytes\[\$cursor\]\) !== 0[\s\S]*?return \$cursor \+ 1' 'GIF LZW parser resets on Clear and requires exact output plus terminal sub-block EOI'
    Assert-Matches $containerPolicy '\$code === \$nextCode && \$nextCode < 4096[\s\S]*?\$currentLength = \$previousLength \+ 1[\s\S]*?\$nextCode\+\+[\s\S]*?\$nextCode === \(1 << \$codeWidth\)' 'GIF LZW parser permits only bounded KwKwK and grows code width after dictionary insertion'
    if ($containerPolicy -match 'readGifSubBlocks|\$chunks\s*=|implode\(') {
        throw 'GIF image data must be consumed as a stream without per-block payload accumulation.'
    }
    Assert-Matches $containerPolicy 'isCompleteWebp[\s\S]*?\$declaredSize !== \$length - 8[\s\S]*?\$chunkSize > \$length - \$offset - 8[\s\S]*?\$bytes\[\$offset\] !== "\\x00"' 'WebP parser binds RIFF size and exact chunk padding boundaries'
    Assert-Matches $containerPolicy '\$type === ''ANMF''[\s\S]*?\$chunkSize < 16[\s\S]*?isCompleteWebpFrame[\s\S]*?VP8 [\s\S]*?VP8L[\s\S]*?\$offset === \$end && \$seenImage' 'animated WebP frames require a bounded nested VP8 or VP8L image chunk'
    $gdDecoder = [regex]::Match(
        $source,
        'final class GdImageDecoder[\s\S]*?(?=\r?\n    private static function elapsedMs)'
    ).Value
    if ([string]::IsNullOrWhiteSpace($gdDecoder)) {
        throw 'Missing performance policy contract: GD decoder body'
    }
    $negativeSegmentsOffset = $gdDecoder.IndexOf('if ($segments < 0)')
    $passthroughOffset = $gdDecoder.IndexOf('if ($segments === 0 || $mime === ''image/gif'')')
    $rawContainerOffset = $gdDecoder.IndexOf('if (!ImageContainerPolicy::isComplete($bytes, $mime))')
    $gdAllocationOffset = $gdDecoder.IndexOf('$src = @imagecreatefromstring($bytes)')
    if ($negativeSegmentsOffset -lt 0 -or $passthroughOffset -lt 0 -or $rawContainerOffset -lt 0 -or $gdAllocationOffset -lt 0 -or
        -not ($negativeSegmentsOffset -lt $passthroughOffset -and $passthroughOffset -lt $rawContainerOffset -and $rawContainerOffset -lt $gdAllocationOffset)) {
        throw 'GD decoder must reject negative segments, validate passthrough once, then gate segmented raw containers before GD allocation.'
    }
    Assert-Matches $source 'height > intdiv\(PHP_INT_MAX, \$width\)[\s\S]*?height > intdiv\(\$effectiveMaxPixels, \$width\)' 'pixel multiplication uses division overflow guards'
    Assert-Matches $source 'imageMaxCompressedBytes[\s\S]*?\$configured\s*>\s*0\s*\?\s*\$configured\s*:\s*JmConfig::DEFAULT_IMAGE_MAX_COMPRESSED_BYTES' 'zero image byte configuration falls back to the mandatory safe default'
    Assert-Matches $source 'imageMaxPixels[\s\S]*?\$configured\s*>\s*0\s*\?\s*\$configured\s*:\s*JmConfig::DEFAULT_IMAGE_MAX_PIXELS' 'zero image pixel configuration falls back to the mandatory safe default'
    Assert-Matches $source 'checkedPixels[\s\S]*?\$effectiveMaxPixels\s*=\s*\$maxPixels\s*>\s*0\s*\?\s*\$maxPixels\s*:\s*JmConfig::DEFAULT_IMAGE_MAX_PIXELS' 'pixel policy itself cannot disable the mandatory cap with zero'
    Assert-Matches $source 'recordImageDecodeSuccess[\s\S]*?input_bytes[\s\S]*?width[\s\S]*?height[\s\S]*?pixels[\s\S]*?decode_ms[\s\S]*?encode_ms[\s\S]*?peak_memory_bytes' 'image metrics are recorded'
    Assert-Matches $source 'ChapterImagePolicy[\s\S]*?isValidFilename[\s\S]*?preg_match\(''//u'',\s*\$filename\)\s*===\s*1' 'chapter filenames must be valid UTF-8 before URL encoding'
    Assert-Matches $source 'ChapterImagePolicy[\s\S]*?isValidFilename[\s\S]*?preg_match\(''/\\p\{Cc\}/u'',\s*\$filename\)\s*!==\s*1' 'all chapter/cache filename validators reject Unicode control characters'
    Assert-Matches $source 'normalizeChapterImages[\s\S]*?preg_match\(''/\\p\{Cc\}/u'',\s*\$filename\)\s*===\s*1[\s\S]*?\$filename\s*=\s*trim\(\$filename\)' 'raw chapter image controls are rejected before trimming'
    Assert-Matches $source 'fetchScrambleId[\s\S]*?is_string\(\$cached\)[\s\S]*?\^\\d\{1,20\}\$[\s\S]*?cache->delete\(\$cacheKey\)[\s\S]*?api->fetchScrambleId[\s\S]*?Malformed scramble id' 'invalid scramble cache entries are discarded and refetched before chapter keys are derived'
    Assert-Matches $source 'ScrambleDecoder[\s\S]*?canonicalDecimal\(\$scrambleId\)[\s\S]*?canonicalDecimal\(\$aid\)[\s\S]*?compareDecimal[\s\S]*?md5\(\$canonicalAid\s*\.\s*\$pageName\)' 'scramble segmentation preserves exact one-to-twenty-digit decimal semantics'
    if ($source -match '\$sid\s*=\s*\(int\)\s*\$scrambleId|\$aid\s*=\s*\(int\)\s*\$aid') {
        throw 'Scramble segmentation must not saturate decimal IDs through native integers.'
    }
    Assert-Matches $source 'RATE_LIMIT_LUA[\s\S]*?ZREMRANGEBYSCORE[\s\S]*?ZCARD[\s\S]*?ZRANGE[\s\S]*?ZADD[\s\S]*?EXPIRE' 'Redis limiter uses one atomic script'
    Assert-Matches $source 'checkRate[\s\S]*?->eval\(self::RATE_LIMIT_LUA[\s\S]*?\], 1\)' 'Redis rate check issues one EVAL with one key'
    Assert-Matches $source 'final class RedisStore[\s\S]*?function __construct[\s\S]*?function isAvailable\(\): bool \{ return \$this->client\(\) !== null; \}' 'Redis connection is lazy'
    Assert-Matches $source 'interface RedisAdapter[\s\S]*?connect\([\s\S]*?float \$connectTimeoutSeconds,[\s\S]*?float \$readTimeoutSeconds' 'Redis adapter contract carries connect and read timeouts'
    Assert-Matches $source 'final class PhpRedisAdapter[\s\S]*?->connect\([\s\S]*?\$connectTimeoutSeconds,[\s\S]*?null,[\s\S]*?0,[\s\S]*?\$readTimeoutSeconds' 'phpredis configures connect and read timeouts together'
    Assert-Matches $source '\$redis->connect\([\s\S]*?\$this->host,[\s\S]*?\$this->port,[\s\S]*?\$this->timeoutSeconds,[\s\S]*?\$this->timeoutSeconds' 'RedisStore passes bounded connect and read timeouts'
    Assert-Matches $source 'final class RedisFailureCircuit[\s\S]*?fallbackOpenUntilMs[\s\S]*?redis-breaker:v1:' 'Redis failure circuit has APCu and static fallback state'
    Assert-Matches $source 'parseRateResult\(mixed \$value, int \$max, int \$window\)[\s\S]*?redisNonNegativeInt[\s\S]*?\$remaining > \$max - 1[\s\S]*?\$remaining !== 0[\s\S]*?\$retryAfter > \$window' 'Redis EVAL parser rejects impossible tuples and retry values outside the Lua window'
    Assert-Matches $source 'redisNonNegativeInt[\s\S]*?PHP_INT_MAX[\s\S]*?strcmp\(\$value, \$limit\) > 0' 'Redis EVAL parser rejects decimal integers that would saturate PHP int'
    Assert-Matches $source 'final class GdImageOutputEncoder[\s\S]*?getimagesizefromstring\(\$output\)[\s\S]*?\[''mime''\][\s\S]*?payloadValidator->isCompleteDecode\(\$output[\s\S]*?imagesx\(\$image\)[\s\S]*?imagesy\(\$image\)' 'encoded image output is MIME-checked, fully decoded by the shared validator, and dimension-checked'
    Assert-Matches $fixture 'image-body-chunked-exact[\s\S]*?Transfer-Encoding:\s*chunked[\s\S]*?dechex\(\$frameSize\)' 'resource fixture emits explicit HTTP chunked frames without Content-Length'
    Assert-Matches $dockerfile 'pecl\s+install[^\r\n]*\bredis(?:-[0-9.]+)?\b' 'runtime image installs phpredis for the optional Redis safety path'
    Assert-Matches $dockerfile 'docker-php-ext-enable[^\r\n]*\bredis\b' 'runtime image enables phpredis'
    Assert-Matches $testCompose '(?s)  jm-redis:.*?redis:8\.8\.0-alpine@sha256:.*?--protected-mode\s*\r?\n\s*-\s*"no".*?redis-cli' 'test Compose provides a bounded Redis service reachable from its isolated network'
    Assert-Matches $redisRuntime 'RedisStore\(prefix:\s*\$prefix\)[\s\S]*?->checkRate\(\$key,\s*\$window,\s*\$maximum\)' 'real Redis workers execute the production rate limiter'
    Assert-Matches $redisGate 'allowed\.Count\s*-eq\s*\$MaxRequests[\s\S]*?eval_calls\s*-eq\s*\$WorkerCount[\s\S]*?Open Redis breaker issued EVAL[\s\S]*?Redis breaker did not recover' 'real Redis gate verifies exact concurrency and breaker recovery'
    Assert-Matches $source 'fetchChapter[\s\S]*?\$ttl\s*=\s*self::chapterCacheTtl\(\)[\s\S]*?if\s*\(\$ttl\s*>\s*0\)\s*\{[\s\S]*?cache->get' 'chapter TTL zero bypasses existing cache reads'
    Assert-Matches $source 'fetchReaderManifest[\s\S]*?\$ttl\s*=\s*self::chapterCacheTtl\(\)[\s\S]*?if\s*\(\$ttl\s*>\s*0\)\s*\{[\s\S]*?cache->get' 'manifest TTL zero bypasses existing cache reads'
    Assert-Matches $source 'singleFlight[\s\S]*?self::pageCacheTtl\(\)\s*<=\s*0[\s\S]*?\$result\s*=\s*\$producer\(\)' 'page TTL zero bypasses cache-dependent single-flight waiting'
    Assert-Matches $source 'cachedDecodedPage[\s\S]*?if\s*\(\$ttl\s*<=\s*0\s*\|\|\s*!\$this->cache->isAvailable\(\)\)\s*return null' 'page TTL zero and unavailable APCu bypass decoded cache reads and attestation state'
    Assert-Matches $source 'cacheDecodedPage[\s\S]*?\$ttl\s*=\s*self::pageCacheTtl\(\)[\s\S]*?if\s*\(\$ttl\s*<=\s*0\s*\|\|\s*!\$this->cache->isAvailable\(\)\)' 'page TTL zero and unavailable APCu disable decoded cache and marker writes'
    Assert-Matches $source 'validatedDecodedPageEnvelope[\s\S]*?decoded-page-v3[\s\S]*?hasExactKeys[\s\S]*?bytes_sha256[\s\S]*?hash\(''sha256'',\s*\$value\[''bytes''\]\)[\s\S]*?hash_equals[\s\S]*?getimagesizefromstring[\s\S]*?ImagePixelPolicy::checkedPixels' 'decoded page v3 envelope strictly binds exact fields, actual bytes, MIME, dimensions, and pixels'
    Assert-Matches $source 'decodedPageAttestationKey[\s\S]*?decoded-page-attestation:v2:[\s\S]*?validatedDecodedPageAttestation[\s\S]*?hasExactKeys[\s\S]*?decoded-page-attestation-v2[\s\S]*?bytes_sha256' 'decoded page attestation uses the v2 digest-keyed exact marker schema'
    if ($source -match 'decoded-page-attestation:v1:|decoded-page-attestation-v1') {
        throw 'Production must not read legacy decoded-page attestation v1 state after the container policy upgrade.'
    }
    Assert-Matches $source 'validatedDecodedPageCacheEntry[\s\S]*?validatedDecodedPageEnvelope[\s\S]*?validatedDecodedPageAttestation[\s\S]*?imagePayloadValidator->isCompleteDecode[\s\S]*?cache->set\(\s*\$markerKey[\s\S]*?\$ttl,' 'cache HITs skip full decode only after exact digest attestation and safely rebuild missing markers'
    Assert-Matches $source 'JmService[\s\S]*?\$this->imagePayloadValidator\s*=\s*\$imagePayloadValidator[\s\S]*?new GdImageDecoder\([\s\S]*?payloadValidator:\s*\$this->imagePayloadValidator' 'service and its default GD decoder share one request-scoped complete-decode validator'
    Assert-Matches $source 'validatedDecodedPageResultDigest[\s\S]*?validatedDecodedPageEnvelope[\s\S]*?hasCompleteDecodeAttestation[\s\S]*?\|\|\s*\$this->imagePayloadValidator->isCompleteDecode' 'materialization reuses trusted request-scoped proof or validates an injected decoder result exactly once'
    Assert-Matches $source 'cacheDecodedPage\(string \$cacheKey, array \$result, string \$validatedDigest\)[\s\S]*?validatedDecodedPageEnvelope[\s\S]*?hash_equals\(\$validatedDigest, \$entry\[''bytes_sha256''\]\)[\s\S]*?cache->set\(\s*\$cacheKey[\s\S]*?cache->set\(\s*\$markerKey[\s\S]*?\$ttl,' 'cache writes accept only the validated digest before storing the page and best-effort marker at the page TTL'
    $cacheWriter = [regex]::Match($source, 'private function cacheDecodedPage[\s\S]*?(?=\r?\n    private function prefetchWaterlineOk)').Value
    if ($cacheWriter -match 'isCompleteDecode') {
        throw 'Decoded page cache writer must not repeat the materialization full validation.'
    }
    Assert-Matches $source 'decodedPageCacheKey[\s\S]*?page:v3:' 'decoded page identity uses only the v3 cache key namespace'
    if ($source -match 'decoded-page-v2|return\s+''page:v2:''|GdImagePayloadValidator::isCompleteDecode') {
        throw 'Legacy decoded page v2 state or static complete-decode calls remain in production.'
    }
    Assert-Matches $source '\$enabled\s*&&\s*self::pageCacheTtl\(\)\s*>\s*0' 'page TTL zero disables prefetch scheduling'
    Assert-Matches $source 'runtimeDiagnostics[\s\S]*?page_cache_enabled[\s\S]*?page_cache_ttl_seconds' 'runtime diagnostics expose effective page cache state'
    Assert-Matches $source 'final class TrustedProxyPolicy[\s\S]*?isTrustedProxy[\s\S]*?clientIp[\s\S]*?requestBaseUrl' 'forwarded headers share the trusted proxy policy'
    Assert-Matches $source 'RequestContext::current\(\)\?->isTestMode\(\) === true[\s\S]*?X-JM-Test-Client-Ip' 'test client IP header is test-mode only'
    Assert-Matches $source 'function requestBaseUrl\(\): string[\s\S]*?TrustedProxyPolicy::fromEnvironment[\s\S]*?->requestBaseUrl\(\$_SERVER\)' 'global base URL reuses trusted proxy policy'
    foreach ($forbidden in @('array_rand(JmConfig::CDN_DOMAINS)', 'ENDPOINT_COMIC_READ', '$image[''url'']', '''manifest:'' . md5', '''chapter:'' . md5')) {
        if ($source.Contains($forbidden)) { throw "Forbidden legacy resource implementation remains: $forbidden" }
    }
}

if ($Area -in @('Verification', 'All')) {
    foreach ($snippet in @(
        'JM_REQUEST_BUDGET_MS', 'JM_MAX_UPSTREAM_ATTEMPTS',
        'JM_LIST_CACHE_TTL', 'JM_ALBUM_CACHE_TTL',
        'JM_DOMAIN_REFRESH_DEFERRED',
        'JM_PREFETCH_WALL_BUDGET_MS', 'JM_PREFETCH_BYTE_BUDGET', 'JM_PREFETCH_MAX_ACTIVE'
    )) { Assert-Contains $compose $snippet "compose $snippet" }
    if ($runtime -match '-Method\s+[''"]HEAD[''"]') {
        throw 'Runtime verifier must not use HEAD for decoded image work.'
    }
    Assert-Contains $runtime 'Try-GetImage' 'runtime verifier uses GET image helper'
    Assert-Matches $runtime 'function\s+Invoke-JmImageRequest[\s\S]*?HttpClient[\s\S]*?ResponseHeadersRead[\s\S]*?ReadAsStreamAsync[\s\S]*?CopyToAsync\(\[System\.IO\.Stream\]::Null\)' 'runtime verifier streams GET image bodies directly to Stream.Null'
    Assert-Matches $runtime 'function\s+Invoke-JmImageRequest[\s\S]*?StatusCode[\s\S]*?Headers' 'streaming image probes preserve status and response headers'
    if ($runtime -match 'Invoke-JmRequest[^\r\n]*Get-ImageUrl|Get-ImageUrl[^\r\n]*Invoke-JmRequest') {
        throw 'Runtime verifier still buffers an image body through Invoke-WebRequest.'
    }
    $streamingImageBlock = [regex]::Match($runtime, 'function\s+Invoke-JmImageRequest[\s\S]*?(?=\r?\nfunction\s+)').Value
    if ($streamingImageBlock -match '(?m)^\s*Content\s*=') {
        throw 'Streaming image result must not retain a Content body.'
    }
    Assert-Matches $compose 'JM_PREFETCH_MIN_FREE_BYTES:\s*"\$\{JM_PREFETCH_MIN_FREE_BYTES:-33554432\}"' 'compose exposes the prefetch byte waterline for deterministic low-memory verification'
    Assert-Matches $compose 'JM_PREFETCH_MIN_FREE_RATIO:\s*"\$\{JM_PREFETCH_MIN_FREE_RATIO:-15\}"' 'compose exposes the prefetch ratio waterline for deterministic low-memory verification'
    Assert-Matches $runtime "Assert-HeaderEquals\s+\`$firstImage\.Headers\s+'X-JM-Prefetch'\s+'scheduled'" 'runtime verifier requires default prefetch to schedule instead of accepting a skipped no-op'
    Assert-Matches $runtime 'Assert-True\s*\(\$attemptedDelta\s+-gt\s+0' 'runtime verifier requires default prefetch to attempt real background work'
    Assert-Matches $runtime 'Assert-True\s*\(\$prefetched\.Count\s+-gt\s+0' 'runtime verifier requires an observed prefetched HIT'
    Assert-Matches $runtime 'function\s+Get-OnDiskImageArtifacts[\s\S]*?/app[\s\S]*?/tmp[\s\S]*?(?:RIFF|89504e47|ffd8ff|47494638)' 'runtime verifier scans app and temp storage by image signature, not only filename extension'
    Assert-Matches $faultRuntime 'exec[\s\S]*?-T[\s\S]*?jmcomic-api[\s\S]*?php[\s\S]*?/app/tests/upstream-policy-runtime\.php' 'Docker fault verification executes the exact connect, Retry-After, and negative-cache policy suite'
    Assert-Contains $faultRuntime 'Upstream policy runtime checks passed.' 'Docker fault verification checks the policy-suite completion marker'
    Assert-Matches $faultRuntime "Count-Key\s+\`$counts\s+'api-good\|/latest\|0\|502-then-valid'\)\s+-eq\s+2" '502 integration verifies exactly two primary attempts'
    Assert-Matches $faultRuntime "Count-Key\s+\`$counts\s+'api-502\|/latest\|0\|502-then-valid'\)\s+-eq\s+1" '502 integration verifies exactly one secondary attempt'
    Assert-Matches $faultRuntime "Count-Key\s+\`$counts\s+'api-timeout\|/latest\|0\|502-then-valid'\)\s+-eq\s+0" '502 integration proves no third-domain attempt'
    Assert-Matches $faultRuntime '\$attempts\s+-eq\s+2[\s\S]*?429 scenario must use exactly one primary and one secondary attempt' '429 integration requires exactly two attempts'
    Assert-Matches $faultRuntime 'api-good\|/latest\|0\|\$scenario[\s\S]*?-eq\s+1[\s\S]*?api-502\|/latest\|0\|\$scenario[\s\S]*?-eq\s+1[\s\S]*?api-timeout\|/latest\|0\|\$scenario[\s\S]*?-eq\s+0' '429 integration verifies exact primary/secondary fixture counts'
    Assert-Matches $faultRuntime "Count-Key\s+\`$counts\s+'api-good\|/latest\|0\|bad-encrypted'\)\s+-eq\s+2" 'bad encrypted responses are repeated to prove they are not cached'
    Assert-Matches $faultRuntime 'refresh_suppressed_reason[\s\S]*?negative-cache[\s\S]*?thirdFallback' 'Docker domain test observes completed refresh failure and negative-cache suppression'
    Assert-Matches $faultRuntime "JM_PREFETCH_MIN_FREE_BYTES[\s\S]*?536870912[\s\S]*?X-JM-Prefetch[\s\S]*?skipped-low-memory" 'Docker fault matrix forces and verifies the low-memory prefetch path'
    Assert-Matches $faultRuntime 'cdnObservedKeys[\s\S]*?Count\s+-eq\s+2[\s\S]*?cdn-fail[\s\S]*?cdn-good' 'CDN integration rejects extra or non-allowlisted observed hosts'
    Assert-Matches $faultRuntime 'directClientIp[\s\S]*?effectiveIp\s+-eq\s+\$directClientIp' 'proxy spoof integration proves the untrusted result equals the direct REMOTE_ADDR control'
    Assert-Matches $faultRuntime "X-JM-Request-Id[\s\S]*?\^\[0-9a-f\]\{16\}\`$[\s\S]*?X-JM-Deadline-Exhausted[\s\S]*?\^\[01\]\`$" 'error diagnostics are validated by format and value domain'
}

Write-Output "Performance policy contract passed for area: $Area"
