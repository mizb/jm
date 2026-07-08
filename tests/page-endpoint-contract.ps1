$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$sourcePath = Join-Path $root 'index.php'
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8

function Assert-Contains {
    param([string] $Snippet)
    if (-not $source.Contains($Snippet)) {
        throw "Missing expected page endpoint contract snippet: $Snippet"
    }
}

function Assert-NotContains {
    param([string] $Snippet)
    if ($source.Contains($Snippet)) {
        throw "Unexpected page endpoint contract snippet: $Snippet"
    }
}

function Assert-Matches {
    param(
        [string] $Pattern,
        [string] $Label
    )
    if ($source -notmatch $Pattern) {
        throw "Missing expected page endpoint contract pattern: $Label"
    }
}

$requiredSnippets = @(
    '$_GET[''page''] ?? null',
    'function sendBinaryImage',
    'validatePageParam',
    'validateNumericChapterId',
    'function isDirectNumericChapterImageRequest',
    'isDirectNumericChapterImageRequest($chapterParam, $pageParam)',
    'fetchDecodedPage',
    'downloadImage',
    'ScrambleDecoder::decodeBytes',
    'decodeBytesWithInfo',
    'preferredDecodedMime',
    'final class MemoryCache',
    'apcu_fetch',
    'apcu_store',
    'apcu_add',
    'compareAndDelete',
    'apcu_cache_info',
    'apcu_sma_info',
    '''apcu_details''',
    '''free_memory_bytes''',
    '''used_memory_bytes''',
    '''free_ratio''',
    'JM_PREFETCH_PAGES',
    'JM_PREFETCH_HIGH_PRIORITY_PAGES',
    'JM_PREFETCH_MIN_FREE_BYTES',
    'JM_PREFETCH_MIN_FREE_RATIO',
    'JM_PAGE_CACHE_MIN_FREE_BYTES',
    'JM_PAGE_CACHE_MIN_FREE_RATIO',
    'JM_SINGLEFLIGHT_LOCK_TTL',
    'JM_SINGLEFLIGHT_WAIT_MS',
    'JM_NEXT_CHAPTER_PREFETCH',
    'JM_DOMAIN_COOLDOWN_SECONDS',
    'JM_DOMAIN_STATS_TTL',
    'DEFAULT_PREFETCH_PAGES = 10',
    'DEFAULT_PREFETCH_HIGH_PRIORITY_PAGES = 2',
    'prefetchDecodedPages',
    'maybePrefetchPages',
    'fetchReaderManifest',
    'readerManifestCacheKey',
    'singleFlight',
    'materializeDecodedPage',
    'prefetchWaterlineOk',
    'pageCacheWaterlineOk',
    'isDecodedPageCached',
    '''cache_hit''',
    '''singleflight''',
    '''cache_store''',
    '''prefetch''',
    '''codec''',
    'X-JM-Image-Codec',
    'X-JM-Singleflight',
    'X-JM-Prefetch',
    'X-JM-Cache-Store',
    'X-JM-APCu-Free',
    'pageNameForScramble',
    'pathinfo($clean, PATHINFO_FILENAME)',
    'imagewebp($dst, null, self::WEBP_QUALITY)',
    'imagejpeg($dst, null, self::JPEG_QUALITY)',
    '$extension === ''gif'' || $segments === 0',
    'function requestBaseUrl',
    'function buildDecodedPageUrl',
    'next_chapter',
    '''source_url''',
    '''mime''',
    '$ch->toArray($album->id, $publicBaseUrl, $nextChapterMap[$ch->photoId] ?? null)',
    'normalizeEpisodes',
    '$seenPhotoIds',
    'episodeSortValue'
)

foreach ($snippet in $requiredSnippets) {
    Assert-Contains $snippet
}

$pageRangeMessage = (-join @([char]0x9875, [char]0x7801)) + ' {$page} ' + (-join @([char]0x8D85, [char]0x51FA, [char]0x8303, [char]0x56F4))
$mojibakePagePrefix = -join @([char]0x6924, [char]0x7535, [char]0x721C)
Assert-Contains $pageRangeMessage
Assert-NotContains $mojibakePagePrefix
$domainUnavailableMessage = (-join @([char]0x0041, [char]0x0050, [char]0x0049, [char]0x0020, [char]0x57DF, [char]0x540D, [char]0x5168, [char]0x90E8, [char]0x4E0D, [char]0x53EF, [char]0x7528))
$mojibakeDomainPrefix = -join @([char]0x934C, [char]0x9369)
Assert-Contains $domainUnavailableMessage
Assert-NotContains $mojibakeDomainPrefix

