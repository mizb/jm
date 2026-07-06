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
    'apcu_cache_info',
    'apcu_sma_info',
    '''apcu_details''',
    '''free_memory_bytes''',
    '''used_memory_bytes''',
    'JM_PREFETCH_PAGES',
    'DEFAULT_PREFETCH_PAGES = 10',
    'prefetchDecodedPages',
    'maybePrefetchPages',
    'isDecodedPageCached',
    '''cache_hit''',
    '''codec''',
    'X-JM-Image-Codec',
    'pageNameForScramble',
    'pathinfo($clean, PATHINFO_FILENAME)',
    'imagewebp($dst, null, self::WEBP_QUALITY)',
    'imagejpeg($dst, null, self::JPEG_QUALITY)',
    '$extension === ''gif'' || $segments === 0',
    'function requestBaseUrl',
    'function buildDecodedPageUrl',
    '''source_url''',
    '''mime''',
    '$ch->toArray($album->id, $publicBaseUrl)',
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

Assert-Matches 'if\s*\(isDirectNumericChapterImageRequest\(\$chapterParam,\s*\$pageParam\)\)\s*\{[\s\S]*?\$service->fetchDecodedPage\(\$chapterId,\s*\$page\)[\s\S]*?sendBinaryImage\(' 'numeric chapter + page direct image path before album fetch'
Assert-Matches 'try\s*\{[\s\S]*?\$album\s*=\s*\$service->fetchAlbum\(\$jmid\);' 'album fetch remains only after direct image branch'
Assert-Matches 'prefetchDecodedPages[\s\S]*?isDecodedPageCached\(\$photoId,\s*\$candidatePage\)[\s\S]*?continue;' 'prefetch skips already cached pages'
Assert-Matches 'decodeBytesWithInfo[\s\S]*?imagewebp\(\$dst,\s*null,\s*self::WEBP_QUALITY\)[\s\S]*?imagejpeg\(\$dst,\s*null,\s*self::JPEG_QUALITY\)' 'decoded image prefers WebP and falls back to JPEG 85'
Assert-Matches 'foreach\s*\(\$photoIds\s+as\s+\$pid\)\s*\{[\s\S]*?\$scrambleId\s*=\s*\$this->fetchScrambleId\(\$pid\);[\s\S]*?\$chapters\[\]\s*=\s*\$this->fetchChapter\(\$pid,\s*\$scrambleId\);' 'batch chapter fetch uses each chapter photo_id scramble id'
Assert-NotContains '$scrambleId = $service->fetchScrambleId($fetchIds[0]);'
Assert-NotContains '$seen[$ep[''sort'']]'
Assert-Contains 'preg_match(''/^@\d+$/'', $param)'
Assert-Contains 'private static function normalizeJmId'
Assert-Contains 'strlen($id) > JmConfig::JMID_MAX_LENGTH'
Assert-Contains 'self::normalizeJmId($m[1])'

Write-Output 'Page endpoint contract snippets found.'
