param(
    [string] $BaseUrl = 'http://localhost:8088',
    [string] $AlbumId = '350234',
    [string] $ChapterId = '350234',
    [ValidateRange(1, 100000)]
    [int] $Page = 1,
    [int] $PrefetchPages = 10,
    [int] $PrefetchWaitSeconds = 8,
    [switch] $SkipBuild
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptRoot
Set-Location -LiteralPath $projectRoot

function Assert-True {
    param(
        [bool] $Condition,
        [string] $Message
    )
    if (-not $Condition) {
        throw $Message
    }
}

function Assert-HeaderEquals {
    param(
        $Headers,
        [string] $Name,
        [string] $Expected
    )
    $actual = @($Headers[$Name]) -join ', '
    if ($actual -ne $Expected) {
        throw "Expected header ${Name}=${Expected}, got '${actual}'"
    }
}

function Assert-HeaderExists {
    param(
        $Headers,
        [string] $Name
    )
    $actual = @($Headers[$Name]) -join ', '
    if ([string]::IsNullOrWhiteSpace($actual)) {
        throw "Missing expected header ${Name}"
    }
}

function Get-HeaderValue {
    param($Headers, [string] $Name)
    return (@($Headers[$Name]) -join ', ')
}

function Invoke-JmRequest {
    param(
        [string] $Url,
        [string] $Method = 'GET'
    )
    Invoke-WebRequest -UseBasicParsing -Method $Method -Uri $Url
}

function Invoke-JmImageRequest {
    param([Parameter(Mandatory = $true)][string] $Url)

    if ($null -eq $script:JmImageHttpClient) {
        $handler = New-Object System.Net.Http.HttpClientHandler
        $handler.AllowAutoRedirect = $false
        $script:JmImageHttpClient = New-Object System.Net.Http.HttpClient($handler, $true)
        $script:JmImageHttpClient.Timeout = [TimeSpan]::FromSeconds(30)
    }

    $request = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::Get, $Url)
    $response = $null
    try {
        $response = $script:JmImageHttpClient.SendAsync(
            $request,
            [System.Net.Http.HttpCompletionOption]::ResponseHeadersRead
        ).GetAwaiter().GetResult()
        $headers = @{}
        foreach ($header in $response.Headers) { $headers[$header.Key] = @($header.Value) }
        foreach ($header in $response.Content.Headers) { $headers[$header.Key] = @($header.Value) }

        $stream = $response.Content.ReadAsStreamAsync().GetAwaiter().GetResult()
        try {
                [void] $stream.CopyToAsync([System.IO.Stream]::Null).GetAwaiter().GetResult()
        } finally {
            $stream.Dispose()
        }

        $statusCode = [int] $response.StatusCode
        if (-not $response.IsSuccessStatusCode) {
            throw "Image GET failed with HTTP $statusCode for '$Url'."
        }
        return [pscustomobject]@{
            StatusCode = $statusCode
            Headers = $headers
        }
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
    }
}

function Invoke-DockerCompose {
    param([string[]] $Arguments)

    & docker compose @Arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "docker compose $($Arguments -join ' ') failed with exit code ${exitCode}."
    }
}

function Get-OnDiskImageArtifacts {
    $scanner = @'
$roots = ['/app', '/tmp'];
$imagePaths = [];
foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || !$entry->isReadable()) continue;
        $handle = @fopen($entry->getPathname(), 'rb');
        if ($handle === false) continue;
        try { $head = fread($handle, 12); } finally { fclose($handle); }
        if (!is_string($head)) continue;
        $hex = bin2hex($head);
        $isImage = str_starts_with($hex, '89504e470d0a1a0a')
            || str_starts_with($hex, 'ffd8ff')
            || str_starts_with($head, 'GIF87a')
            || str_starts_with($head, 'GIF89a')
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')
            || str_starts_with($head, 'BM')
            || str_starts_with($hex, '49492a00')
            || str_starts_with($hex, '4d4d002a');
        if ($isImage) $imagePaths[] = $entry->getPathname();
    }
}
sort($imagePaths, SORT_STRING);
foreach ($imagePaths as $path) echo $path, "\n";
'@
    return @(Invoke-DockerCompose -Arguments @('exec', '-T', 'jmcomic-api', 'php', '-r', $scanner) |
        ForEach-Object { ([string] $_).Trim() } |
        Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Get-ImageUrl {
    param(
        [int] $ImagePage,
        [bool] $DisablePrefetch = $false,
        [string] $NextChapterId = ''
    )
    $url = "${BaseUrl}/?jmid=${AlbumId}&chapter=${ChapterId}&page=${ImagePage}"
    if ($DisablePrefetch) {
        $url += '&prefetch=0'
    }
    if (-not [string]::IsNullOrWhiteSpace($NextChapterId)) {
        $url += "&next_chapter=$([uri]::EscapeDataString($NextChapterId))"
    }
    return $url
}

function Wait-Health {
    $healthUrl = "${BaseUrl}/?health=1"
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        try {
            return Invoke-JmRequest -Url $healthUrl
        } catch {
            Start-Sleep -Seconds 2
        }
    }
    throw "Service did not become healthy at ${healthUrl}"
}

