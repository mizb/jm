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

function Get-PhpFunctionBlock {
    param(
        [string] $Text,
        [string] $Name
    )
    $declarationPattern = '(?m)^[ \t]*(?:(?:public|private|protected)[ \t]+)?(?:static[ \t]+)?function[ \t]+{0}[ \t]*\(' -f [regex]::Escape($Name)
    $declaration = [regex]::Match($Text, $declarationPattern)
    if (-not $declaration.Success) {
        throw "Could not isolate PHP function declaration: $Name"
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
if ($latestBody -notmatch '\$sourcePage\s*=\s*\(int\)\s*\$window\[''source_page''\]' -or
    $latestBody -notmatch 'ENDPOINT_LATEST,\s*\[\s*''page''\s*=>\s*\(string\)\s*\$sourcePage\s*\]') {
    throw 'Latest list must forward original 0-based source page to upstream latest endpoint'
}
if ($latestBody -match 'max\(0,\s*\$page\s*-\s*1\)') {
    throw 'Latest list must use sourceListWindow instead of ad-hoc page subtraction'
}
if ($latestBody -notmatch 'windowedListResultFromItems') {
    throw 'Latest list must slice upstream 80-item pages into original 20-item local pages'
}

$popularBody = [regex]::Match($source, 'public function fetchPopularList\(int \$page, string \$order = ''new''\): JmListResult\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function fetchPromoteList').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($popularBody)) {
    throw 'Could not isolate fetchPopularList body'
}
if ($popularBody -notmatch 'sourceListWindow\(\$page\)') {
    throw 'Popular list must use original jm-boom source page window calculation'
}
if ($popularBody -notmatch '\$sourcePage\s*=\s*\(int\)\s*\$window\[''source_page''\]' -or
    $popularBody -notmatch '''page''\s*=>\s*\(string\)\s*\$sourcePage') {
    throw 'Popular list must forward original 0-based source page to upstream category filter endpoint'
}
if ($popularBody -notmatch 'normalizeCatalogOrder\(\$order\)') {
    throw 'Popular/category list must re-normalize internal order before upstream use'
}
if ($popularBody -notmatch '''c''\s*=>\s*''latest''[\s\S]*?''o''\s*=>\s*\$order') {
    throw 'Popular/category list must use category=latest and forward normalized order'
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
Assert-Matches 'function\s+normalizeCatalogOrder\(mixed\s+\$value\):\s*string' 'catalog order uses a separate normalizer'
Assert-Matches 'in_array\(\$order,\s*\[''new'',\s*''mv'',\s*''tf''\],\s*true\)' 'catalog order whitelist is exactly new/mv/tf'
Assert-Matches 'normalizeCatalogOrder\(\$_GET\[''order''\]\s*\?\?\s*\$_GET\[''o''\]\s*\?\?\s*''new''\)' 'popular order reads order before compatibility alias o'
Assert-Matches 'normalizeSearchOrder[\s\S]*?\[''mr'',\s*''mv'',\s*''mp'',\s*''tf'',\s*''new''\]' 'search order compatibility remains unchanged'

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

foreach ($snippet in @(
    'JM_LIST_CACHE_TTL',
    'JM_SEARCH_CACHE_TTL',
    'JM_WEEKLY_LIST_CACHE_TTL',
    'cacheThroughArray',
    'canonicalCacheValue',
    'X-JM-Source-Cache',
    'source_cache_hits',
    'source_cache_misses'
)) {
    if (-not $source.Contains($snippet)) {
        throw "Missing list source-cache contract: $snippet"
    }
}
Assert-Matches 'fetchLatestList[\s\S]*?cacheThroughArray[\s\S]*?ENDPOINT_LATEST[\s\S]*?source_page' 'latest caches normalized upstream source pages before local slicing'
Assert-Matches 'fetchPopularList[\s\S]*?cacheThroughArray[\s\S]*?category[\s\S]*?order' 'popular cache key isolates category and order'
Assert-Matches 'searchAlbums[\s\S]*?query_sha256[\s\S]*?JM_SEARCH_CACHE_TTL' 'search cache key hashes query and uses independent TTL'
Assert-Matches 'fetchWeeklyList[\s\S]*?category_id[\s\S]*?type_id[\s\S]*?JM_WEEKLY_LIST_CACHE_TTL' 'weekly cache key isolates category and type'
Assert-Matches 'cacheThroughArray[\s\S]*?JM_CACHE_FILL_WAIT_MS[\s\S]*?compareAndDelete' 'list source cache uses bounded single-flight and token-safe release'

$cacheThroughBody = [regex]::Match(
    $source,
    'private function cacheThroughArray\([\s\S]*?\): array \{(?<body>[\s\S]*?)\n    \}\n\n    /\*\* @param callable\(array\):bool'
).Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($cacheThroughBody)) {
    throw 'Could not isolate cacheThroughArray body'
}
if ($cacheThroughBody.Contains('JmListResult')) {
    throw 'List source cache must store normalized arrays before constructing JmListResult'
}

Assert-Matches '\$this->cacheNamespace\s*\.\s*\$class\s*\.\s*'':v1:''\s*\.\s*hash\(''sha256'',\s*json_encode' 'list source cache key has an explicit v1 schema and SHA-256 canonical JSON digest'
Assert-Matches 'canonicalCacheValue[\s\S]*?if\s*\(!is_array\(\$value\)\)\s*return\s+\$value[\s\S]*?array_is_list\(\$value\)[\s\S]*?array_map\([\s\S]*?canonicalCacheValue[\s\S]*?ksort\(\$value,\s*SORT_STRING\)[\s\S]*?canonicalCacheValue\(\$item\)' 'cache key canonicalizer preserves scalar types and list order while recursively sorting map keys'
Assert-Matches 'json_encode\([\s\S]*?canonicalCacheValue\(\$keyFields\)[\s\S]*?JSON_PRESERVE_ZERO_FRACTION[\s\S]*?JSON_THROW_ON_ERROR' 'canonical cache JSON preserves scalar number types and rejects encoding failures'
Assert-Matches 'if\s*\(\$ttl\s*<=\s*0\s*\|\|\s*!\$this->cache->isAvailable\(\)\)[\s\S]*?recordSourceCache\(''disabled''\)[\s\S]*?return\s+\$this->produceValidatedArray\(\$validator,\s*\$producer\)' 'TTL zero and unavailable APCu call the validated producer directly'

Assert-Matches 'tryAdd\(\$leaseKey,\s*\$token,\s*\$lockTtl\)[\s\S]*?try\s*\{[\s\S]*?\$this->cache->get\(\$key\)[\s\S]*?produceValidatedArray\(\$validator,\s*\$producer\)[\s\S]*?\$this->cache->set\(\$key,\s*\$value,\s*\$ttl\)[\s\S]*?finally\s*\{[\s\S]*?compareAndDelete\(\$leaseKey,\s*\$token\)' 'cache fill owner double-checks, validates, stores, and token-safely releases in finally'
Assert-Matches '\$lockTtl\s*=\s*min\(90,\s*max\(\$configuredLockTtl,\s*\(int\)\s*ceil\(max\(1,\s*\$remainingMs\)\s*/\s*1000\)\s*\+\s*2\)\)' 'cache fill lease exceeds the remaining producer budget by two seconds and is capped at 90 seconds'
Assert-Matches 'JM_CACHE_FILL_WAIT_MS[\s\S]*?remainingMs\(\)\s*-\s*100[\s\S]*?usleep\(random_int\(20_000,\s*60_000\)\)[\s\S]*?\$this->cache->get\(\$key\)[\s\S]*?remainingMs\(\)\s*<=\s*100[\s\S]*?Source cache fill exceeded request deadline' 'cache fill losers use bounded jittered rereads and fail clearly when producer budget is insufficient'

$listItemNormalizer = Get-PhpFunctionBlock $source 'normalizeListItemPayload'
$listItemsNormalizer = Get-PhpFunctionBlock $source 'normalizeListItemsPayload'
$listItemsContract = '(?s)^\s*private\s+static\s+function\s+normalizeListItemsPayload\s*\(\s*mixed\s+\$payload\s*\)\s*:\s*array\s*\{\s*if\s*\(\s*!is_array\(\$payload\)\s*\|\|\s*!array_is_list\(\$payload\)\s*\)\s*\{\s*throw\s+new\s+JmException\(''Invalid upstream list payload'',\s*502\)\s*;\s*\}\s*\$normalized\s*=\s*\[\s*\]\s*;\s*foreach\s*\(\s*\$payload\s+as\s+\$item\s*\)\s*\{\s*\$normalized\[\]\s*=\s*self::normalizeListItemPayload\(\$item\)\s*;\s*\}\s*return\s+\$normalized\s*;\s*\}\s*$'
if ($listItemsNormalizer -notmatch $listItemsContract) {
    throw 'List payload normalizer must require a true list and map a valid empty list to an empty list'
}
if ($listItemNormalizer -notmatch 'if\s*\(\s*!is_array\(\$item\)\s*\|\|\s*array_is_list\(\$item\)\s*\)') {
    throw 'List item validator must require a real object/map shape'
}
if ($listItemNormalizer -notmatch 'PayloadNormalizer::scalarString\(\$item\[''id''\]\s*\?\?\s*\$item\[''aid''\]\s*\?\?\s*\$item\[''AID''\]\s*\?\?\s*''''\)' -or
    $listItemNormalizer -notmatch 'trim\(\$id\)\s*===\s*''''[\s\S]*?Invalid upstream list item id') {
    throw 'List item validator must canonicalize supported id aliases and reject an empty id'
}
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
    if ($listItemNormalizer -notmatch $projection[0]) {
        throw "List item canonical projection is missing: $($projection[1])"
    }
}
$projectionInitializer = [regex]::Match($listItemNormalizer, '(?s)\$normalized\s*=\s*\[(?<fields>.*?)\]\s*;')
if (-not $projectionInitializer.Success) {
    throw 'List item normalizer must build a fresh canonical projection'
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
    throw 'Optional category projection must allow only category/category_sub title'
}
if ($listItemNormalizer -match 'return\s+\$item\s*;|\$normalized\s*=\s*\$item\b|\$normalized\s*\+=|array_(?:merge|replace)\s*\([^;]*\$item|\.\.\.\s*\$item') {
    throw 'List item canonical projection must not retain arbitrary upstream fields'
}

