$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$sourcePath = Join-Path $root 'index.php'
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8

$requiredSnippets = @(
    'ENDPOINT_LATEST',
    'ENDPOINT_SEARCH',
    'ENDPOINT_CATEGORY_FILTER',
    'class JmListItem',
    'class JmListResult',
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

Write-Output 'List endpoint contract snippets found.'
