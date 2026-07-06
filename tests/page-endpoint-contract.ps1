$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$sourcePath = Join-Path $root 'index.php'
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8

$requiredSnippets = @(
    '$_GET[''page''] ?? null',
    'function sendBinaryImage',
    'validatePageParam',
    'fetchDecodedPage',
    'downloadImage',
    'ScrambleDecoder::decodeBytes',
    'pageNameForScramble',
    'pathinfo($clean, PATHINFO_FILENAME)',
    "return ['bytes' => `$bytes, 'mime' => 'image/jpeg']",
    'function requestBaseUrl',
    'function buildDecodedPageUrl',
    '''source_url''',
    '''mime''',
    '$ch->toArray($album->id, $publicBaseUrl)'
)

foreach ($snippet in $requiredSnippets) {
    if (-not $source.Contains($snippet)) {
        throw "Missing expected page endpoint contract snippet: $snippet"
    }
}

Write-Output 'Page endpoint contract snippets found.'