foreach ($validator in @(
    @('isListItemsPayload', 'return\s+self::normalizeListItemsPayload\(\$payload\)\s*===\s*\$payload\s*;'),
    @('isPagedListPayload', 'return\s+self::normalizePagedListPayload\(\$payload,\s*\$itemsKey,\s*\$allowRedirect\)\s*===\s*\$payload\s*;'),
    @('isPromoteHomePayload', 'return\s+self::normalizePromoteHomePayload\(\$payload\)\s*===\s*\$payload\s*;')
)) {
    $validatorBody = Get-PhpFunctionBlock $source $validator[0]
    if ($validatorBody -notmatch $validator[1]) {
        throw "List cache validator must reject noncanonical cached payloads: $($validator[0])"
    }
}
$pagedListNormalizer = Get-PhpFunctionBlock $source 'normalizePagedListPayload'
$promoteHomeNormalizer = Get-PhpFunctionBlock $source 'normalizePromoteHomePayload'
if ($pagedListNormalizer -notmatch 'array_key_exists\(\$itemsKey,\s*\$payload\)[\s\S]*?\$redirectAid\s*===\s*''''[\s\S]*?\$totalRaw[\s\S]*?preg_match\(''/\^\\d\+\$/''') {
    throw 'Paged list validator must require the items key or a legal redirect and a numeric total'
}
if ($pagedListNormalizer -notmatch '\$totalRaw\s*=\s*\$payload\[''total''\]\s*\?\?\s*\(\$redirectAid\s*!==\s*''''\s*\?\s*1\s*:\s*null\)') {
    throw 'Search redirect payload without content must receive a valid normalized total'
}
if ($promoteHomeNormalizer -notmatch 'array_is_list\(\$payload\)[\s\S]*?array_key_exists\(''content'',\s*\$section\)[\s\S]*?normalizeListItemsPayload') {
    throw 'Promote-home validator must require normalized list sections'
}

Assert-Matches 'fetchLatestList[\s\S]*?\[''endpoint''\s*=>\s*JmConfig::ENDPOINT_LATEST,\s*''source_page''\s*=>\s*\$sourcePage\][\s\S]*?JM_LIST_CACHE_TTL[\s\S]*?normalizeListItemsPayload[\s\S]*?windowedListResultFromItems' 'latest key contains endpoint/source_page and caches normalized arrays before slicing'
Assert-Matches 'fetchPopularList[\s\S]*?''endpoint''\s*=>\s*JmConfig::ENDPOINT_CATEGORY_FILTER[\s\S]*?''source_page''\s*=>\s*\$sourcePage[\s\S]*?''category''\s*=>\s*''latest''[\s\S]*?''order''\s*=>\s*\$order[\s\S]*?JM_LIST_CACHE_TTL[\s\S]*?normalizePagedListPayload' 'popular key contains endpoint/source_page/category/order and caches normalized arrays'
Assert-Matches 'fetchPromoteList[\s\S]*?''endpoint''\s*=>\s*JmConfig::ENDPOINT_PROMOTE_LIST[\s\S]*?''source_page''\s*=>\s*\$currentSourcePage[\s\S]*?''section_id''\s*=>\s*\$sectionId[\s\S]*?JM_LIST_CACHE_TTL[\s\S]*?normalizePagedListPayload' 'promote-list key contains endpoint/source_page/section_id and caches normalized arrays'
Assert-Matches 'fetchPromoteHomeList[\s\S]*?\[''endpoint''\s*=>\s*JmConfig::ENDPOINT_PROMOTE\][\s\S]*?JM_LIST_CACHE_TTL[\s\S]*?normalizePromoteHomePayload[\s\S]*?windowedListResultFromItems' 'promote-home key contains endpoint and caches normalized sections before slicing'
Assert-Matches 'fetchWeeklyList[\s\S]*?''endpoint''\s*=>\s*JmConfig::ENDPOINT_WEEK_FILTER[\s\S]*?''page''\s*=>\s*\$page[\s\S]*?''category_id''\s*=>\s*\$categoryId[\s\S]*?''type_id''\s*=>\s*\$typeId[\s\S]*?JM_WEEKLY_LIST_CACHE_TTL[\s\S]*?normalizePagedListPayload' 'weekly key contains endpoint/page/category_id/type_id and caches normalized arrays'
Assert-Matches 'searchAlbums[\s\S]*?''endpoint''\s*=>\s*JmConfig::ENDPOINT_SEARCH[\s\S]*?''upstream_page''\s*=>\s*\$page[\s\S]*?''order''\s*=>\s*\$order[\s\S]*?''query_sha256''\s*=>\s*hash\(''sha256'',\s*\$query\)[\s\S]*?JM_SEARCH_CACHE_TTL[\s\S]*?normalizePagedListPayload' 'search key contains endpoint/upstream_page/order and only the normalized query digest'
Assert-NotMatches 'error_log\([^\n]*\$query' 'normalized search query must not enter logs'

Assert-Matches 'sourceCacheStatus[\s\S]*?\$this->sourceCacheHits\s*>\s*0\s*&&\s*\$this->sourceCacheMisses\s*>\s*0\)\s*return\s*''mixed''' 'a request with source hits and misses reports mixed'
Assert-Matches '\$data\[''api_calls''\]\s*=\s*\$service->requestCount\(\)[\s\S]*?\$data\[''source_cache_hits''\][\s\S]*?\$data\[''source_cache_misses''\]' 'list diagnostics preserve real upstream call counts beside source cache counts'

foreach ($snippet in @(
    'JM_ALBUM_CACHE_TTL',
    'JM_WEEK_DEFAULTS_CACHE_TTL',
    'JM_WEEK_DEFAULTS_STALE_TTL',
    'album:v1:',
    'week-defaults:v1',
    'weekDefaultsCacheKey',
    'normalizeAlbumPayload',
    'normalizeWeekDefaultsPayload'
)) {
    if (-not $source.Contains($snippet)) { throw "Missing metadata cache contract snippet: $snippet" }
}

$albumBody = [regex]::Match($source, 'public function fetchAlbum\(string \$jmid\): JmAlbum\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function fetchLatestList').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($albumBody)) {
    throw 'Unable to inspect fetchAlbum metadata cache contract'
}
if ($albumBody -notmatch 'hash\(''sha256'',\s*\$canonicalAlbumId\)') {
    throw 'Album metadata key must contain only the canonical album ID SHA-256 digest'
}
if ($albumBody -notmatch 'normalizeAlbumPayload[\s\S]*?\$resp\s*=\s*\[''data''\s*=>\s*\$payload\][\s\S]*?JmAlbum::fromApiResponse\(\$resp\[''data''\],\s*\$jmid\)') {
    throw 'Album metadata must cache normalized arrays and reconstruct JmAlbum after every lookup'
}
if ($albumBody -match 'cache->set\([^\n]*JmAlbum') {
    throw 'Album metadata must not cache a JmAlbum object'
}

