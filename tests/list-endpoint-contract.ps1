$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$sourcePath = Join-Path $root 'index.php'
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8
$readmePath = Join-Path $root 'README.md'
$readme = Get-Content -LiteralPath $readmePath -Raw -Encoding UTF8

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
    'ENDPOINT_PROMOTE',
    'ENDPOINT_PROMOTE_LIST',
    'ENDPOINT_WEEK',
    'ENDPOINT_WEEK_FILTER',
    'LOCAL_LIST_PAGE_SIZE',
    'SOURCE_LIST_PAGE_SIZE',
    'class JmListItem',
    'class JmListResult',
    'sourceListWindow',
    'windowedListResultFromItems',
    'fetchLatestList',
    'searchAlbums',
    'fetchPopularList',
    'fetchPromoteList',
    'fetchWeeklyList',
    'searchListResultFromItems',
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

$promoteBody = [regex]::Match($source, 'public function fetchPromoteList\(int \$page, int \$sectionId = 0\): JmListResult\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function fetchWeeklyList').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($promoteBody)) {
    throw 'Could not isolate fetchPromoteList body'
}
if ($promoteBody -notmatch 'promoteListWindow\(\$page\)') {
    throw 'Promote list must use original jm-boom 27-item source page window calculation'
}
if ($promoteBody -notmatch 'fetchPromoteHomeList\(\$page\)') {
    throw 'Promote list without a section id must use original homepage promote feed'
}
if ($source -notmatch 'UNSUPPORTED_HOME_SECTION_TITLES') {
    throw 'Promote homepage list must preserve original unsupported section title filter'
}
if ($source -notmatch 'isUnsupportedHomeSection') {
    throw 'Promote homepage list must use an unsupported section filter helper'
}
if ($source -notmatch '\$seenIds\s*=\s*\[\]') {
    throw 'Promote homepage flattened list must track seen ids to avoid duplicate manga'
}
if ($source -notmatch 'isset\(\$seenIds\[\$itemId\]\)') {
    throw 'Promote homepage flattened list must skip duplicate manga ids'
}
if ($promoteBody -notmatch 'ENDPOINT_PROMOTE_LIST') {
    throw 'Promote list must call original promote_list endpoint'
}
if ($promoteBody -notmatch '''id''\s*=>\s*\(string\)\s*\$sectionId') {
    throw 'Promote list must forward original promote section id'
}
if ($promoteBody -notmatch 'windowedListResultFromItems') {
    throw 'Promote list must slice upstream 27-item pages into local pages'
}
if ($promoteBody -notmatch 'while\s*\(count\(\$buffer\)\s*<\s*\$window\[''offset''\]\s*\+\s*self::LOCAL_LIST_PAGE_SIZE') {
    throw 'Promote list must fetch enough 27-item source pages to fill a 20-item local page'
}
if ($promoteBody -notmatch '\$sourcePage\s*=\s*\$sourcePage\s*\+\s*1') {
    throw 'Promote list must advance source pages while filling the local page'
}
if ($promoteBody -notmatch '\$window\[''source_has_more''\]\s*=\s*\$sourceHasMore') {
    throw 'Promote list must pass known upstream has-more state into result slicing'
}
if ($promoteBody -match 'array_push\(\$buffer,\s*\.\.\.\$items\)') {
    throw 'Promote list must not call array_push with a variadic empty item list risk'
}

$weeklyBody = [regex]::Match($source, 'public function fetchWeeklyList\(int \$page, \?string \$categoryId = null, \?string \$typeId = null\): JmListResult\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function searchAlbums').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($weeklyBody)) {
    throw 'Could not isolate fetchWeeklyList body'
}
if ($weeklyBody -notmatch 'fetchWeekDefaults\(\)') {
    throw 'Weekly picks must load original week defaults when category/type are not supplied'
}
if ($weeklyBody -notmatch 'ENDPOINT_WEEK_FILTER') {
    throw 'Weekly picks must call original week/filter endpoint'
}
if ($weeklyBody -notmatch '''id''\s*=>\s*\$categoryId') {
    throw 'Weekly picks must forward original weekly category id'
}
if ($weeklyBody -notmatch '''type''\s*=>\s*\$typeId') {
    throw 'Weekly picks must forward original weekly type id'
}
if ($weeklyBody -notmatch 'listResultFromItems') {
    throw 'Weekly picks must parse original week/filter list payload'
}

$searchBody = [regex]::Match($source, 'public function searchAlbums\(string \$query, int \$page, string \$order = ''mr''\): JmListResult\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function fetchScrambleId').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($searchBody)) {
    throw 'Could not isolate searchAlbums body'
}
if ($searchBody -notmatch 'searchListResultFromItems\(') {
    throw 'Search results must use original jm-boom 80-item source page pagination'
}
Assert-Matches 'searchListResultFromItems[\s\S]*?\$loaded\s*=\s*\(\$page\s*-\s*1\)\s*\*\s*self::SOURCE_LIST_PAGE_SIZE\s*\+\s*\$sourceItemCount' 'search pagination must use original 80-item source page size'
Assert-Matches 'searchListResultFromItems[\s\S]*?\$sourceItemCount\s*>=\s*self::SOURCE_LIST_PAGE_SIZE' 'search has_next_page must require a full upstream source page'
Assert-Matches 'searchListResultFromItems[\s\S]*?\(\$total\s*<=\s*0\s*\|\|\s*\$loaded\s*<\s*\$total\)' 'search has_next_page must respect total after 80-item source pages'
Assert-Matches '\$item\[''updated_at''\]\s*\?\?\s*\$item\[''update_at''\]' 'list item timestamp must accept both updated_at and update_at payload fields'
$invalidPromoteSectionMessage = -join @([char]0x65E0, [char]0x6548, [char]0x7684, [char]0x63A8, [char]0x8350, [char]0x5206, [char]0x533A)
$invalidWeeklyFilterMessage = -join @([char]0x65E0, [char]0x6548, [char]0x7684, [char]0x6BCF, [char]0x5468, [char]0x63A8, [char]0x8350, [char]0x7B5B, [char]0x9009, [char]0x53C2, [char]0x6570)
$missingSearchMessage = -join @([char]0x7F3A, [char]0x5C11, [char]0x641C, [char]0x7D22, [char]0x5173, [char]0x952E, [char]0x8BCD)
$longSearchMessage = -join @([char]0x641C, [char]0x7D22, [char]0x5173, [char]0x952E, [char]0x8BCD, [char]0x8FC7, [char]0x957F)
$mojibakePrefix = -join @([char]0x93C3, [char]0x7282)
foreach ($message in @($invalidPromoteSectionMessage, $invalidWeeklyFilterMessage, $missingSearchMessage, $longSearchMessage)) {
    if (-not $source.Contains($message)) {
        throw "Missing readable list/search validation message: $message"
    }
}
if ($source.Contains($mojibakePrefix)) {
    throw "List/search validation messages contain mojibake"
}
Assert-NotMatches 'WEEKLY_SOURCE_PAGE_SIZE' 'old serialization weekly page-size constant'
Assert-NotMatches 'weeklyListWindow' 'old serialization weekly window helper'
Assert-NotMatches 'currentChinaWeekday' 'old serialization weekday helper'
if ($source -notmatch '\$loaded\s*=\s*\(\$page\s*-\s*1\)\s*\*\s*self::LOCAL_LIST_PAGE_SIZE\s*\+\s*\$sourceItemCount') {
    throw 'List result has_next_page must use stable local page size and raw source item count, not current valid item count'
}
if ($source -match '\$pageSize\s*=\s*max\(1,\s*count\(\$mapped\)\)') {
    throw 'List result must not use current item count as page size for total-based pagination'
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
Assert-Matches '\$sourcePageSize\s*=\s*max\(1,\s*\(int\)\s*\(\$window\[''source_page_size''\]\s*\?\?\s*self::SOURCE_LIST_PAGE_SIZE\)\)' 'list window carries per-mode source page size'
Assert-Matches 'array_key_exists\(''source_has_more'',\s*\$window\)' 'list result can use known upstream has-more state'
Assert-Matches '\$sourceItemCount\s*>=\s*\$sourcePageSize' 'list has_next_page follows per-mode full source page logic'
Assert-Matches '''promote''\s*=>\s*\$service->fetchPromoteList' 'list router exposes original promote mode'
Assert-Matches '''weekly''\s*=>\s*\$service->fetchWeeklyList' 'list router exposes original weekly mode'
Assert-Matches '''recommend''\s*=>\s*''promote''' 'list mode normalizes recommend alias to promote'
Assert-Matches '''week''\s*=>\s*''weekly''' 'list mode normalizes week alias to weekly'

foreach ($snippet in @(
    'list=promote',
    'list=weekly',
    'section',
    'week',
    'category'
)) {
    if (-not $readme.Contains($snippet)) {
        throw "README must document original homepage list mode: $snippet"
    }
}

if ($latestBody -match 'ENDPOINT_LATEST,\s*\[\s*''page''\s*=>\s*\(string\)\s*\$page\s*\]') {
    throw 'Latest list must not pass client page directly to upstream'
}

Write-Output 'List endpoint contract snippets found.'
