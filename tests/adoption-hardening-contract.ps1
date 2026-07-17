$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$sourcePath = Join-Path $root 'index.php'
$readmePath = Join-Path $root 'README.md'
$implementationPath = Join-Path $root 'docs\jmcomic-python-reference-adoption-implementation.md'
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8
$readme = Get-Content -LiteralPath $readmePath -Raw -Encoding UTF8
$implementation = Get-Content -LiteralPath $implementationPath -Raw -Encoding UTF8
$httpGetBody = [regex]::Match($source, 'public function get\(string \$url, array \$headers, int \$timeoutMs, \?int \$bodyLimitBytes = null\): HttpResult\s*\{(?<body>[\s\S]*?)\r?\n    \}\r?\n\}').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($httpGetBody)) {
    throw 'Could not isolate JmHttpClient::get body'
}

function Assert-Contains {
    param(
        [string] $Text,
        [string] $Snippet,
        [string] $Label
    )
    if (-not $Text.Contains($Snippet)) {
        throw "Missing adoption hardening snippet: $Label"
    }
}

function Assert-Matches {
    param(
        [string] $Text,
        [string] $Pattern,
        [string] $Label
    )
    if ($Text -notmatch $Pattern) {
        throw "Missing adoption hardening pattern: $Label"
    }
}

function Assert-NotMatches {
    param(
        [string] $Text,
        [string] $Pattern,
        [string] $Label
    )
    if ($Text -match $Pattern) {
        throw "Unexpected adoption hardening pattern: $Label"
    }
}

foreach ($snippet in @(
    'final class ApiFailure',
    'KIND_NETWORK',
    'KIND_HTTP_RETRYABLE',
    'KIND_HTTP_CLIENT',
    'KIND_BUSINESS',
    'KIND_ENVELOPE_JSON',
    'KIND_ENVELOPE_SHAPE',
    'KIND_DECRYPT',
    'KIND_PAYLOAD_JSON',
    'KIND_PAYLOAD_SHAPE',
    'KIND_SCRAMBLE_TEMPLATE',
    'private function recordApiFailure',
    'public function shouldRetry',
    'json_last_error_msg()',
    'last_failure_kind',
    'last_failure_message'
)) {
    Assert-Contains $source $snippet $snippet
}

Assert-Contains $source 'function apiDomainDiagnostics' 'health diagnostics select active cached API domains when available'
Assert-Matches $source 'apiDomainDiagnostics\(MemoryCache \$cache, RequestContext \$context\)[\s\S]*?new DomainResolver\(\$context, \$cache\)' 'health diagnostics use the read-only domain resolver'
Assert-Matches $source '''domains''\s*=>\s*apiDomainDiagnostics\(\$memoryCache, \$healthContext\)' 'health endpoint uses active domain diagnostics helper'
Assert-Contains $source 'public static function normalizeApiDomains' 'API domain resolver exposes one normalizer for requests and diagnostics'
Assert-Matches $source 'validatedState[\s\S]*?JmApiClient::normalizeApiDomains\(\$value\[''domains''\]\s*\?\?\s*null\)' 'cached API domains are normalized before use'
Assert-Matches $source 'decodeDomainConfig[\s\S]*?JmApiClient::normalizeApiDomains\(\$decoded\[''Server''\]\s*\?\?\s*null\)' 'remote API domains are normalized before cache/use'
Assert-Matches $source 'markFailure\(string \$domain,\s*bool \$hardFailure\s*=\s*true,\s*string \$kind\s*=\s*''unknown''' 'domain health records failure kind'
Assert-Matches $source '\$failure->hardDomainFailure\(\)' 'api failure classifier decides whether to punish a domain'
Assert-Matches $source 'ApiFailure::http\(\$result->status\)' 'structured HTTP response status is classified before domain scoring'
Assert-Matches $source 'ApiFailure::business\(\$code' 'JM business errors are classified separately from domain failures'
Assert-Matches $source 'ApiFailure::payloadJson\(' 'decrypted payload JSON failures are classified'
Assert-Matches $source 'throw ApiFailure::publicException\(\$lastFailure\)' 'final API exception preserves classified failure'
Assert-Matches $source 'if\s*\(!\$lastFailure->shouldRetry\(\)\)\s*\{\s*throw ApiFailure::publicException\(\$lastFailure\);\s*\}' 'non-retryable API failures stop instead of rotating all domains'
Assert-Matches $source 'return in_array\(\$this->kind,\s*\[\s*self::KIND_NETWORK,\s*self::KIND_HTTP_RETRYABLE\s*\],\s*true\)' 'only network and retryable HTTP failures are retried'
Assert-Matches $source 'fetchScrambleId\(string \$photoId\): string[\s\S]*?if\s*\(!\$lastFailure->shouldRetry\(\)\)\s*\{[\s\S]*?recordScrambleFallback\(\$photoId,\s*\$lastFailure\)[\s\S]*?return \(string\) JmConfig::SCRAMBLE_220980;' 'non-retryable scramble template failures fall back without rotating all domains'
Assert-Matches $httpGetBody '\$statusCode\s*=\s*\(int\)\s*curl_getinfo\(\$this->ch,\s*CURLINFO_HTTP_CODE\)' 'HTTP client captures status before empty-body handling'
Assert-NotMatches $httpGetBody 'if\s*\(\$body\s*===\s*false\s*\|\|\s*\$body\s*===\s*''''\)' 'HTTP client must not classify all empty bodies as network failures'

foreach ($snippet in @(
    'final class PayloadNormalizer',
    'scalarString',
    'scalarInt',
    'listArray',
    'stringList',
    'PayloadNormalizer::identifierString($data[''id''] ?? $fallbackAlbumId)',
    'PayloadNormalizer::listArray($data[''series''] ?? [])',
    'normalizeChapterImages($data[''images''])',
    'PayloadNormalizer::identifierString($item[''id''] ?? $item[''aid''] ?? $item[''AID''] ?? '''')'
)) {
    Assert-Contains $source $snippet $snippet
}