function Try-GetImage {
    param(
        [int] $ImagePage,
        [bool] $DisablePrefetch = $false
    )
    try {
        return Invoke-JmImageRequest -Url (Get-ImageUrl -ImagePage $ImagePage -DisablePrefetch $DisablePrefetch)
    } catch {
        return $null
    }
}

function Get-HealthJson {
    return ((Invoke-JmRequest -Url "${BaseUrl}/?health=1").Content | ConvertFrom-Json)
}

function Get-PrefetchMetric {
    param($HealthJson, [string] $Name)
    $property = $HealthJson.diagnostics.prefetch.aggregate.PSObject.Properties[$Name]
    return $(if ($null -eq $property) { 0 } else { [long] $property.Value })
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'docker is not available on PATH. Install Docker Desktop or run this script on the deployment host.'
}

$composeText = Get-Content -LiteralPath (Join-Path $projectRoot 'docker-compose.yml') -Raw -Encoding UTF8
Assert-True ($composeText -notmatch '/app/cache') 'docker-compose.yml must not contain an /app/cache volume.'

if (-not $SkipBuild) {
    Invoke-DockerCompose -Arguments @('build')
}
Invoke-DockerCompose -Arguments @('up', '-d', '--force-recreate')

$health = Wait-Health
$imageArtifactsBefore = @(Get-OnDiskImageArtifacts)
Assert-HeaderExists $health.Headers 'X-JM-API-Version'
$healthJson = $health.Content | ConvertFrom-Json
Assert-True ($healthJson.success -eq $true) 'health=1 did not return success=true.'
Assert-True (-not [string]::IsNullOrWhiteSpace([string] $healthJson.version)) 'health=1 did not include top-level version.'
Assert-True (-not [string]::IsNullOrWhiteSpace([string] $healthJson.diagnostics.app_version)) 'health=1 did not include app_version.'
Assert-True ($healthJson.diagnostics.apcu -eq $true) 'health=1 did not report APCu enabled.'
Assert-True ($null -ne $healthJson.diagnostics.apcu_details) 'health=1 did not include apcu_details.'
Assert-True ($null -ne $healthJson.diagnostics.apcu_details.free_memory_bytes) 'health=1 did not include APCu free memory.'
Assert-True ($null -ne $healthJson.diagnostics.apcu_details.free_ratio) 'health=1 did not include APCu free ratio.'
Assert-True ($null -ne $healthJson.diagnostics.singleflight) 'health=1 did not include singleflight diagnostics.'
Assert-True ($null -ne $healthJson.diagnostics.prefetch) 'health=1 did not include prefetch diagnostics.'
Assert-True ($null -ne $healthJson.diagnostics.cache_policy) 'health=1 did not include cache policy diagnostics.'
Assert-True ($null -ne $healthJson.diagnostics.domains) 'health=1 did not include domain health diagnostics.'

$latestUrl = "${BaseUrl}/?list=latest&page=1&format=min"
$latest = Invoke-JmRequest -Url $latestUrl
$latestJson = $latest.Content | ConvertFrom-Json
Assert-True ($latestJson.success -eq $true) "Latest list request failed: ${latestUrl}"
Assert-True ($latestJson.data.mode -eq 'latest') 'Latest list returned an unexpected mode.'
Assert-True ($latestJson.data.page -eq 1) 'Latest list did not preserve client page=1.'

$promoteUrl = "${BaseUrl}/?list=promote&page=1&format=min"
$promote = Invoke-JmRequest -Url $promoteUrl
$promoteJson = $promote.Content | ConvertFrom-Json
Assert-True ($promoteJson.success -eq $true) "Promote list request failed: ${promoteUrl}"
Assert-True ($promoteJson.data.mode -eq 'promote') 'Promote list returned an unexpected mode.'
Assert-True ($promoteJson.data.page -eq 1) 'Promote list did not preserve client page=1.'

