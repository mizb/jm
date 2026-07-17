$ErrorActionPreference = 'Stop'

$fixturePath = Join-Path $PSScriptRoot 'fixtures\upstream-router.php'
$sourcePath = Join-Path (Split-Path -Parent $PSScriptRoot) 'index.php'
$fixture = Get-Content -LiteralPath $fixturePath -Raw -Encoding UTF8
$source = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8

foreach ($snippet in @(
    'chapter-images-strings',
    'chapter-images-objects',
    'chapter-images-mixed',
    'chapter-images-empty',
    'chapter-images-object-empty',
    'chapter-images-object-zero',
    'chapter-images-malformed',
    'function chapterImagesForScenario',
    '[''image'' => sprintf(''%05d.png'', $i)]',
    '(object) []',
    '(object) [''0'' => ''00001.png'']'
)) {
    if (-not $fixture.Contains($snippet)) {
        throw "Missing resource fixture contract: $snippet"
    }
}

foreach ($snippet in @(
    'image-body-exact',
    'image-body-over',
    'image-body-no-length',
    'image-body-chunked-exact',
    'image-body-chunked-over',
    'image-body-forged-length',
    'image-pixel-over',
    'image-invalid',
    'image-http-302',
    'image-http-404',
    'image-http-408',
    'image-http-429',
    'PIXEL_OVER_PNG',
    'Content-Length:'
)) {
    if (-not $fixture.Contains($snippet)) {
        throw "Missing image resource fixture contract: $snippet"
    }
}

if ($fixture -notmatch '''/chapter''\s*=>\s*\[[\s\S]*?''images''\s*=>\s*chapterImagesForScenario\(\$scenario\)') {
    throw 'Chapter fixture endpoint does not route images through the protocol-shape corpus.'
}

foreach ($snippet in @(
    'comic-read-valid',
    'comic-read-objects',
    'comic-read-missing-scramble',
    'comic-read-bad-json',
    'comic-read-business-error',
    'comic-read-not-found',
    'comic-read-timeout',
    '$uriPath === ''/comic_read'''
)) {
    if (-not $fixture.Contains($snippet)) {
        throw "Missing disabled comic-read A/B fixture contract: $snippet"
    }
}

if ($source.Contains('ENDPOINT_COMIC_READ') -or $source -match 'callJson\(\s*[''"]\/comic_read') {
    throw 'Production must not enable /comic_read before the disabled A/B gate is approved.'
}
if (-not $source.Contains("public const ENDPOINT_CHAPTER  = '/chapter';") -or
    -not $source.Contains("public const ENDPOINT_SCRAMBLE = '/chapter_view_template';")) {
    throw 'Production chapter + scramble endpoints must remain the default path.'
}

Write-Output 'Resource fixture contract passed.'