Assert-Matches $source 'JmAlbum::fromApiResponse\(\$resp\[''data''\],\s*\$jmid\)' 'album model falls back to requested jmid when upstream id is missing'
Assert-Matches $source 'JmChapter::fromApiResponse\(\$resp\[''data''\],\s*\$scrambleId,\s*\$photoId\)' 'chapter model validates against the requested photo id'
Assert-Matches $source 'fromApiResponse\(array \$data,\s*string \$fallbackAlbumId' 'album fromApiResponse accepts fallback album id'
Assert-Matches $source 'fromApiResponse\(array \$data,\s*string \$scrambleId,\s*string \$requestedPhotoId' 'chapter fromApiResponse accepts the requested photo id validator input'
Assert-Matches $source '\$rawResponsePhotoId === null[\s\S]*?trim\(\$rawResponsePhotoId\) === ''''[\s\S]*?\$responsePhotoId = \$requestedPhotoId' 'missing or empty upstream chapter id falls back to requested id'
Assert-Matches $source 'is_string\(\$rawResponsePhotoId\)\s*\|\|\s*is_int\(\$rawResponsePhotoId\)[\s\S]*?\$responsePhotoId = \(string\) \$rawResponsePhotoId' 'string and integer upstream chapter ids share one canonical string validator'
Assert-Matches $source '\$responsePhotoId !== \$requestedPhotoId' 'present upstream chapter id must match requested id'
Assert-Matches $source 'function identifierString\(mixed \$value,[\s\S]*?is_string\(\$value\)\s*\|\|\s*is_int\(\$value\)[\s\S]*?return \$default' 'upstream identifiers accept only strings or integers before role-specific validation'
Assert-Contains $source 'isUnsupportedHomeSection(PayloadNormalizer::scalarString($section[''title''] ?? ''''))' 'promote section title is scalar-normalized'
Assert-Contains $source '$itemId = PayloadNormalizer::scalarString($item[''id''] ?? $item[''aid''] ?? $item[''AID''] ?? '''')' 'promote item id is scalar-normalized'
Assert-Contains $source 'PayloadNormalizer::identifierString($category[''id''] ?? '''')' 'weekly category id is identifier-normalized'
Assert-Contains $source 'PayloadNormalizer::identifierString($type[''id''] ?? '''')' 'weekly type id is identifier-normalized'
Assert-Contains $source 'PayloadNormalizer::identifierString($rawRedirectAid ?? '''')' 'search redirect aid is identifier-normalized'
Assert-Contains $source 'private static function listItemFromPayload' 'list item mapper centralizes empty-id filtering'
Assert-Contains $source 'if ($mappedItem->id === '''') return null;' 'list item mapper skips payloads without usable ids'
Assert-Contains $source 'private static function payloadItemCount' 'list pagination keeps raw payload item counts separate from valid mapped items'
Assert-Matches $source 'listResultFromItems[\s\S]*?\$sourceItemCount\s*=\s*self::payloadItemCount\(\$items\)' 'regular list pagination tracks source payload count'
Assert-Matches $source 'searchListResultFromItems[\s\S]*?\$sourceItemCount\s*=\s*self::payloadItemCount\(\$items\)' 'search pagination tracks source payload count'
Assert-Matches $source 'windowedListResultFromItems[\s\S]*?\$sourceItemCount\s*=\s*self::payloadItemCount\(\$items\)' 'windowed list pagination tracks source payload count'
Assert-Matches $source 'searchListResultFromItems[\s\S]*?count\(\$mapped\)\s*>=\s*self::SOURCE_LIST_PAGE_SIZE' 'search pagination contract still exposes mapped-count expression for legacy static checks'
Assert-Matches $source 'searchListResultFromItems[\s\S]*?\$sourceItemCount\s*>=\s*self::SOURCE_LIST_PAGE_SIZE' 'search has_next_page uses source count after invalid item filtering'
Assert-Matches $source 'windowedListResultFromItems[\s\S]*?\$sourceItemCount\s*>=\s*\$sourcePageSize' 'windowed has_next_page uses source count after invalid item filtering'
Assert-Matches $source 'listResultFromItems[\s\S]*?self::listItemFromPayload\(\$item\)' 'regular list results use central list item mapper'
Assert-Matches $source 'searchListResultFromItems[\s\S]*?self::listItemFromPayload\(\$item\)' 'search list results use central list item mapper'
Assert-Matches $source 'windowedListResultFromItems[\s\S]*?self::listItemFromPayload\(\$item\)' 'windowed list results use central list item mapper'
Assert-NotMatches $source '\(string\)\s*\$data\[''id''\]' 'album/chapter models must not assume upstream id exists'
Assert-NotMatches $source 'foreach\s*\(\$data\[''series''\]\s*\?\?\s*\[\]\s+as' 'series must be normalized before iteration'
Assert-NotMatches $source 'foreach\s*\(\$data\[''images''\]\s*\?\?\s*\[\]\s+as' 'images must be normalized before iteration'

foreach ($snippet in @(
    'REDIS_HOST',
    'REDIS_PORT',
    'REDIS_TIMEOUT_MS',
    'addToExpiringSetAndCount',
    '$count = $this->store->addToExpiringSetAndCount($key, $jmid, 600)',
    'function safeErrorExtras',
    'numeric direct image requests validate chapter id format and page bounds, not album membership'
)) {
    Assert-Contains $source $snippet $snippet
}

Assert-Matches $source 'addToExpiringSetAndCount[\s\S]*?zRemRangeByScore\(\$key,\s*''-inf'',\s*\(string\)\s*\(\$now\s*-\s*\$ttl\)\)' 'distinct jmid guard removes expired members before counting'
Assert-Matches $source 'addToExpiringSetAndCount[\s\S]*?zAdd\(\$key,\s*\(float\)\s*\$now,\s*\$member\)' 'distinct jmid guard stores last-seen timestamp per member'
Assert-Matches $source 'addToExpiringSetAndCount[\s\S]*?zCard\(\$key\)' 'distinct jmid guard counts active distinct members'
Assert-NotMatches $source 'addToExpiringSetAndCount[\s\S]*?sAdd\(\$key,\s*\$member\)' 'distinct jmid guard must not use a plain set with refreshed TTL'
$checkRateMatch = [regex]::Match($source, 'public function checkRate\(string \$key, int \$window, int \$max\): array\s*\{(?<body>[\s\S]*?)\r?\n    \}')
if (-not $checkRateMatch.Success) { throw 'Could not isolate RedisStore::checkRate body' }
$checkRateBody = $checkRateMatch.Groups['body'].Value
foreach ($snippet in @(
    'interface RedisAdapter',
    'interface RedisAdapterFactory',
    'final class RedisFailureCircuit',
    'RATE_LIMIT_LUA',
    'ZREMRANGEBYSCORE',
    'ZCARD',
    'ZRANGE',
    'ZADD',
    'EXPIRE'
)) {
    Assert-Contains $source $snippet "Redis atomic/lazy contract $snippet"
}
Assert-Matches $checkRateBody 'eval\(' 'Redis rate limiter executes one Lua EVAL'
Assert-NotMatches $checkRateBody '->zRemRangeByScore|->zCard|->zRange|->zAdd|->expire' 'Redis rate limiter must not issue direct multi-command zset operations'
Assert-Matches $source 'RATE_LIMIT_LUA[\s\S]*?if\s+count\s*>=\s*maxRequests[\s\S]*?return\s+\{0,\s*0,\s*retryAfter\}[\s\S]*?ZADD[\s\S]*?return\s+\{1,' 'Redis Lua performs conditional add and returns retry-after atomically'
Assert-NotMatches $source 'checkBruteForce[\s\S]*?\$this->store->incr\(\$key,\s*600\)' 'brute force guard must count distinct jmids, not every request'
Assert-Matches $source 'if\s*\(\$code\s*>=\s*500\)\s*\{[\s\S]*?safeErrorExtras\(' '5xx sendError extras are whitelisted'
Assert-Contains $readme 'Numeric direct image requests validate chapter id format and page bounds, not album membership.' 'README documents direct image validation boundary'
Assert-Matches $readme 'jmid[^\r\n]*20' 'README jmid length matches JmConfig::JMID_MAX_LENGTH'
foreach ($snippet in @('REDIS_HOST', 'REDIS_PORT', 'REDIS_TIMEOUT_MS')) {
    Assert-Contains $readme $snippet "README documents optional Redis setting $snippet"
}
Assert-Contains $readme '502' 'README 502 error code exists'
$upstreamUnavailable = (-join @([char]0x4E0A, [char]0x6E38)) + ' API ' + (-join @([char]0x4E0D, [char]0x53EF, [char]0x7528))
$malformedUpstream = -join @([char]0x54CD, [char]0x5E94, [char]0x5F02, [char]0x5E38)
Assert-Contains $readme $upstreamUnavailable 'README 502 error code documents upstream unavailability'
Assert-Contains $readme $malformedUpstream 'README 502 error code documents malformed upstream responses'
Assert-Contains $implementation 'Redis sorted set' 'implementation notes document sorted-set distinct jmid guard'
Assert-NotMatches $implementation 'Redis set instead of incrementing' 'implementation notes must not describe distinct jmid guard as a plain set'

Write-Output 'Adoption hardening contract snippets found.'