$weeklyUrl = "${BaseUrl}/?list=weekly&page=1&format=min"
$weekly = Invoke-JmRequest -Url $weeklyUrl
$weeklyJson = $weekly.Content | ConvertFrom-Json
Assert-True ($weeklyJson.success -eq $true) "Weekly list request failed: ${weeklyUrl}"
Assert-True ($weeklyJson.data.mode -eq 'weekly') 'Weekly list returned an unexpected mode.'
Assert-True ($weeklyJson.data.page -eq 1) 'Weekly list did not preserve client page=1.'

$metadataUrl = "${BaseUrl}/?jmid=$AlbumId&format=min"
$metadata = Invoke-JmRequest -Url $metadataUrl
$metadataJson = $metadata.Content | ConvertFrom-Json
Assert-True ($metadataJson.success -eq $true) "Album metadata request failed: ${metadataUrl}"
Assert-True ($metadataJson.data.album.album_id -eq $AlbumId) "Album metadata returned an unexpected album_id."

$chapterUrl = "${BaseUrl}/?jmid=${AlbumId}&chapter=${ChapterId}&format=min"
$chapterResponse = Invoke-JmRequest -Url $chapterUrl
$chapterJson = $chapterResponse.Content | ConvertFrom-Json
$selectedChapters = @($chapterJson.data.chapters | Where-Object { [string] $_.photo_id -eq $ChapterId })
Assert-True ($selectedChapters.Count -eq 1) 'Chapter verification did not return exactly the requested chapter.'
$verifiedChapter = $selectedChapters[0]
$pageCount = [int] $verifiedChapter.page_count
Assert-True ($pageCount -ge 4) 'Runtime prefetch verification requires a confirmed chapter with page_count -ge 4.'

$nextChapterId = ''
foreach ($chapterImage in @($verifiedChapter.images)) {
    if ([string] $chapterImage.url -match '[?&]next_chapter=(\d{1,20})(?:&|$)') {
        $nextChapterId = $Matches[1]
        break
    }
}
Assert-True (-not [string]::IsNullOrWhiteSpace($nextChapterId)) 'Runtime prefetch=0 verification requires a confirmed next_chapter from the selected chapter.'

# Prove prefetch=0 on a confirmed adjacent page3+ pair before any default
# prefetch can warm it. Aggregate stats also cover an optional next chapter.
$prefetchDisabledPage = $pageCount - 1
$afterDisabledPage = $pageCount
$disabledBefore = Get-HealthJson
$disabled = Invoke-JmImageRequest -Url (Get-ImageUrl -ImagePage $prefetchDisabledPage -DisablePrefetch $true -NextChapterId $nextChapterId)
Assert-HeaderEquals $disabled.Headers 'X-JM-Prefetch' 'disabled'
Start-Sleep -Seconds $PrefetchWaitSeconds
$disabledAfter = Get-HealthJson
Assert-True ((Get-PrefetchMetric $disabledAfter 'scheduled') - (Get-PrefetchMetric $disabledBefore 'scheduled') -eq 0) 'prefetch=0 scheduled background work.'
Assert-True ((Get-PrefetchMetric $disabledAfter 'attempted') - (Get-PrefetchMetric $disabledBefore 'attempted') -eq 0) 'prefetch=0 attempted current, page3+, or next-chapter background work.'
Assert-True ((Get-PrefetchMetric $disabledAfter 'cache_hits') - (Get-PrefetchMetric $disabledBefore 'cache_hits') -eq 0) 'prefetch=0 performed cached background work.'
Assert-True ((Get-PrefetchMetric $disabledAfter 'stored') - (Get-PrefetchMetric $disabledBefore 'stored') -eq 0) 'prefetch=0 stored a background page.'
Assert-True ((Get-PrefetchMetric $disabledAfter 'bytes') - (Get-PrefetchMetric $disabledBefore 'bytes') -eq 0) 'prefetch=0 downloaded background bytes.'
$afterDisabled = Invoke-JmImageRequest -Url (Get-ImageUrl -ImagePage $afterDisabledPage -DisablePrefetch $true)
Assert-HeaderEquals $afterDisabled.Headers 'X-JM-Cache' 'MISS'

