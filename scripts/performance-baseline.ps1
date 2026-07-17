param(
    [string] $BaseUrl = 'http://localhost:8088',
    [string] $AlbumId = '350234',
    [string] $ChapterId = '350234',
    [int] $WarmupIterations = 10,
    [int] $Iterations = 120,
    [int] $Concurrency = 10,
    [string] $OutputPath = '.\performance-baseline.json',
    [string] $ComparePath = '',
    [ValidateSet('unspecified', 'docker', 'local-fixture', 'external')]
    [string] $RuntimeKind = 'unspecified',
    [Alias('ActualWorkerCount')]
    [int] $AssertedActualWorkerCount = 0,
    [ValidateSet('unverified', 'local-process', 'docker-image', 'external-verified')]
    [string] $RuntimeSourceBinding = 'unverified',
    [string] $RuntimeImageDigest = '',
    [string] $NetworkConditionId = 'unverified',
    [string] $ResourceProfileId = 'unverified',
    [ValidateRange(1, 300)]
    [int] $RequestTimeoutSeconds = 30,
    [string] $MeasurementRunId = '',
    [ValidateRange(1, 60)]
    [int] $PrefetchObservationSeconds = 8
)

$ErrorActionPreference = 'Stop'
$performanceEvidencePath = Join-Path $PSScriptRoot 'performance-evidence.ps1'
if (-not (Test-Path -LiteralPath $performanceEvidencePath -PathType Leaf)) {
    throw "Performance evidence helper not found: $performanceEvidencePath"
}
$initialPerformanceEvidenceHash = (Get-FileHash -LiteralPath $performanceEvidencePath -Algorithm SHA256).Hash
. $performanceEvidencePath
$performanceEvidenceHash = (Get-FileHash -LiteralPath $performanceEvidencePath -Algorithm SHA256).Hash
if ([string] $initialPerformanceEvidenceHash -ne [string] $performanceEvidenceHash) {
    throw 'Performance evidence helper changed while it was being loaded; retry the measurement.'
}
$scriptVersion = '2026.07.16.3'
$scriptHash = (Get-FileHash -LiteralPath $MyInvocation.MyCommand.Path -Algorithm SHA256).Hash
$projectRoot = Split-Path -Parent $PSScriptRoot
$indexPath = Join-Path $projectRoot 'index.php'
$indexHash = $(if (Test-Path -LiteralPath $indexPath) { (Get-FileHash -LiteralPath $indexPath -Algorithm SHA256).Hash } else { $null })
$dockerfilePath = Join-Path $projectRoot 'Dockerfile'
$dockerfileHash = $(if (Test-Path -LiteralPath $dockerfilePath) { (Get-FileHash -LiteralPath $dockerfilePath -Algorithm SHA256).Hash } else { $null })
$composePath = Join-Path $projectRoot 'docker-compose.yml'
$composeHash = $(if (Test-Path -LiteralPath $composePath) { (Get-FileHash -LiteralPath $composePath -Algorithm SHA256).Hash } else { $null })
$composeText = $(if (Test-Path -LiteralPath $composePath) { Get-Content -LiteralPath $composePath -Raw -Encoding UTF8 } else { '' })
$workerMatch = [regex]::Match($composeText, 'PHP_CLI_SERVER_WORKERS:\s*["'']?(\d+)')
$declaredWorkerCount = $(if ($workerMatch.Success) { [int] $workerMatch.Groups[1].Value } else { $null })
$entrypointPath = Join-Path $projectRoot 'docker-entrypoint.sh'
$entrypointHash = $(if (Test-Path -LiteralPath $entrypointPath) { (Get-FileHash -LiteralPath $entrypointPath -Algorithm SHA256).Hash } else { $null })
$entrypointText = $(if (Test-Path -LiteralPath $entrypointPath) { Get-Content -LiteralPath $entrypointPath -Raw -Encoding UTF8 } else { '' })
$apcMatch = [regex]::Match($entrypointText, 'apc\.shm_size=([^\s\\]+)')
$apcShmSize = $(if ($apcMatch.Success) { $apcMatch.Groups[1].Value } else { $null })
if ($PSVersionTable.PSVersion.Major -lt 5) { throw 'PowerShell 5.1 or newer is required.' }
if ($WarmupIterations -lt 0) { throw 'WarmupIterations must be non-negative.' }
if ($Iterations -lt 1) { throw 'Iterations must be positive.' }
if ($Concurrency -lt 1) { throw 'Concurrency must be positive.' }
if ($AssertedActualWorkerCount -lt 0) { throw 'AssertedActualWorkerCount must be zero (unknown) or positive.' }
foreach ($assertion in @(
    [pscustomobject]@{ Name = 'NetworkConditionId'; Value = $NetworkConditionId },
    [pscustomobject]@{ Name = 'ResourceProfileId'; Value = $ResourceProfileId }
)) {
    if ($assertion.Value -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$') {
        throw "$($assertion.Name) must contain 1-64 safe identifier characters."
    }
}
$measurementRunIdGenerated = [string]::IsNullOrWhiteSpace($MeasurementRunId)
$measurementRunIdOrigin = $(if ($measurementRunIdGenerated) { 'script-generated' } else { 'caller-provided' })
if ($measurementRunIdGenerated) {
    $MeasurementRunId = [guid]::NewGuid().ToString('N')
}
if ($MeasurementRunId -notmatch '^[A-Za-z0-9._-]{1,64}$') {
    throw 'MeasurementRunId must contain 1-64 safe characters.'
}
$prefetchMeasurementRunId = $MeasurementRunId
if ($measurementRunIdGenerated) {
    $prefetchMeasurementRunId = [guid]::NewGuid().ToString('N')
}

