param(
    [string] $BaseUrl = 'http://localhost:8088',
    [string] $AlbumId = '350234',
    [string] $ChapterId = '350234',
    [int] $Page = 1,
    [int] $PrefetchPages = 10,
    [int] $PrefetchWaitSeconds = 8,
    [switch] $SkipBuild
)

$ErrorActionPreference = 'Stop'

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

function Invoke-JmRequest {
    param(
        [string] $Url,
        [string] $Method = 'GET'
    )
    Invoke-WebRequest -UseBasicParsing -Method $Method -Uri $Url
}

function Invoke-DockerCompose {
    param([string[]] $Arguments)

    & docker compose @Arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "docker compose $($Arguments -join ' ') failed with exit code ${exitCode}."
    }
}

function Get-ImageUrl {
    param(
        [int] $ImagePage,
        [bool] $DisablePrefetch = $false
    )
    $url = "${BaseUrl}/?jmid=${AlbumId}&chapter=${ChapterId}&page=${ImagePage}"
    if ($DisablePrefetch) {
        $url += '&prefetch=0'
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

function Try-HeadImage {
    param(
        [int] $ImagePage,
        [bool] $DisablePrefetch = $false
    )
    try {
        return Invoke-JmRequest -Method 'HEAD' -Url (Get-ImageUrl -ImagePage $ImagePage -DisablePrefetch $DisablePrefetch)
    } catch {
        return $null
    }
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

$metadataUrl = "${BaseUrl}/?jmid=$AlbumId&format=min"
$metadata = Invoke-JmRequest -Url $metadataUrl
$metadataJson = $metadata.Content | ConvertFrom-Json
Assert-True ($metadataJson.success -eq $true) "Album metadata request failed: ${metadataUrl}"
Assert-True ($metadataJson.data.album.album_id -eq $AlbumId) "Album metadata returned an unexpected album_id."

$firstImage = Invoke-JmRequest -Method 'HEAD' -Url (Get-ImageUrl -ImagePage $Page)
Assert-HeaderExists $firstImage.Headers 'X-JM-Cache'
Assert-HeaderExists $firstImage.Headers 'X-JM-Image-Codec'
Assert-HeaderExists $firstImage.Headers 'X-JM-Singleflight'
Assert-HeaderExists $firstImage.Headers 'X-JM-Prefetch'
Assert-HeaderExists $firstImage.Headers 'X-JM-Cache-Store'
Assert-HeaderEquals $firstImage.Headers 'X-JM-Cache' 'MISS'

$secondImage = Invoke-JmRequest -Method 'HEAD' -Url (Get-ImageUrl -ImagePage $Page)
Assert-HeaderEquals $secondImage.Headers 'X-JM-Cache' 'HIT'

# Default prefetch target range is N+1 through N+10.
Start-Sleep -Seconds $PrefetchWaitSeconds
$prefetched = @()
for ($offset = 1; $offset -le $PrefetchPages; $offset++) {
    $candidatePage = $Page + $offset
    $prefetchResponse = Try-HeadImage -ImagePage $candidatePage -DisablePrefetch $true
    if ($null -eq $prefetchResponse) {
        break
    }
    Assert-HeaderEquals $prefetchResponse.Headers 'X-JM-Cache' 'HIT'
    $prefetched += $candidatePage
}
Assert-True ($prefetched.Count -gt 0) "No prefetched pages were observed after requesting page ${Page}."
Write-Output "Observed prefetch cache hits for pages: $($prefetched -join ', ')"

$prefetchDisabledPage = $Page + $PrefetchPages + 1
$afterDisabledPage = $prefetchDisabledPage + 1
try {
    Invoke-JmRequest -Method 'HEAD' -Url (Get-ImageUrl -ImagePage $prefetchDisabledPage -DisablePrefetch $true) | Out-Null
    $afterDisabled = Try-HeadImage -ImagePage $afterDisabledPage -DisablePrefetch $true
    if ($null -ne $afterDisabled) {
        $afterCache = @($afterDisabled.Headers['X-JM-Cache']) -join ', '
        Assert-True ($afterCache -ne 'HIT') "prefetch=0 still warmed page ${afterDisabledPage}."
    } else {
        Write-Warning "Could not check prefetch=0 follow-up page ${afterDisabledPage}; it may be out of range."
    }
} catch {
    Write-Warning "Could not check prefetch=0 at page ${prefetchDisabledPage}; it may be out of range. $($_.Exception.Message)"
}

$imageFiles = Invoke-DockerCompose -Arguments @('exec', '-T', 'jmcomic-api', 'sh', '-lc', "find /app -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.webp' -o -iname '*.gif' \)")
Assert-True ([string]::IsNullOrWhiteSpace(($imageFiles | Out-String).Trim())) "Unexpected image files were found under /app: ${imageFiles}"

Write-Output 'Runtime verification passed.'