$weekDefaultsBody = [regex]::Match($source, 'private function fetchWeekDefaults\(\): array\s*\{(?<body>[\s\S]*?)\n    \}\n\n    public function searchAlbums').Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($weekDefaultsBody)) {
    throw 'Unable to inspect fetchWeekDefaults metadata cache contract'
}
foreach ($pattern in @(
    'JM_WEEK_DEFAULTS_CACHE_TTL',
    'JM_WEEK_DEFAULTS_STALE_TTL',
    'fresh_until',
    'stale_until',
    'week-defaults-fill-lease:',
    'recordWeekDefaultsStaleFallback'
)) {
    if ($weekDefaultsBody -notmatch $pattern) { throw "Weekly defaults cache contract is missing $pattern" }
}
if ($weekDefaultsBody -notmatch '\$key\s*=\s*self::weekDefaultsCacheKey\(\$this->cacheNamespace\)') {
    throw 'Weekly defaults fetch must use the canonical endpoint/schema key builder'
}
Assert-Matches 'metadataCacheThroughArray[\s\S]*?produceValidatedArray[\s\S]*?cache->set\(\$key,\s*\$value' 'metadata cache stores validated arrays only'
Assert-Matches 'metadataCacheThroughArray(?<body>[\s\S]*?)private function produceValidatedArray' 'metadata array cache helper is present'
$metadataHelperBody = [regex]::Match(
    $source,
    'private function metadataCacheThroughArray(?<body>[\s\S]*?)(?=\r?\n    /\*\* @param callable\(array\):bool \$validator @param callable\(\):array \$producer \*/\r?\n    private function produceValidatedArray)'
).Groups['body'].Value
if ([string]::IsNullOrWhiteSpace($metadataHelperBody)) {
    throw 'Unable to inspect metadataCacheThroughArray helper body'
}
if ($metadataHelperBody -match 'recordSourceCache') {
    throw 'Metadata cache activity must not alter X-JM-Source-Cache list diagnostics'
}
Assert-Matches '''metadata_cache''\s*=>[\s\S]*?''entry_status''\s*=>[\s\S]*?''stale_fallback_count''\s*=>' 'health reports metadata TTL state and bounded stale fallback count'

Write-Output 'List endpoint contract snippets found.'