function Header-Value { param($Headers, [string] $Name); return (@($Headers[$Name]) -join ', ') }

function Add-MeasurementRunId {
    param([string] $Url, [string] $RunId = $MeasurementRunId)
    $separator = $(if ($Url.Contains('?')) { '&' } else { '?' })
    return $Url + $separator + 'test_run_id=' + [uri]::EscapeDataString($RunId)
}

function Get-HealthSnapshot {
    param([string] $RunId = $MeasurementRunId)
    try {
        return Invoke-RestMethod -Method Get -Uri (Add-MeasurementRunId "$BaseUrl/?health=1" $RunId) `
            -Headers @{ 'X-JM-Test-Run-Id' = $RunId } -TimeoutSec $RequestTimeoutSeconds
    } catch {
        return $null
    }
}

function Get-NumericProperty {
    param($Object, [string] $Name)
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value) { return $null }
    try { return [int64] $property.Value } catch { return $null }
}

function Get-CounterDelta {
    param($Before, $After, [string] $Name)
    $beforeValue = Get-NumericProperty $Before $Name
    $afterValue = Get-NumericProperty $After $Name
    if ($null -eq $beforeValue -or $null -eq $afterValue) { return $null }
    return [int64] $afterValue - [int64] $beforeValue
}

function Remove-PerformanceTempTree {
    param([string] $Path, [string] $Label)
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) { return }
    $tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
    $resolved = [System.IO.Path]::GetFullPath((Resolve-Path -LiteralPath $Path).ProviderPath)
    $leaf = Split-Path -Leaf $resolved
    if (-not $resolved.StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase) -or
        $leaf -notlike 'jm-performance-barrier-*'
    ) {
        throw "$Label escaped the temporary directory: '$resolved'."
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}

function Invoke-Sample {
    param([string] $Name, [string] $Url, [string] $RunId = $MeasurementRunId)
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Method GET -Uri (Add-MeasurementRunId $Url $RunId) `
            -Headers @{ 'X-JM-Test-Run-Id' = $RunId } -TimeoutSec $RequestTimeoutSeconds
        $watch.Stop()
        $apiCalls = $null
        try {
            if ((Header-Value $response.Headers 'Content-Type') -match 'json') {
                $json = $response.Content | ConvertFrom-Json
                if ($null -ne $json.data.api_calls) { $apiCalls = [int] $json.data.api_calls }
            }
        } catch {}
        return [pscustomobject]@{
            name = $Name; ok = $true; status = [int] $response.StatusCode; elapsed_ms = [int64] $watch.ElapsedMilliseconds
            api_calls = $apiCalls; request_id = Header-Value $response.Headers 'X-JM-Request-Id'
            upstream_attempts = Header-Value $response.Headers 'X-JM-Upstream-Attempts'
            upstream_ms = Header-Value $response.Headers 'X-JM-Upstream-Ms'
            source_cache = Header-Value $response.Headers 'X-JM-Source-Cache'
            image_cache = Header-Value $response.Headers 'X-JM-Cache'
            prefetch = Header-Value $response.Headers 'X-JM-Prefetch'
        }
    } catch {
        $watch.Stop()
        $status = 0
        $headers = $null
        if ($null -ne $_.Exception.Response) {
            $status = [int] $_.Exception.Response.StatusCode
            $headers = $_.Exception.Response.Headers
        }
        return [pscustomobject]@{
            name = $Name; ok = $false; status = $status; elapsed_ms = [int64] $watch.ElapsedMilliseconds
            api_calls = $null; request_id = $(if ($null -ne $headers) { Header-Value $headers 'X-JM-Request-Id' } else { '' })
            upstream_attempts = $(if ($null -ne $headers) { Header-Value $headers 'X-JM-Upstream-Attempts' } else { '' })
            upstream_ms = $(if ($null -ne $headers) { Header-Value $headers 'X-JM-Upstream-Ms' } else { '' })
            source_cache = $(if ($null -ne $headers) { Header-Value $headers 'X-JM-Source-Cache' } else { '' })
            image_cache = $(if ($null -ne $headers) { Header-Value $headers 'X-JM-Cache' } else { '' })
            prefetch = $(if ($null -ne $headers) { Header-Value $headers 'X-JM-Prefetch' } else { '' }); error = $_.Exception.Message
        }
    }
}

function Percentile {
    param([long[]] $Values, [double] $P)
    if ($Values.Count -eq 0) { return $null }
    $sorted = @($Values | Sort-Object)
    $index = [Math]::Ceiling($P * $sorted.Count) - 1
    $index = [Math]::Max(0, [Math]::Min($sorted.Count - 1, $index))
    return [int64] $sorted[$index]
}