# Default prefetch may only complete a bounded HIT subset. The wall/byte
# budgets intentionally do not promise every N+1..N+10 page will be hot.
$defaultPage = $Page
Assert-True ($defaultPage -lt $prefetchDisabledPage) 'The requested default prefetch page must leave the confirmed adjacent page3+ pair isolated.'
$prefetchBefore = Get-HealthJson
$firstImage = Invoke-JmImageRequest -Url (Get-ImageUrl -ImagePage $defaultPage)
Assert-HeaderExists $firstImage.Headers 'X-JM-Cache'
Assert-HeaderExists $firstImage.Headers 'X-JM-Image-Codec'
Assert-HeaderExists $firstImage.Headers 'X-JM-Singleflight'
Assert-HeaderExists $firstImage.Headers 'X-JM-Prefetch'
Assert-HeaderExists $firstImage.Headers 'X-JM-Cache-Store'
Assert-HeaderEquals $firstImage.Headers 'X-JM-Cache' 'MISS'
    Assert-HeaderEquals $firstImage.Headers 'X-JM-Prefetch' 'scheduled'
    $prefetchStatus = 'scheduled'

Start-Sleep -Seconds $PrefetchWaitSeconds
$prefetchAfter = Get-HealthJson
$eventDelta = (Get-PrefetchMetric $prefetchAfter 'events') - (Get-PrefetchMetric $prefetchBefore 'events')
$scheduledDelta = (Get-PrefetchMetric $prefetchAfter 'scheduled') - (Get-PrefetchMetric $prefetchBefore 'scheduled')
$attemptedDelta = (Get-PrefetchMetric $prefetchAfter 'attempted') - (Get-PrefetchMetric $prefetchBefore 'attempted')
$cacheHitDelta = (Get-PrefetchMetric $prefetchAfter 'cache_hits') - (Get-PrefetchMetric $prefetchBefore 'cache_hits')
$storedDelta = (Get-PrefetchMetric $prefetchAfter 'stored') - (Get-PrefetchMetric $prefetchBefore 'stored')
$bytesDelta = (Get-PrefetchMetric $prefetchAfter 'bytes') - (Get-PrefetchMetric $prefetchBefore 'bytes')
$wallDelta = (Get-PrefetchMetric $prefetchAfter 'wall_ms') - (Get-PrefetchMetric $prefetchBefore 'wall_ms')
Assert-True ($eventDelta -eq 1) "One image scheduling decision must produce one final stats event; got $eventDelta."
Assert-True ($attemptedDelta -ge 0 -and $cacheHitDelta -ge 0 -and $storedDelta -ge 0 -and $bytesDelta -ge 0 -and $wallDelta -ge 0) 'Prefetch aggregate counters must be monotonic.'
Assert-True ($cacheHitDelta + $storedDelta -le $attemptedDelta) 'Prefetch cache_hits + stored exceeded attempted.'
    Assert-True ($scheduledDelta -eq 1) 'A scheduled callback was not reflected in aggregate stats.'
    Assert-True ($attemptedDelta -gt 0) 'Default prefetch scheduled no real background attempt.'
    Assert-True ($storedDelta -gt 0 -and $bytesDelta -gt 0) 'Default prefetch attempted work but stored no downloaded page.'
    Assert-True ($wallDelta -le ([int] $prefetchAfter.diagnostics.prefetch.wall_budget_ms + 1000)) 'Prefetch callback exceeded wall budget tolerance.'

$secondImage = Invoke-JmImageRequest -Url (Get-ImageUrl -ImagePage $defaultPage -DisablePrefetch $true)
Assert-HeaderEquals $secondImage.Headers 'X-JM-Cache' 'HIT'

$prefetched = @()
for ($offset = 1; $offset -le $PrefetchPages; $offset++) {
    $candidatePage = $defaultPage + $offset
    if ($candidatePage -gt $pageCount) { break }
    if ($candidatePage -eq $prefetchDisabledPage -or $candidatePage -eq $afterDisabledPage) { continue }
    $prefetchResponse = Try-GetImage -ImagePage $candidatePage -DisablePrefetch $true
    Assert-True ($null -ne $prefetchResponse) "Confirmed candidate page $candidatePage was not readable."
    if ((Get-HeaderValue $prefetchResponse.Headers 'X-JM-Cache') -eq 'HIT') {
        $prefetched += $candidatePage
    }
}
    Assert-True ($prefetched.Count -gt 0) 'Scheduled prefetch attempted work but produced no bounded HIT subset.'
    Assert-True ($prefetched.Count -le $storedDelta) 'Observed prefetch HIT subset exceeded stored stats.'
Write-Output "Observed bounded HIT subset for pages: $($prefetched -join ', ')"

$imageArtifactsAfter = @(Get-OnDiskImageArtifacts)
$newImageArtifacts = @($imageArtifactsAfter | Where-Object { $imageArtifactsBefore -notcontains $_ })
Assert-True ($newImageArtifacts.Count -eq 0) "Decoded image artifacts were written under /app or /tmp: $($newImageArtifacts -join ', ')"

Write-Output 'Runtime verification passed.'