Assert-Matches 'if\s*\(isDirectNumericChapterImageRequest\(\$chapterParam,\s*\$pageParam\)\)\s*\{[\s\S]*?\$service->fetchDecodedPage\(\$chapterId,\s*\$page\)[\s\S]*?sendBinaryImage\(' 'numeric chapter + page direct image path before album fetch'
Assert-Matches 'try\s*\{[\s\S]*?\$album\s*=\s*\$service->fetchAlbum\(\$jmid\);' 'album fetch remains only after direct image branch'
Assert-Matches 'fetchDecodedPage[\s\S]*?\$manifest\s*=\s*\$this->fetchReaderManifest\(\$photoId\)[\s\S]*?\$image\s*=\s*\$manifest\[''images''\]\[\$page - 1\]' 'decoded page flow uses lightweight reader manifest'
Assert-Matches 'singleFlight[\s\S]*?tryAdd\(\$lockKey,\s*\$token,\s*\$lockTtl\)[\s\S]*?cachedDecodedPage\(\$cacheKey\)' 'single-flight uses atomic add and rechecks cache after lock acquisition'
Assert-Matches 'singleFlight[\s\S]*?while\s*\(\(int\)\s*round\(\(microtime\(true\)\s*-\s*\$waitStart\)\s*\*\s*1000\)\s*<\s*\$waitMs\)' 'single-flight wait is bounded'
Assert-Matches 'singleFlight[\s\S]*?compareAndDelete\(\$lockKey,\s*\$token\)' 'single-flight releases lock only by token'
Assert-Matches 'prefetchDecodedPages[\s\S]*?\$highPriorityPages[\s\S]*?\$lowPriorityPages' 'prefetch plans high and low priority pages'
Assert-Matches 'prefetchDecodedPages[\s\S]*?isDecodedPageCached\(\$photoId,\s*\$candidatePage\)[\s\S]*?continue;' 'prefetch skips already cached pages'
Assert-Matches 'prefetchDecodedPages[\s\S]*?prefetchWaterlineOk\(\)[\s\S]*?break;' 'prefetch stops low-priority work when APCu memory is low'
Assert-Matches 'maybePrefetchPages[\s\S]*?\$nextChapterId[\s\S]*?shouldPrefetchNextChapter\(\$photoId,\s*\$page,\s*\$nextChapterId\)' 'near-end next chapter preheat is scheduled only from an explicit next chapter hint'
Assert-NotContains '$this->fetchAlbum($jmid)'
Assert-Matches 'decodeBytesWithInfo[\s\S]*?imagewebp\(\$dst,\s*null,\s*self::WEBP_QUALITY\)[\s\S]*?imagejpeg\(\$dst,\s*null,\s*self::JPEG_QUALITY\)' 'decoded image prefers WebP and falls back to JPEG 85'
Assert-Matches 'foreach\s*\(\$photoIds\s+as\s+\$pid\)\s*\{[\s\S]*?\$scrambleId\s*=\s*\$this->fetchScrambleId\(\$pid\);[\s\S]*?\$chapters\[\]\s*=\s*\$this->fetchChapter\(\$pid,\s*\$scrambleId\);' 'batch chapter fetch uses each chapter photo_id scramble id'
Assert-Matches 'final class DomainHealth[\s\S]*?domain-health:[\s\S]*?failure_streak[\s\S]*?ewma_latency_ms' 'domain health scoring stores cooldown and EWMA latency state'
Assert-Matches 'orderedDomains[\s\S]*?domainsInOriginalOrder[\s\S]*?usort' 'domain health scoring sorts healthy domains before upstream requests'
Assert-NotContains '$scrambleId = $service->fetchScrambleId($fetchIds[0]);'
Assert-NotContains '$seen[$ep[''sort'']]'
Assert-Contains 'preg_match(''/^@\d+$/'', $param)'
Assert-Contains 'private static function normalizeJmId'
Assert-Contains 'strlen($id) > JmConfig::JMID_MAX_LENGTH'
Assert-Contains 'self::normalizeJmId($m[1])'

Write-Output 'Page endpoint contract snippets found.'