function Summarize {
    param([object[]] $Samples)
    $grouped = $Samples | Group-Object name
    $result = [ordered]@{}
    foreach ($group in $grouped) {
        $valid = @($group.Group | Where-Object ok)
        $times = [long[]] @($valid | ForEach-Object { [long] $_.elapsed_ms })
        $summary = [ordered]@{
            samples = $group.Count; successful = $valid.Count; failed = $group.Count - $valid.Count
            median_ms = Percentile $times 0.50; p95_ms = $(if ($times.Count -ge 40) { Percentile $times 0.95 } else { $null })
            p99_ms = $(if ($times.Count -ge 100) { Percentile $times 0.99 } else { $null })
            max_ms = $(if ($times.Count -gt 0) { [int64] (($times | Measure-Object -Maximum).Maximum) } else { $null })
            upstream_calls = [int] (($valid | Where-Object { $null -ne $_.api_calls } | Measure-Object api_calls -Sum).Sum)
        }
        $result[$group.Name] = $summary
    }
    return $result
}

$routes = [ordered]@{
    health = "$BaseUrl/?health=1"
    latest_1 = "$BaseUrl/?list=latest&page=1&format=min"
    latest_2 = "$BaseUrl/?list=latest&page=2&format=min"
    latest_3 = "$BaseUrl/?list=latest&page=3&format=min"
    latest_4 = "$BaseUrl/?list=latest&page=4&format=min"
    popular_1 = "$BaseUrl/?list=popular&page=1&order=new&format=min"
    popular_2 = "$BaseUrl/?list=popular&page=2&order=new&format=min"
    popular_3 = "$BaseUrl/?list=popular&page=3&order=new&format=min"
    popular_4 = "$BaseUrl/?list=popular&page=4&order=new&format=min"
    album = "$BaseUrl/?jmid=$AlbumId&format=min"
    image_no_prefetch = "$BaseUrl/?jmid=$AlbumId&chapter=$ChapterId&page=1&prefetch=0"
}

$healthBefore = Get-HealthSnapshot
$healthSnapshot = $healthBefore

# Cold samples are first observations and are not mixed into warm percentiles.
$cold = @()
foreach ($entry in $routes.GetEnumerator()) { $cold += Invoke-Sample "cold_$($entry.Key)" $entry.Value }
$coldImage = @($cold | Where-Object { $_.name -eq 'cold_image_no_prefetch' }) | Select-Object -First 1
$coldCachePrecondition = $null -ne $coldImage -and $coldImage.image_cache -eq 'MISS'
if (-not $coldCachePrecondition) { Write-Warning 'Cold image did not report X-JM-Cache: MISS; cold labels are not valid unless the container/cache was reset.' }

for ($i = 0; $i -lt $WarmupIterations; $i++) {
    foreach ($entry in $routes.GetEnumerator()) { Invoke-Sample "warmup_$($entry.Key)" $entry.Value | Out-Null }
}

$warm = @()
for ($i = 0; $i -lt $Iterations; $i++) {
    foreach ($entry in $routes.GetEnumerator()) { $warm += Invoke-Sample $entry.Key $entry.Value }
}

