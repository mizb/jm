$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$sourcePath = Join-Path $root 'index.php'
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8

function Assert-Matches {
    param(
        [string] $Pattern,
        [string] $Label
    )
    if ($source -notmatch $Pattern) {
        throw "Missing expected list endpoint contract pattern: $Label"
    }
}

function Assert-NotMatches {
    param(
        [string] $Pattern,
        [string] $Label
    )
    if ($source -match $Pattern) {
        throw "Unexpected list endpoint contract pattern: $Label"
    }
}

$requiredSnippets = @(
    'ENDPOINT_LATEST',
    'ENDPOINT_SEARCH',
    'ENDPOINT_CATEGORY_FILTER',
    'LOCAL_LIST_PAGE_SIZE',
    'SOURCE_LIST_PAGE_SIZE',
    'class JmListItem',
    'class JmListResult',
    'sourceListWindow',
    'windowedListResultFromItems',
    'fetchLatestList',
    'searchAlbums',
    'fetchPopularList',
    'normalizeListPage',
    'normalizeSearchQuery',
    'normalizeListMode',
    '$_GET[''list''] ?? null',
    '$_GET[''search''] ?? null',
    'buildCoverUrl',
    '''has_next_page'''
)

foreach ($snippet in $requiredSnippets) {
    if (-not $source.Contains($snippet)) {
        throw "Missing expected list endpoint contract snippet: $snippet"
    }
}

$latestBody = [regex]::Match($source, 'public function fetchLatestList\(int \$page\): JmListResult\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function fetchPopularList').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($latestBody)) {
    throw 'Could not isolate fetchLatestList body'
}
if ($latestBody -notmatch 'sourceListWindow\(\$page\)') {
    throw 'Latest list must use original jm-boom source page window calculation'
}
if ($latestBody -notmatch 'ENDPOINT_LATEST,\s*\[\s*''page''\s*=>\s*\(string\)\s*\$window\[''source_page''\]\s*\]') {
    throw 'Latest list must forward original 0-based source page to upstream latest endpoint'
}
if ($latestBody -match 'max\(0,\s*\$page\s*-\s*1\)') {
    throw 'Latest list must use sourceListWindow instead of ad-hoc page subtraction'
}
if ($latestBody -notmatch 'windowedListResultFromItems') {
    throw 'Latest list must slice upstream 80-item pages into original 20-item local pages'
}

$popularBody = [regex]::Match($source, 'public function fetchPopularList\(int \$page\): JmListResult\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function searchAlbums').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($popularBody)) {
    throw 'Could not isolate fetchPopularList body'
}
if ($popularBody -notmatch 'sourceListWindow\(\$page\)') {
    throw 'Popular list must use original jm-boom source page window calculation'
}
if ($popularBody -notmatch '''page''\s*=>\s*\(string\)\s*\$window\[''source_page''\]') {
    throw 'Popular list must forward original 0-based source page to upstream category filter endpoint'
}
if ($popularBody -notmatch '''c''\s*=>\s*''latest''[\s\S]*?''o''\s*=>\s*''new''') {
    throw 'Popular/category list must use original default category=latest and order=new'
}
if ($popularBody -notmatch 'windowedListResultFromItems') {
    throw 'Popular list must slice upstream 80-item pages into original 20-item local pages'
}

foreach ($snippet in @(
    'private static function sourceListWindow',
    '(max(1, $page) - 1) * self::LOCAL_LIST_PAGE_SIZE',
    "'source_page' => intdiv(`$start, self::SOURCE_LIST_PAGE_SIZE)",
    "'offset' => `$start % self::SOURCE_LIST_PAGE_SIZE"
)) {
    if (-not $source.Contains($snippet)) {
        throw "Missing original jm-boom list window calculation snippet: $snippet"
    }
}
Assert-Matches 'array_slice\(\$mapped,\s*\$window\[''offset''\],\s*self::LOCAL_LIST_PAGE_SIZE\)' 'list results use original offset/take slicing'
Assert-Matches 'count\(\$mapped\)\s*>\s*\$window\[''offset''\]\s*\+\s*self::LOCAL_LIST_PAGE_SIZE' 'list has_next_page follows original offset plus local page size logic'
Assert-Matches 'count\(\$mapped\)\s*>=\s*self::SOURCE_LIST_PAGE_SIZE' 'list has_next_page follows original full source page logic'

if ($latestBody -match 'ENDPOINT_LATEST,\s*\[\s*''page''\s*=>\s*\(string\)\s*\$page\s*\]') {
    throw 'Latest list must not pass client page directly to upstream'
}

Write-Output 'List endpoint contract snippets found.'