$concurrentUrl = Add-MeasurementRunId "$BaseUrl/?jmid=$AlbumId&chapter=$ChapterId&page=1&prefetch=0"
$concurrentBarrierRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('jm-performance-barrier-' + [guid]::NewGuid().ToString('N'))
$concurrentReadyPaths = @()
$concurrentReleasePath = Join-Path $concurrentBarrierRoot 'release'
$jobs = @()
$concurrent = @()
$concurrentWallMs = $null
try {
    New-Item -ItemType Directory -Path $concurrentBarrierRoot -Force | Out-Null
    for ($jobIndex = 1; $jobIndex -le $Concurrency; $jobIndex++) {
        $readyPath = Join-Path $concurrentBarrierRoot ("ready-$jobIndex")
        $concurrentReadyPaths += $readyPath
        $jobs += Start-Job -ScriptBlock {
            param($Url, $Index, $ReadyPath, $ReleasePath, $ReleaseTimeoutMs, $RunId, $TimeoutSeconds)
            [System.IO.File]::WriteAllText($ReadyPath, 'ready')
            $releaseWatch = [System.Diagnostics.Stopwatch]::StartNew()
            while (-not (Test-Path -LiteralPath $ReleasePath -PathType Leaf)) {
                if ($releaseWatch.ElapsedMilliseconds -ge $ReleaseTimeoutMs) {
                    throw "Concurrent performance barrier timed out for client $Index."
                }
                Start-Sleep -Milliseconds 10
            }
            $releaseWatch.Stop()
        $watch = [System.Diagnostics.Stopwatch]::StartNew()
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Method GET -Uri $Url `
                -Headers @{ 'X-JM-Test-Run-Id' = $RunId } -TimeoutSec $TimeoutSeconds
            $watch.Stop()
            $json = $null
            try { $json = $response.Content | ConvertFrom-Json } catch {}
            [pscustomobject]@{
                index = $Index; ok = $true; status = [int] $response.StatusCode; elapsed_ms = [int64] $watch.ElapsedMilliseconds
                cache = (@($response.Headers['X-JM-Cache']) -join ', '); request_id = (@($response.Headers['X-JM-Request-Id']) -join ', ')
                upstream_attempts = (@($response.Headers['X-JM-Upstream-Attempts']) -join ', '); upstream_ms = (@($response.Headers['X-JM-Upstream-Ms']) -join ', ')
                source_cache = (@($response.Headers['X-JM-Source-Cache']) -join ', '); prefetch = (@($response.Headers['X-JM-Prefetch']) -join ', ')
                api_calls = $(if ($null -ne $json -and $null -ne $json.data.api_calls) { [int] $json.data.api_calls } else { $null })
            }
        } catch {
            $watch.Stop()
            $response = $_.Exception.Response
            [pscustomobject]@{
                index = $Index; ok = $false; status = $(if ($null -ne $response) { [int] $response.StatusCode } else { 0 }); elapsed_ms = [int64] $watch.ElapsedMilliseconds
                cache = $(if ($null -ne $response) { @($response.Headers['X-JM-Cache']) -join ', ' } else { '' })
                request_id = $(if ($null -ne $response) { @($response.Headers['X-JM-Request-Id']) -join ', ' } else { '' })
                upstream_attempts = $(if ($null -ne $response) { @($response.Headers['X-JM-Upstream-Attempts']) -join ', ' } else { '' })
                upstream_ms = $(if ($null -ne $response) { @($response.Headers['X-JM-Upstream-Ms']) -join ', ' } else { '' })
                source_cache = $(if ($null -ne $response) { @($response.Headers['X-JM-Source-Cache']) -join ', ' } else { '' })
                prefetch = $(if ($null -ne $response) { @($response.Headers['X-JM-Prefetch']) -join ', ' } else { '' })
                api_calls = $null; error = $_.Exception.Message
            }
        }
        } -ArgumentList $concurrentUrl, $jobIndex, $readyPath, $concurrentReleasePath, 60000, $MeasurementRunId, $RequestTimeoutSeconds
    }

    $readyWatch = [System.Diagnostics.Stopwatch]::StartNew()
    while (@($concurrentReadyPaths | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf }).Count -ne $Concurrency) {
        $failedJobs = @($jobs | Where-Object { $_.State -eq 'Failed' })
        if ($failedJobs.Count -gt 0) {
            throw "Concurrent performance client failed before release: $($failedJobs[0].Id)."
        }
        if ($readyWatch.Elapsed.TotalSeconds -ge 60) {
            throw "Concurrent performance clients did not all become ready within 60 seconds."
        }
        Start-Sleep -Milliseconds 20
    }
    $readyWatch.Stop()

    $concurrentBatchWatch = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        [System.IO.File]::WriteAllText($concurrentReleasePath, 'release')
        $completionWatch = [System.Diagnostics.Stopwatch]::StartNew()
        $completionTimeoutSeconds = $RequestTimeoutSeconds + 30
        while ($true) {
            $activeJobs = @($jobs | Where-Object { $_.State -in @('Running', 'NotStarted') })
            if ($activeJobs.Count -eq 0) { break }
            if ($completionWatch.Elapsed.TotalSeconds -ge $completionTimeoutSeconds) {
                throw "Concurrent performance clients did not finish within $completionTimeoutSeconds seconds."
            }
            Wait-Job -Job $activeJobs -Timeout 1 | Out-Null
        }
        $completionWatch.Stop()
        $concurrent = @($jobs | Receive-Job -ErrorAction Stop)
    } finally {
        $concurrentBatchWatch.Stop()
        $concurrentWallMs = [int64] $concurrentBatchWatch.ElapsedMilliseconds
    }
} finally {
    foreach ($job in $jobs) {
        if ($job.State -in @('Running', 'NotStarted')) {
            Stop-Job -Job $job -ErrorAction SilentlyContinue
        }
        Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
    }
    Remove-PerformanceTempTree -Path $concurrentBarrierRoot -Label 'Performance concurrency barrier cleanup'
}
$concurrentClientElapsedMs = [int64] (($concurrent | Where-Object ok | Measure-Object elapsed_ms -Sum).Sum)
$clientOccupancyRatioRaw = $null
$clientOccupancyRatio = $null
if ($AssertedActualWorkerCount -gt 0 -and $concurrentWallMs -gt 0) {
    $clientOccupancyRatioRaw = [Math]::Round(
        $concurrentClientElapsedMs / ([double] $concurrentWallMs * $AssertedActualWorkerCount),
        4
    )
    $clientOccupancyRatio = [Math]::Round([Math]::Min(1.0, $clientOccupancyRatioRaw), 4)
}

$prefetchHealthBefore = Get-HealthSnapshot -RunId $prefetchMeasurementRunId
$prefetchAggregateBefore = $(if ($null -ne $prefetchHealthBefore) { $prefetchHealthBefore.diagnostics.prefetch.aggregate } else { $null })
$prefetchWatch = [System.Diagnostics.Stopwatch]::StartNew()
$prefetchTrigger = Invoke-Sample -Name 'image_prefetch_trigger' -Url "$BaseUrl/?jmid=$AlbumId&chapter=$ChapterId&page=1" -RunId $prefetchMeasurementRunId
$prefetchHealthAfter = Get-HealthSnapshot -RunId $prefetchMeasurementRunId
while ($prefetchWatch.Elapsed.TotalSeconds -lt $PrefetchObservationSeconds) {
    $candidateAggregate = $(if ($null -ne $prefetchHealthAfter) { $prefetchHealthAfter.diagnostics.prefetch.aggregate } else { $null })
    $eventDelta = Get-CounterDelta $prefetchAggregateBefore $candidateAggregate 'events'
    if ($null -ne $eventDelta -and $eventDelta -gt 0) { break }
    Start-Sleep -Milliseconds 100
    $prefetchHealthAfter = Get-HealthSnapshot -RunId $prefetchMeasurementRunId
}
$prefetchWatch.Stop()
$prefetchAggregateAfter = $(if ($null -ne $prefetchHealthAfter) { $prefetchHealthAfter.diagnostics.prefetch.aggregate } else { $null })
$prefetchFollowups = @()
foreach ($prefetchPage in 2..3) {
    $prefetchFollowups += Invoke-Sample -Name "prefetch_followup_$prefetchPage" `
        -Url "$BaseUrl/?jmid=$AlbumId&chapter=$ChapterId&page=$prefetchPage&prefetch=0" `
        -RunId $prefetchMeasurementRunId
}
$prefetchStoredDelta = Get-CounterDelta $prefetchAggregateBefore $prefetchAggregateAfter 'stored'
$prefetchFollowupHits = @($prefetchFollowups | Where-Object { $_.ok -and $_.image_cache -eq 'HIT' }).Count
$prefetchAttribution = Resolve-PrefetchAttributionEvidence `
    -MeasurementRunIdGenerated $measurementRunIdGenerated `
    -TestCacheScoped ($null -ne $prefetchHealthBefore -and $prefetchHealthBefore.diagnostics.test_cache_scoped -eq $true) `
    -TriggerSucceeded ($null -ne $prefetchTrigger -and $prefetchTrigger.ok -eq $true) `
    -AggregateBefore $prefetchAggregateBefore `
    -AggregateAfter $prefetchAggregateAfter `
    -FollowupHits $prefetchFollowupHits

$healthAfter = Get-HealthSnapshot
$apcuBefore = $(if ($null -ne $healthBefore) { $healthBefore.diagnostics.apcu_details } else { $null })
$apcuAfter = $(if ($null -ne $healthAfter) { $healthAfter.diagnostics.apcu_details } else { $null })
$prefetchConfigSnapshot = $(if ($null -ne $healthSnapshot) { $healthSnapshot.diagnostics.prefetch } else { $null })
$cachePolicySnapshot = $(if ($null -ne $healthSnapshot) { $healthSnapshot.diagnostics.cache_policy } else { $null })
$runtimePrefetchPolicy = $(if ($null -ne $prefetchConfigSnapshot) {
    [ordered]@{
        default_pages = $prefetchConfigSnapshot.default_pages
        high_priority_pages = $prefetchConfigSnapshot.high_priority_pages
        next_chapter_pages = $prefetchConfigSnapshot.next_chapter_pages
        wall_budget_ms = $prefetchConfigSnapshot.wall_budget_ms
        byte_budget = $prefetchConfigSnapshot.byte_budget
        max_active = $prefetchConfigSnapshot.max_active
        low_memory_policy = $prefetchConfigSnapshot.low_memory_policy
    }
} else { $null })
$runtimeCachePolicy = $(if ($null -ne $cachePolicySnapshot) {
    [ordered]@{
        page_cache_enabled = $cachePolicySnapshot.page_cache_enabled
        page_cache_ttl_seconds = $cachePolicySnapshot.page_cache_ttl_seconds
        max_item_bytes = $cachePolicySnapshot.max_item_bytes
        page_cache_min_free_bytes = $cachePolicySnapshot.page_cache_min_free_bytes
        page_cache_min_free_ratio = $cachePolicySnapshot.page_cache_min_free_ratio
        prefetch_min_free_bytes = $cachePolicySnapshot.prefetch_min_free_bytes
        prefetch_min_free_ratio = $cachePolicySnapshot.prefetch_min_free_ratio
    }
} else { $null })
$runtimePrefetchPolicyHash = Get-PerformanceEvidenceObjectSha256 -Value $runtimePrefetchPolicy
$runtimeCachePolicyHash = Get-PerformanceEvidenceObjectSha256 -Value $runtimeCachePolicy
$comparisonEqualityEnvironmentFields = @(
    'base_url', 'album_id', 'chapter_id', 'warmup_iterations', 'iterations', 'concurrency',
    'script_version', 'script_sha256', 'asserted_runtime_kind', 'asserted_runtime_source_binding',
    'asserted_actual_worker_count', 'request_timeout_seconds', 'runtime_apcu_total_memory_bytes',
    'powershell', 'php_version', 'asserted_network_condition_id', 'asserted_resource_profile_id',
    'runtime_prefetch_policy_sha256', 'runtime_cache_policy_sha256'
)
$comparisonRequiredEnvironmentFields = @(
    'base_url', 'album_id', 'chapter_id', 'powershell', 'warmup_iterations', 'iterations', 'concurrency',
    'script_version', 'script_sha256', 'local_source_performance_evidence_sha256',
    'local_source_compose_sha256', 'local_source_index_sha256', 'local_source_dockerfile_sha256',
    'local_source_entrypoint_sha256', 'asserted_runtime_kind', 'asserted_runtime_source_binding',
    'asserted_runtime_image_digest', 'measurement_run_id', 'measurement_run_id_origin',
    'request_timeout_seconds', 'local_source_compose_declared_worker_count',
    'asserted_actual_worker_count', 'local_source_entrypoint_apc_shm_size',
    'runtime_apcu_total_memory_bytes', 'api_version', 'php_version',
    'asserted_network_condition_id', 'asserted_resource_profile_id',
    'runtime_prefetch_policy', 'runtime_prefetch_policy_sha256',
    'runtime_cache_policy', 'runtime_cache_policy_sha256',
    'prefetch_config', 'cache_policy'
)
$comparisonExpectedRoutes = [string[]] @($routes.Keys)
$comparisonRequiredMetricFields = @(
    'samples', 'successful', 'failed', 'median_ms', 'p95_ms', 'p99_ms', 'max_ms', 'upstream_calls'
)

$report = [ordered]@{
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    environment = [ordered]@{
        base_url = $BaseUrl; album_id = $AlbumId; chapter_id = $ChapterId
        powershell = $PSVersionTable.PSVersion.ToString(); warmup_iterations = $WarmupIterations
        iterations = $Iterations; concurrency = $Concurrency
        script_version = $scriptVersion; script_sha256 = $scriptHash
        local_source_performance_evidence_sha256 = $performanceEvidenceHash
        local_source_compose_sha256 = $composeHash
        local_source_index_sha256 = $indexHash
        local_source_dockerfile_sha256 = $dockerfileHash
        local_source_entrypoint_sha256 = $entrypointHash
        asserted_runtime_kind = $RuntimeKind
        asserted_runtime_source_binding = $RuntimeSourceBinding
        asserted_runtime_image_digest = $(if ([string]::IsNullOrWhiteSpace($RuntimeImageDigest)) { $null } else { $RuntimeImageDigest })
        asserted_network_condition_id = $NetworkConditionId
        asserted_resource_profile_id = $ResourceProfileId
        measurement_run_id = $MeasurementRunId
        measurement_run_id_origin = $measurementRunIdOrigin
        request_timeout_seconds = $RequestTimeoutSeconds
        local_source_compose_declared_worker_count = $declaredWorkerCount
        asserted_actual_worker_count = $(if ($AssertedActualWorkerCount -gt 0) { $AssertedActualWorkerCount } else { $null })
        local_source_entrypoint_apc_shm_size = $apcShmSize
        runtime_apcu_total_memory_bytes = $(if ($null -ne $apcuBefore) { $apcuBefore.total_memory_bytes } else { $null })
        api_version = $(if ($null -ne $healthSnapshot) { $healthSnapshot.version } else { $null })
        php_version = $(if ($null -ne $healthSnapshot) { $healthSnapshot.diagnostics.php } else { $null })
        runtime_prefetch_policy = $runtimePrefetchPolicy
        runtime_prefetch_policy_sha256 = $runtimePrefetchPolicyHash
        runtime_cache_policy = $runtimeCachePolicy
        runtime_cache_policy_sha256 = $runtimeCachePolicyHash
        prefetch_config = $prefetchConfigSnapshot
        cache_policy = $cachePolicySnapshot
    }
    cold_samples = $cold
    preconditions = [ordered]@{
        cold_image_was_miss = $coldCachePrecondition
        measurement_run_id = $MeasurementRunId
        measurement_run_id_origin = $measurementRunIdOrigin
        test_mode_cache_namespace = $(if ($null -ne $healthBefore) { [bool] $healthBefore.diagnostics.test_cache_scoped } else { $false })
    }
    warm_summary = Summarize $warm
    warm_samples = $warm
    concurrent_samples = $concurrent
    concurrency_probe = [ordered]@{
        samples = $concurrent.Count
        successful = @($concurrent | Where-Object ok).Count
        wall_ms = $concurrentWallMs
        summed_client_elapsed_ms = $concurrentClientElapsedMs
        asserted_actual_worker_count = $(if ($AssertedActualWorkerCount -gt 0) { $AssertedActualWorkerCount } else { $null })
        client_occupancy_ratio_raw = $clientOccupancyRatioRaw
        client_occupancy_ratio = $clientOccupancyRatio
        occupancy_method = 'sum(successful client elapsed) / (request batch wall * asserted workers); includes client/network/queue time and is not server worker busy time'
    }
    apcu = [ordered]@{
        apcu_before = $apcuBefore
        apcu_after = $apcuAfter
        hits_delta = Get-CounterDelta $apcuBefore $apcuAfter 'hits'
        misses_delta = Get-CounterDelta $apcuBefore $apcuAfter 'misses'
        inserts_delta = Get-CounterDelta $apcuBefore $apcuAfter 'inserts'
        expunges_delta = Get-CounterDelta $apcuBefore $apcuAfter 'expunges'
        fragmentation_ratio_before = $(if ($null -ne $apcuBefore) { $apcuBefore.fragmentation_ratio } else { $null })
        fragmentation_ratio_after = $(if ($null -ne $apcuAfter) { $apcuAfter.fragmentation_ratio } else { $null })
    }
    prefetch_probe = [ordered]@{
        trigger = $prefetchTrigger
        followups = $prefetchFollowups
        aggregate_before = $prefetchAggregateBefore
        aggregate_after = $prefetchAggregateAfter
        events_delta = $prefetchAttribution.events_delta
        attempted_delta = Get-CounterDelta $prefetchAggregateBefore $prefetchAggregateAfter 'attempted'
        stored_delta = $prefetchStoredDelta
        cache_hits_delta = Get-CounterDelta $prefetchAggregateBefore $prefetchAggregateAfter 'cache_hits'
        bytes_delta = Get-CounterDelta $prefetchAggregateBefore $prefetchAggregateAfter 'bytes'
        wall_ms = Get-CounterDelta $prefetchAggregateBefore $prefetchAggregateAfter 'wall_ms'
        observation_elapsed_ms = [int64] $prefetchWatch.ElapsedMilliseconds
        followup_hits = $prefetchFollowupHits
        attribution_verified = $prefetchAttribution.attribution_verified
        attribution_measurement_run_id = $prefetchMeasurementRunId
        attribution_origin = $prefetchAttribution.origin
        attribution_reason = $prefetchAttribution.reason
        utilization_ratio = $prefetchAttribution.utilization_ratio
        waste_ratio = $prefetchAttribution.waste_ratio
        ratio_scope = 'Ratios require a script-generated fresh attribution namespace, a scoped exact-zero initial aggregate with no skips, a successful trigger, exactly one completed event, and a positive stored delta. Follow-up pages 2-3 are observed; stored pages not read by this probe count as unused.'
    }
}
$currentComparisonEvidence = Resolve-PerformanceReportComparisonEvidence `
    -Report $report -RequiredEnvironmentFields $comparisonRequiredEnvironmentFields `
    -ExpectedRoutes $comparisonExpectedRoutes -RequiredMetricFields $comparisonRequiredMetricFields `
    -Label 'CURRENT'
$report['preconditions']['comparison_evidence_complete'] = [bool] $currentComparisonEvidence.evidence_complete
$report['preconditions']['comparison_evidence_reason'] = $currentComparisonEvidence.reason
$report['preconditions']['comparison_required_environment_fields'] = $comparisonRequiredEnvironmentFields
$report['preconditions']['comparison_equality_environment_fields'] = $comparisonEqualityEnvironmentFields
$report['preconditions']['comparison_expected_routes'] = $comparisonExpectedRoutes
$report['preconditions']['comparison_required_metric_fields'] = $comparisonRequiredMetricFields

if (-not [string]::IsNullOrWhiteSpace($ComparePath)) {
    if (-not (Test-Path -LiteralPath $ComparePath -PathType Leaf)) { throw "ComparePath not found: $ComparePath" }
    $before = Get-Content -LiteralPath $ComparePath -Raw -Encoding UTF8 | ConvertFrom-Json
    $comparisonEvidence = Resolve-PerformanceComparisonEvidence `
        -BeforeReport $before -AfterReport $report `
        -RequiredEnvironmentFields $comparisonRequiredEnvironmentFields `
        -EqualityEnvironmentFields $comparisonEqualityEnvironmentFields `
        -ExpectedRoutes $comparisonExpectedRoutes -RequiredMetricFields $comparisonRequiredMetricFields
    if (-not $comparisonEvidence.comparable -or -not $comparisonEvidence.evidence_complete) {
        throw "ComparePath evidence is incomplete or incompatible: $($comparisonEvidence.reason)."
    }
    $report.comparison_preconditions = [ordered]@{
        evidence_complete = $comparisonEvidence.evidence_complete
        reason = $comparisonEvidence.reason
        before = $comparisonEvidence.before
        after = $comparisonEvidence.after
        required_environment_fields = $comparisonRequiredEnvironmentFields
        equality_environment_fields = $comparisonEqualityEnvironmentFields
        expected_routes = $comparisonExpectedRoutes
        required_metric_fields = $comparisonRequiredMetricFields
    }
    $comparison = [ordered]@{}
    foreach ($property in $comparisonExpectedRoutes) {
        $beforeProperty = $before.warm_summary.PSObject.Properties[$property]
        $afterValue = $report.warm_summary[$property]
        $beforeSuccessRate = [decimal] $beforeProperty.Value.successful / [decimal] $beforeProperty.Value.samples
        $afterSuccessRate = [decimal] $afterValue.successful / [decimal] $afterValue.samples
        $comparison[$property] = [ordered]@{
            before_successful = $beforeProperty.Value.successful
            after_successful = $afterValue.successful
            successful_delta = [int64] $afterValue.successful - [int64] $beforeProperty.Value.successful
            before_failed = $beforeProperty.Value.failed
            after_failed = $afterValue.failed
            failed_delta = [int64] $afterValue.failed - [int64] $beforeProperty.Value.failed
            before_success_rate = [Math]::Round($beforeSuccessRate, 6)
            after_success_rate = [Math]::Round($afterSuccessRate, 6)
            success_rate_delta = [Math]::Round($afterSuccessRate - $beforeSuccessRate, 6)
            before_median_ms = $beforeProperty.Value.median_ms
            after_median_ms = $afterValue.median_ms
            before_p95_ms = $beforeProperty.Value.p95_ms
            after_p95_ms = $afterValue.p95_ms
            before_p99_ms = $beforeProperty.Value.p99_ms
            after_p99_ms = $afterValue.p99_ms
            before_upstream_calls = $beforeProperty.Value.upstream_calls
            after_upstream_calls = $afterValue.upstream_calls
            median_delta_ms = $(if ($null -ne $beforeProperty.Value.median_ms -and $null -ne $afterValue.median_ms) { [int64] $afterValue.median_ms - [int64] $beforeProperty.Value.median_ms } else { $null })
            p95_delta_ms = $(if ($null -ne $beforeProperty.Value.p95_ms -and $null -ne $afterValue.p95_ms) { [int64] $afterValue.p95_ms - [int64] $beforeProperty.Value.p95_ms } else { $null })
            p99_delta_ms = $(if ($null -ne $beforeProperty.Value.p99_ms -and $null -ne $afterValue.p99_ms) { [int64] $afterValue.p99_ms - [int64] $beforeProperty.Value.p99_ms } else { $null })
            upstream_calls_delta = [int] $afterValue.upstream_calls - [int] $beforeProperty.Value.upstream_calls
        }
    }
    $report.comparison = $comparison
    $report.comparison_provenance = [ordered]@{
        before = [ordered]@{
            local_source_index_sha256 = $before.environment.local_source_index_sha256
            local_source_dockerfile_sha256 = $before.environment.local_source_dockerfile_sha256
            local_source_compose_sha256 = $before.environment.local_source_compose_sha256
            local_source_entrypoint_sha256 = $before.environment.local_source_entrypoint_sha256
            local_source_performance_evidence_sha256 = $before.environment.local_source_performance_evidence_sha256
            local_source_compose_declared_worker_count = $before.environment.local_source_compose_declared_worker_count
            local_source_entrypoint_apc_shm_size = $before.environment.local_source_entrypoint_apc_shm_size
            asserted_runtime_kind = $before.environment.asserted_runtime_kind
            asserted_runtime_source_binding = $before.environment.asserted_runtime_source_binding
            asserted_actual_worker_count = $before.environment.asserted_actual_worker_count
            asserted_runtime_image_digest = $before.environment.asserted_runtime_image_digest
            asserted_network_condition_id = $before.environment.asserted_network_condition_id
            asserted_resource_profile_id = $before.environment.asserted_resource_profile_id
            runtime_prefetch_policy_sha256 = $before.environment.runtime_prefetch_policy_sha256
            runtime_cache_policy_sha256 = $before.environment.runtime_cache_policy_sha256
            runtime_apcu_total_memory_bytes = $before.environment.runtime_apcu_total_memory_bytes
        }
        after = [ordered]@{
            local_source_index_sha256 = $report.environment.local_source_index_sha256
            local_source_dockerfile_sha256 = $report.environment.local_source_dockerfile_sha256
            local_source_compose_sha256 = $report.environment.local_source_compose_sha256
            local_source_entrypoint_sha256 = $report.environment.local_source_entrypoint_sha256
            local_source_performance_evidence_sha256 = $report.environment.local_source_performance_evidence_sha256
            local_source_compose_declared_worker_count = $report.environment.local_source_compose_declared_worker_count
            local_source_entrypoint_apc_shm_size = $report.environment.local_source_entrypoint_apc_shm_size
            asserted_runtime_kind = $report.environment.asserted_runtime_kind
            asserted_runtime_source_binding = $report.environment.asserted_runtime_source_binding
            asserted_actual_worker_count = $report.environment.asserted_actual_worker_count
            asserted_runtime_image_digest = $report.environment.asserted_runtime_image_digest
            asserted_network_condition_id = $report.environment.asserted_network_condition_id
            asserted_resource_profile_id = $report.environment.asserted_resource_profile_id
            runtime_prefetch_policy_sha256 = $report.environment.runtime_prefetch_policy_sha256
            runtime_cache_policy_sha256 = $report.environment.runtime_cache_policy_sha256
            runtime_apcu_total_memory_bytes = $report.environment.runtime_apcu_total_memory_bytes
        }
        note = 'Local code and asserted image provenance may differ between BEFORE and AFTER and are recorded, not equality-gated. Asserted runtime kind/source binding/worker count and observed APCu capacity must match.'
    }
}

$finalIndexHash = $(if (Test-Path -LiteralPath $indexPath) { (Get-FileHash -LiteralPath $indexPath -Algorithm SHA256).Hash } else { $null })
if ([string] $indexHash -ne [string] $finalIndexHash) {
    throw 'index.php changed while the performance measurement was running; discard this report.'
}
$finalPerformanceEvidenceHash = $(if (Test-Path -LiteralPath $performanceEvidencePath) { (Get-FileHash -LiteralPath $performanceEvidencePath -Algorithm SHA256).Hash } else { $null })
if ([string] $performanceEvidenceHash -ne [string] $finalPerformanceEvidenceHash) {
    throw 'Performance evidence helper changed while the performance measurement was running; discard this report.'
}
$finalScriptHash = $(if (Test-Path -LiteralPath $MyInvocation.MyCommand.Path) { (Get-FileHash -LiteralPath $MyInvocation.MyCommand.Path -Algorithm SHA256).Hash } else { $null })
if ([string] $scriptHash -ne [string] $finalScriptHash) {
    throw 'performance-baseline.ps1 changed while the performance measurement was running; discard this report.'
}
$finalDockerfileHash = $(if (Test-Path -LiteralPath $dockerfilePath) { (Get-FileHash -LiteralPath $dockerfilePath -Algorithm SHA256).Hash } else { $null })
if ([string] $dockerfileHash -ne [string] $finalDockerfileHash) {
    throw 'Dockerfile changed while the performance measurement was running; discard this report.'
}
$finalComposeHash = $(if (Test-Path -LiteralPath $composePath) { (Get-FileHash -LiteralPath $composePath -Algorithm SHA256).Hash } else { $null })
if ([string] $composeHash -ne [string] $finalComposeHash) {
    throw 'docker-compose.yml changed while the performance measurement was running; discard this report.'
}
$finalEntrypointHash = $(if (Test-Path -LiteralPath $entrypointPath) { (Get-FileHash -LiteralPath $entrypointPath -Algorithm SHA256).Hash } else { $null })
if ([string] $entrypointHash -ne [string] $finalEntrypointHash) {
    throw 'docker-entrypoint.sh changed while the performance measurement was running; discard this report.'
}

$jsonText = $report | ConvertTo-Json -Depth 10
Set-Content -LiteralPath $OutputPath -Value $jsonText -Encoding UTF8
Write-Output "Performance report written to $OutputPath"
