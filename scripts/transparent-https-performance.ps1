param(
    [string] $PythonPath = 'C:\Users\MZB\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe',
    [string] $PhpPath = 'D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe',
    [string] $ApcuExtension = 'D:\jm\.tools\php_apcu-5.1.28-8.3-nts-vs16-x64\php_apcu.dll',
    [string] $BeforeSourceDirectory = '',
    [string] $AfterSourcePath = '',
    [ValidateRange(0, 1000)]
    [int] $WarmupIterations = 10,
    [ValidateRange(1, 10000)]
    [int] $Iterations = 120,
    [ValidateRange(1, 32)]
    [int] $Concurrency = 10,
    [ValidateRange(1, 120)]
    [int] $RequestTimeoutSeconds = 30,
    [string] $AlbumId = '350234',
    [string] $ChapterId = '350234',
    [string] $OutputPath = '.\performance-evidence\transparent-https-common-ab-20260717.json'
)

$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSVersion.Major -lt 5) { throw 'PowerShell 5.1 or newer is required.' }

$projectRoot = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($BeforeSourceDirectory)) {
    $BeforeSourceDirectory = Join-Path $projectRoot 'performance-evidence\before-source-20260713T061309Z'
}
if ([string]::IsNullOrWhiteSpace($AfterSourcePath)) {
    $AfterSourcePath = Join-Path $projectRoot 'index.php'
}
$proxyPath = Join-Path $projectRoot 'tests\fixtures\transparent_https_proxy.py'
$fixturePath = Join-Path $projectRoot 'tests\fixtures\upstream-router.php'
$probePath = Join-Path $projectRoot 'tests\fixtures\transparent_https_probe.php'
$scriptPath = $MyInvocation.MyCommand.Path
$beforeIndexPath = Join-Path $BeforeSourceDirectory 'index.php'
$beforeManifestPath = Join-Path $BeforeSourceDirectory 'manifest.json'

function Assert-True {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function Resolve-RequiredFile {
    param([string] $Path, [string] $Label)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label not found: $Path" }
    return (Resolve-Path -LiteralPath $Path).Path
}

function Get-Sha256 {
    param([string] $Path)
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToUpperInvariant()
}

function Get-ObjectSha256 {
    param($Value)
    $json = $Value | ConvertTo-Json -Depth 30 -Compress
    $encoding = New-Object System.Text.UTF8Encoding($false)
    $bytes = $encoding.GetBytes($json)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return (-join ($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('X2') }))
    } finally {
        $sha.Dispose()
    }
}

function Get-BytesSha256 {
    param([byte[]] $Bytes)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { return (-join ($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('X2') })) } finally { $sha.Dispose() }
}

function Get-PathHashMap {
    param($Paths)
    $result = [ordered]@{}
    foreach ($name in @($Paths.Keys)) {
        $result[$name] = Get-Sha256 ([string] $Paths[$name])
    }
    return $result
}

function Get-ProcessIdentity {
    param($Process)
    if ($null -eq $Process) { return $null }
    $hasExited = $true
    try { $hasExited = [bool] $Process.HasExited } catch { $hasExited = $true }
    return [ordered]@{
        pid = [int] $Process.Id
        start_time_utc = $Process.StartTime.ToUniversalTime().ToString('o')
        path = $Process.Path
        has_exited = $hasExited
    }
}

function Test-EnvironmentMatchesSnapshot {
    param([string[]] $Names, $Snapshot)
    foreach ($name in $Names) {
        $actual = [Environment]::GetEnvironmentVariable($name, 'Process')
        $expected = $Snapshot[$name]
        if ($null -eq $actual -and $null -eq $expected) { continue }
        if ([string] $actual -cne [string] $expected) { return $false }
    }
    return $true
}

function Get-FreeLoopbackPort {
    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    try {
        $listener.Start()
        return ([System.Net.IPEndPoint] $listener.LocalEndpoint).Port
    } finally {
        $listener.Stop()
    }
}

function Get-DistinctLoopbackPorts {
    param([int] $Count)
    $ports = New-Object 'System.Collections.Generic.HashSet[int]'
    while ($ports.Count -lt $Count) { $ports.Add((Get-FreeLoopbackPort)) | Out-Null }
    return @($ports)
}

function Stop-ManagedProcess {
    param($Process)
    if ($null -eq $Process) { return }
    try {
        if (-not $Process.HasExited) {
            Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
            try { $Process.WaitForExit(5000) | Out-Null } catch {}
        }
    } finally {
        $Process.Dispose()
    }
}

function Stop-ManagedProcessId {
    param([int] $Id)
    $process = Get-Process -Id $Id -ErrorAction SilentlyContinue
    if ($null -eq $process) { return }
    try {
        Stop-Process -Id $Id -Force -ErrorAction SilentlyContinue
        try { $process.WaitForExit(5000) | Out-Null } catch {}
    } finally {
        $process.Dispose()
    }
}

function Remove-ControlledPerformanceTree {
    param([string] $Path)
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) { return }
    $tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
    $resolved = [System.IO.Path]::GetFullPath((Resolve-Path -LiteralPath $Path).ProviderPath)
    $leaf = Split-Path -Leaf $resolved
    if (-not $resolved.StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase) -or
        $leaf -notmatch '^jm-transparent-https-performance-[0-9a-f]{32}$'
    ) {
        throw "Refusing to delete uncontrolled performance path: $resolved"
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}

function Wait-JsonFile {
    param([string] $Path, $Process, [string] $ErrorLog)
    for ($attempt = 1; $attempt -le 150; $attempt++) {
        if ($Process.HasExited) {
            $tail = if (Test-Path -LiteralPath $ErrorLog) { (Get-Content -LiteralPath $ErrorLog -Tail 30) -join "`n" } else { '' }
            throw "Process $($Process.Id) exited before state became ready. $tail"
        }
        if (Test-Path -LiteralPath $Path -PathType Leaf) {
            try { return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json } catch {}
        }
        Start-Sleep -Milliseconds 100
    }
    throw "Timed out waiting for state file: $Path"
}

function Wait-Fixture {
    param([string] $FixtureUrl, $Process, [string] $ErrorLog)
    for ($attempt = 1; $attempt -le 150; $attempt++) {
        if ($Process.HasExited) {
            $tail = if (Test-Path -LiteralPath $ErrorLog) { (Get-Content -LiteralPath $ErrorLog -Tail 30) -join "`n" } else { '' }
            throw "Fixture process exited before ready. $tail"
        }
        try {
            $state = Invoke-RestMethod -Method Get -Uri "$FixtureUrl/__stats?run_id=ready" -TimeoutSec 2
            if ($state.ok -eq $true) { return }
        } catch {}
        Start-Sleep -Milliseconds 100
    }
    throw 'Fixture did not become ready.'
}

function Wait-Api {
    param([string] $BaseUrl, $Process, [string] $ErrorLog)
    for ($attempt = 1; $attempt -le 200; $attempt++) {
        if ($Process.HasExited) {
            $tail = if (Test-Path -LiteralPath $ErrorLog) { (Get-Content -LiteralPath $ErrorLog -Tail 40) -join "`n" } else { '' }
            throw "API process exited before ready. $tail"
        }
        try {
            $state = Invoke-RestMethod -Method Get -Uri "$BaseUrl/?health=1" -TimeoutSec 2
            if ($state.code -eq 200 -and $state.success -eq $true) { return $state }
        } catch {}
        Start-Sleep -Milliseconds 100
    }
    $tail = if (Test-Path -LiteralPath $ErrorLog) { (Get-Content -LiteralPath $ErrorLog -Tail 40) -join "`n" } else { '' }
    throw "API did not become ready. $tail"
}

function Header-Value {
    param($Headers, [string] $Name)
    if ($null -eq $Headers) { return '' }
    try { return (@($Headers[$Name]) -join ', ') } catch { return '' }
}

function Get-ResponseBodyBytes {
    param($Response)
    $rawStreamProperty = $Response.PSObject.Properties['RawContentStream']
    if ($null -ne $rawStreamProperty -and $null -ne $rawStreamProperty.Value) {
        $stream = $rawStreamProperty.Value
        if ($stream -is [System.IO.MemoryStream]) { return [byte[]] $stream.ToArray() }
        if ($stream.CanSeek) {
            $originalPosition = $stream.Position
            try {
                $stream.Position = 0
                $memory = New-Object System.IO.MemoryStream
                try { $stream.CopyTo($memory); return [byte[]] $memory.ToArray() } finally { $memory.Dispose() }
            } finally {
                $stream.Position = $originalPosition
            }
        }
    }
    if ($Response.Content -is [byte[]]) { return [byte[]] $Response.Content }
    return [System.Text.Encoding]::UTF8.GetBytes([string] $Response.Content)
}

function Test-RouteResponseContract {
    param([string] $Name, $Response, [byte[]] $BodyBytes)
    if ([int] $Response.StatusCode -ne 200) { return [ordered]@{ valid = $false; reason = 'status-not-200'; fixture_marker = $false } }
    if ($BodyBytes.Length -eq 0) { return [ordered]@{ valid = $false; reason = 'empty-body'; fixture_marker = $false } }
    $contentType = Header-Value $Response.Headers 'Content-Type'
    if ($Name -eq 'image_no_prefetch') {
        if ($contentType -notmatch '^image/(?:png|webp|jpeg|gif)\b') { return [ordered]@{ valid = $false; reason = 'image-content-type-invalid'; fixture_marker = $false } }
        $cacheState = Header-Value $Response.Headers 'X-JM-Cache'
        if ($cacheState -notin @('MISS', 'HIT')) { return [ordered]@{ valid = $false; reason = 'image-cache-header-invalid'; fixture_marker = $false } }
        return [ordered]@{ valid = $true; reason = 'complete'; fixture_marker = $false }
    }
    if ($contentType -notmatch '(?i)application/json') { return [ordered]@{ valid = $false; reason = 'json-content-type-invalid'; fixture_marker = $false } }
    try {
        $json = [System.Text.Encoding]::UTF8.GetString($BodyBytes) | ConvertFrom-Json
    } catch {
        return [ordered]@{ valid = $false; reason = 'json-body-invalid'; fixture_marker = $false }
    }
    if ($json.code -ne 200 -or $json.success -ne $true) { return [ordered]@{ valid = $false; reason = 'business-success-invalid'; fixture_marker = $false } }
    if ($Name -eq 'health') {
        $valid = -not [string]::IsNullOrWhiteSpace([string] $json.version) -and $null -ne $json.diagnostics
        return [ordered]@{ valid = $valid; reason = if ($valid) { 'complete' } else { 'health-shape-invalid' }; fixture_marker = $false }
    }
    if ($Name -eq 'latest_page_1' -or $Name -eq 'latest_page_4') {
        $expectedPage = if ($Name -eq 'latest_page_1') { 1 } else { 4 }
        $expectedFirstName = if ($Name -eq 'latest_page_1') { 'Fixture Album 1' } else { 'Fixture Album 61' }
        $items = @($json.data.items)
        $valid = $json.data.mode -eq 'latest' -and [int] $json.data.page -eq $expectedPage -and
            $items.Count -eq 20 -and [string] $items[0].name -ceq $expectedFirstName
        return [ordered]@{ valid = $valid; reason = if ($valid) { 'complete' } else { 'fixture-list-contract-invalid' }; fixture_marker = $valid }
    }
    if ($Name -eq 'album') {
        $valid = [string] $json.data.album.album_id -ceq $AlbumId -and [string] $json.data.album.name -ceq 'Fixture Album'
        return [ordered]@{ valid = $valid; reason = if ($valid) { 'complete' } else { 'fixture-album-contract-invalid' }; fixture_marker = $valid }
    }
    return [ordered]@{ valid = $false; reason = 'unknown-route-contract'; fixture_marker = $false }
}

function Invoke-ApiSample {
    param([string] $Name, [string] $Url)
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Method Get -Uri $Url -TimeoutSec $RequestTimeoutSeconds
        $watch.Stop()
        $bodyBytes = Get-ResponseBodyBytes $response
        $contract = Test-RouteResponseContract -Name $Name -Response $response -BodyBytes $bodyBytes
        return [pscustomobject][ordered]@{
            name = $Name
            ok = [bool] $contract.valid
            status = [int] $response.StatusCode
            elapsed_ms = [Math]::Round($watch.Elapsed.TotalMilliseconds, 3)
            response_bytes = [int64] $bodyBytes.Length
            body_sha256 = Get-BytesSha256 $bodyBytes
            contract_valid = [bool] $contract.valid
            contract_reason = $contract.reason
            fixture_marker_verified = [bool] $contract.fixture_marker
            request_id = Header-Value $response.Headers 'X-JM-Request-Id'
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
            try { $status = [int] $_.Exception.Response.StatusCode } catch {}
            try { $headers = $_.Exception.Response.Headers } catch {}
        }
        return [pscustomobject][ordered]@{
            name = $Name
            ok = $false
            status = $status
            elapsed_ms = [Math]::Round($watch.Elapsed.TotalMilliseconds, 3)
            response_bytes = 0
            body_sha256 = $null
            contract_valid = $false
            contract_reason = 'transport-error'
            fixture_marker_verified = $false
            request_id = Header-Value $headers 'X-JM-Request-Id'
            upstream_attempts = Header-Value $headers 'X-JM-Upstream-Attempts'
            upstream_ms = Header-Value $headers 'X-JM-Upstream-Ms'
            source_cache = Header-Value $headers 'X-JM-Source-Cache'
            image_cache = Header-Value $headers 'X-JM-Cache'
            prefetch = Header-Value $headers 'X-JM-Prefetch'
            error = $_.Exception.Message
        }
    }
}

function Get-Percentile {
    param([double[]] $Values, [double] $Percentile)
    if ($Values.Count -eq 0) { return $null }
    $sorted = @($Values | Sort-Object)
    $index = [Math]::Ceiling($Percentile * $sorted.Count) - 1
    $index = [Math]::Max(0, [Math]::Min($sorted.Count - 1, $index))
    return [Math]::Round([double] $sorted[$index], 3)
}

function Get-WarmSummary {
    param([object[]] $Samples, [string[]] $RouteNames)
    $summary = [ordered]@{}
    foreach ($route in $RouteNames) {
        $routeSamples = @($Samples | Where-Object { $_.name -eq $route })
        $successful = @($routeSamples | Where-Object { $_.ok -eq $true })
        $values = [double[]] @($successful | ForEach-Object { [double] $_.elapsed_ms })
        $summary[$route] = [ordered]@{
            samples = $routeSamples.Count
            successful = $successful.Count
            failed = $routeSamples.Count - $successful.Count
            median_ms = Get-Percentile $values 0.50
            p95_ms = if ($values.Count -ge 40) { Get-Percentile $values 0.95 } else { $null }
            p99_ms = if ($values.Count -ge 100) { Get-Percentile $values 0.99 } else { $null }
            max_ms = if ($values.Count -gt 0) { [Math]::Round([double] (($values | Measure-Object -Maximum).Maximum), 3) } else { $null }
        }
    }
    return $summary
}

function Reset-FixtureStats {
    param([string] $FixtureUrl)
    $reset = Invoke-RestMethod -Method Get -Uri "$FixtureUrl/__reset?run_id=default" -TimeoutSec 5
    Assert-True ($reset.ok -eq $true) 'Fixture reset failed.'
    $state = Invoke-RestMethod -Method Get -Uri "$FixtureUrl/__stats?run_id=default" -TimeoutSec 5
    Assert-True ($state.ok -eq $true) 'Fixture reset verification failed.'
    $remainingCount = if ($null -eq $state.counts) {
        0
    } elseif ($state.counts -is [System.Array]) {
        @($state.counts).Count
    } else {
        @($state.counts.PSObject.Properties).Count
    }
    Assert-True ($remainingCount -eq 0) "Fixture counts were not empty after reset: $($state.counts | ConvertTo-Json -Compress)"
    return $state
}

function Get-FixtureStats {
    param([string] $FixtureUrl)
    $state = Invoke-RestMethod -Method Get -Uri "$FixtureUrl/__stats?run_id=default" -TimeoutSec 5
    Assert-True ($state.ok -eq $true) 'Fixture stats request failed.'
    return $state
}

function Get-FixtureRequestCount {
    param($Counts)
    if ($null -eq $Counts) { return [int64] 0 }
    if ($Counts -is [System.Array]) {
        Assert-True (@($Counts).Count -eq 0) 'Fixture returned a non-empty positional count array.'
        return [int64] 0
    }
    [int64] $total = 0
    foreach ($property in @($Counts.PSObject.Properties)) { $total += [int64] $property.Value }
    return $total
}

function Get-FixtureCoverage {
    param($Counts, [string[]] $AllowedHosts, [bool] $RequireConfigProxy)
    [int64] $config = 0
    [int64] $latest = 0
    [int64] $album = 0
    [int64] $chapterData = 0
    [int64] $media = 0
    $unexpectedHosts = New-Object 'System.Collections.Generic.List[string]'
    if ($null -ne $Counts -and $Counts -isnot [System.Array]) {
        foreach ($property in @($Counts.PSObject.Properties)) {
            $parts = $property.Name -split '\|'
            if ($parts.Count -lt 4) { continue }
            $fixtureHost = [string] $parts[0]
            $path = [string] $parts[1]
            $count = [int64] $property.Value
            if ($AllowedHosts -cnotcontains $fixtureHost -and -not $unexpectedHosts.Contains($fixtureHost)) { $unexpectedHosts.Add($fixtureHost) | Out-Null }
            if ($path -eq '/newsvr-2025.txt') { $config += $count }
            if ($path -eq '/latest') { $latest += $count }
            if ($path -eq '/album') { $album += $count }
            if ($path -in @('/chapter', '/comic_read', '/chapter_view_template')) { $chapterData += $count }
            if ($path.StartsWith('/media/photos/', [System.StringComparison]::Ordinal)) { $media += $count }
        }
    }
    $configIsolated = if ($RequireConfigProxy) { $config -gt 0 } else { $config -eq 0 }
    $complete = $unexpectedHosts.Count -eq 0 -and $configIsolated -and $latest -gt 0 -and
        $album -gt 0 -and $chapterData -gt 0 -and $media -gt 0
    return [ordered]@{
        complete = $complete
        config_requests = $config
        config_policy = if ($RequireConfigProxy) { 'curl-must-reach-loopback-fixture' } else { 'url-fopen-disabled-fail-closed-fallback' }
        config_isolated = $configIsolated
        latest_requests = $latest
        album_requests = $album
        chapter_or_scramble_requests = $chapterData
        media_requests = $media
        unexpected_hosts = @($unexpectedHosts)
        proof = 'Fixture-specific JSON markers, fail-closed URL streams, and API/chapter/CDN loopback counters cover every measured upstream route family.'
    }
}

function Invoke-ConcurrencyProbe {
    param([string] $Url, [int] $ClientCount)
    Add-Type -AssemblyName System.Net.Http
    $handler = New-Object System.Net.Http.HttpClientHandler
    $handler.UseProxy = $false
    if ($handler.PSObject.Properties['MaxConnectionsPerServer']) { $handler.MaxConnectionsPerServer = $ClientCount }
    $client = [System.Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromSeconds($RequestTimeoutSeconds)
    $tasks = @()
    $responses = @()
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        for ($index = 1; $index -le $ClientCount; $index++) {
            $separator = if ($Url.Contains('?')) { '&' } else { '?' }
            $tasks += $client.GetAsync($Url + $separator + 'queue_probe=' + $index)
        }
        foreach ($task in $tasks) {
            try {
                $awaiter = $task.GetAwaiter()
                $responses += $awaiter.GetResult()
            } catch {
                $responses += $null
            }
        }
        $watch.Stop()
        $statuses = @()
        foreach ($response in $responses) {
            if ($null -eq $response) {
                $statuses += 0
            } else {
                $statuses += [int] $response.StatusCode
            }
        }
        return [ordered]@{
            clients = $ClientCount
            successful = @($statuses | Where-Object { $_ -eq 200 }).Count
            failed = @($statuses | Where-Object { $_ -ne 200 }).Count
            wall_ms = [Math]::Round($watch.Elapsed.TotalMilliseconds, 3)
            statuses = $statuses
            scope = 'Single Windows PHP built-in-server worker queue throughput; not a multi-worker singleflight claim.'
        }
    } finally {
        foreach ($response in $responses) { if ($null -ne $response) { $response.Dispose() } }
        $client.Dispose()
        $handler.Dispose()
    }
}

function Start-ApiProcess {
    param(
        [string] $SourcePath,
        [int] $Port,
        [string[]] $PhpArguments,
        [string] $OutLog,
        [string] $ErrLog
    )
    $sourceDirectory = Split-Path -Parent $SourcePath
    $process = Start-Process -FilePath $PhpPath `
        -ArgumentList ($PhpArguments + @('-S', "127.0.0.1:$Port", $SourcePath)) `
        -WorkingDirectory $sourceDirectory -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $OutLog -RedirectStandardError $ErrLog
    $script:childPids.Add([int] $process.Id) | Out-Null
    return $process
}

function Measure-Version {
    param(
        [string] $Label,
        [int] $Order,
        [string] $SourcePath,
        [string] $BaseUrl,
        [string] $FixtureUrl,
        [string[]] $PhpArguments,
        [string] $ConfiguredConditionsSha256,
        $ProxyProcess,
        $FixtureProcess,
        [string] $ProxyInstanceNonce,
        [string] $FixtureInstanceNonce,
        $RuntimeInputPaths,
        [string[]] $AllowedHosts,
        [string] $TempTree
    )
    $sourceHashBefore = Get-Sha256 $SourcePath
    $runtimeInputHashesBefore = Get-PathHashMap $RuntimeInputPaths
    $proxyIdentityBefore = Get-ProcessIdentity $ProxyProcess
    $fixtureIdentityBefore = Get-ProcessIdentity $FixtureProcess
    Assert-True (-not $proxyIdentityBefore.has_exited) "$Label observed the original proxy process already exited."
    Assert-True (-not $fixtureIdentityBefore.has_exited) "$Label observed the original fixture process already exited."
    $fixtureReportedBefore = Reset-FixtureStats $FixtureUrl
    Assert-True ([int] $fixtureReportedBefore.pid -eq [int] $fixtureIdentityBefore.pid) "$Label fixture live PID did not match the original process."
    Assert-True ([string] $fixtureReportedBefore.instance_nonce -ceq $FixtureInstanceNonce) "$Label fixture live nonce did not match the original instance."
    $phaseConditionsBefore = [ordered]@{
        configured_conditions_sha256 = $ConfiguredConditionsSha256
        proxy_identity = $proxyIdentityBefore
        proxy_instance_nonce = $ProxyInstanceNonce
        fixture_identity = $fixtureIdentityBefore
        fixture_reported_pid = [int] $fixtureReportedBefore.pid
        fixture_instance_nonce = [string] $fixtureReportedBefore.instance_nonce
        runtime_input_sha256 = $runtimeInputHashesBefore
    }
    $phaseConditionsSha256Before = Get-ObjectSha256 $phaseConditionsBefore
    $apiOut = Join-Path $TempTree "$Label-api.out.log"
    $apiErr = Join-Path $TempTree "$Label-api.err.log"
    $apiProcess = Start-ApiProcess -SourcePath $SourcePath -Port ([uri] $BaseUrl).Port `
        -PhpArguments $PhpArguments -OutLog $apiOut -ErrLog $apiErr
    $apiPid = [int] $apiProcess.Id
    $health = $null
    $coldSamples = @()
    $warmSamples = @()
    $concurrencyProbe = $null
    try {
        $health = Wait-Api -BaseUrl $BaseUrl -Process $apiProcess -ErrorLog $apiErr
        Assert-True (-not $apiProcess.HasExited) "$Label API exited after readiness."
        Assert-True (-not $ProxyProcess.HasExited) "$Label observed the original proxy process stop after API readiness."
        Assert-True (-not $FixtureProcess.HasExited) "$Label observed the original fixture process stop after API readiness."

        $routes = [ordered]@{
            health = "$BaseUrl/?health=1"
            latest_page_1 = "$BaseUrl/?list=latest&page=1&format=min"
            latest_page_4 = "$BaseUrl/?list=latest&page=4&format=min"
            album = "$BaseUrl/?jmid=$AlbumId&format=min"
            image_no_prefetch = "$BaseUrl/?jmid=$AlbumId&chapter=$ChapterId&page=1&prefetch=0"
        }
        foreach ($entry in $routes.GetEnumerator()) {
            $coldSamples += Invoke-ApiSample -Name $entry.Key -Url $entry.Value
        }
        for ($iteration = 0; $iteration -lt $WarmupIterations; $iteration++) {
            foreach ($entry in $routes.GetEnumerator()) { Invoke-ApiSample -Name $entry.Key -Url $entry.Value | Out-Null }
        }
        for ($iteration = 0; $iteration -lt $Iterations; $iteration++) {
            foreach ($entry in $routes.GetEnumerator()) {
                $warmSamples += Invoke-ApiSample -Name $entry.Key -Url $entry.Value
            }
        }
        $concurrencyProbe = Invoke-ConcurrencyProbe `
            -Url "$BaseUrl/?jmid=$AlbumId&chapter=$ChapterId&page=2&prefetch=0" `
            -ClientCount $Concurrency
    } finally {
        Stop-ManagedProcess $apiProcess
    }

    $sourceHashAfter = Get-Sha256 $SourcePath
    $fixtureState = Get-FixtureStats $FixtureUrl
    $proxyIdentityAfter = Get-ProcessIdentity $ProxyProcess
    $fixtureIdentityAfter = Get-ProcessIdentity $FixtureProcess
    $runtimeInputHashesAfter = Get-PathHashMap $RuntimeInputPaths
    Assert-True (-not $proxyIdentityAfter.has_exited) "$Label proxy exited during its measurement phase."
    Assert-True (-not $fixtureIdentityAfter.has_exited) "$Label fixture exited during its measurement phase."
    Assert-True ([int] $fixtureState.pid -eq [int] $fixtureIdentityAfter.pid) "$Label final fixture PID did not match the original process."
    Assert-True ([string] $fixtureState.instance_nonce -ceq $FixtureInstanceNonce) "$Label final fixture nonce did not match the original instance."
    $phaseConditionsAfter = [ordered]@{
        configured_conditions_sha256 = $ConfiguredConditionsSha256
        proxy_identity = $proxyIdentityAfter
        proxy_instance_nonce = $ProxyInstanceNonce
        fixture_identity = $fixtureIdentityAfter
        fixture_reported_pid = [int] $fixtureState.pid
        fixture_instance_nonce = [string] $fixtureState.instance_nonce
        runtime_input_sha256 = $runtimeInputHashesAfter
    }
    $phaseConditionsSha256After = Get-ObjectSha256 $phaseConditionsAfter
    $phaseIdentityVerified = $phaseConditionsSha256Before -ceq $phaseConditionsSha256After
    $routeNames = @('health', 'latest_page_1', 'latest_page_4', 'album', 'image_no_prefetch')
    $policy = [ordered]@{
        prefetch = $health.diagnostics.prefetch
        cache = $health.diagnostics.cache_policy
    }
    return [ordered]@{
        label = $Label
        execution_order = $Order
        api_pid = $apiPid
        proxy_pid = [int] $proxyIdentityBefore.pid
        fixture_pid = [int] $fixtureIdentityBefore.pid
        external_conditions_sha256 = $phaseConditionsSha256Before
        external_conditions_sha256_after = $phaseConditionsSha256After
        instance_identity_verified = $phaseIdentityVerified
        proxy_identity_before = $proxyIdentityBefore
        proxy_identity_after = $proxyIdentityAfter
        proxy_instance_nonce = $ProxyInstanceNonce
        fixture_identity_before = $fixtureIdentityBefore
        fixture_identity_after = $fixtureIdentityAfter
        fixture_instance_nonce = $FixtureInstanceNonce
        runtime_input_sha256_before = $runtimeInputHashesBefore
        runtime_input_sha256_after = $runtimeInputHashesAfter
        api_version = $health.version
        php_version = $health.diagnostics.php
        apcu_enabled = [bool] $health.diagnostics.apcu
        apcu_total_memory_bytes = $health.diagnostics.apcu_details.total_memory_bytes
        runtime_policy = $policy
        runtime_policy_sha256 = Get-ObjectSha256 $policy
        source_sha256_before = $sourceHashBefore
        source_sha256_after = $sourceHashAfter
        cold_samples = $coldSamples
        warm_samples = $warmSamples
        warm_summary = Get-WarmSummary -Samples $warmSamples -RouteNames $routeNames
        concurrency_probe = $concurrencyProbe
        fixture_counts = $fixtureState.counts
        fixture_request_count = Get-FixtureRequestCount $fixtureState.counts
        fixture_coverage = Get-FixtureCoverage -Counts $fixtureState.counts -AllowedHosts $AllowedHosts -RequireConfigProxy ($Label -eq 'after')
    }
}

function Test-CertificateAbsentFromRoots {
    param([string] $Thumbprint)
    if ([string]::IsNullOrWhiteSpace($Thumbprint)) { return $false }
    return -not (Test-Path -LiteralPath "Cert:\CurrentUser\Root\$Thumbprint") -and
        -not (Test-Path -LiteralPath "Cert:\LocalMachine\Root\$Thumbprint")
}

function Get-ImprovementPercent {
    param($Before, $After, [bool] $Allowed)
    if (-not $Allowed -or $null -eq $Before -or $null -eq $After -or [double] $Before -le 0) { return $null }
    return [Math]::Round((([double] $Before - [double] $After) / [double] $Before) * 100.0, 3)
}

function Build-Comparison {
    param(
        $Before,
        $After,
        [string[]] $RouteNames,
        [bool] $CleanupComplete,
        [bool] $ToolsUnchanged,
        [bool] $HistoricalSnapshotUnchanged
    )
    $reasons = New-Object 'System.Collections.Generic.List[string]'
    if ($null -eq $Before -or $null -eq $After) { $reasons.Add('missing-version-result') | Out-Null }
    if ($null -ne $Before -and $null -ne $After) {
        if ($Before.external_conditions_sha256 -cne $After.external_conditions_sha256) { $reasons.Add('external-conditions-differ') | Out-Null }
        if ($Before.external_conditions_sha256 -cne $Before.external_conditions_sha256_after -or
            $After.external_conditions_sha256 -cne $After.external_conditions_sha256_after
        ) { $reasons.Add('phase-environment-changed') | Out-Null }
        if (-not $Before.instance_identity_verified -or -not $After.instance_identity_verified) { $reasons.Add('live-instance-identity-unverified') | Out-Null }
        if ($Before.proxy_pid -ne $After.proxy_pid) { $reasons.Add('proxy-instance-differed') | Out-Null }
        if ($Before.fixture_pid -ne $After.fixture_pid) { $reasons.Add('fixture-instance-differed') | Out-Null }
        if ([string] $Before.proxy_instance_nonce -cne [string] $After.proxy_instance_nonce) { $reasons.Add('proxy-nonce-differed') | Out-Null }
        if ([string] $Before.fixture_instance_nonce -cne [string] $After.fixture_instance_nonce) { $reasons.Add('fixture-nonce-differed') | Out-Null }
        if ($Before.source_sha256_before -cne $Before.source_sha256_after) { $reasons.Add('before-source-changed') | Out-Null }
        if ($After.source_sha256_before -cne $After.source_sha256_after) { $reasons.Add('after-source-changed') | Out-Null }
        if ([string] $Before.php_version -cne [string] $After.php_version) { $reasons.Add('php-version-differed') | Out-Null }
        if ([string] $Before.apcu_total_memory_bytes -cne [string] $After.apcu_total_memory_bytes) { $reasons.Add('apcu-capacity-differed') | Out-Null }
        if (-not $Before.apcu_enabled -or -not $After.apcu_enabled) { $reasons.Add('apcu-not-enabled') | Out-Null }
        if (-not $Before.fixture_coverage.complete -or -not $After.fixture_coverage.complete) { $reasons.Add('fixed-loopback-coverage-incomplete') | Out-Null }
        if ($Before.concurrency_probe.successful -ne $Before.concurrency_probe.clients -or
            $After.concurrency_probe.successful -ne $After.concurrency_probe.clients
        ) { $reasons.Add('concurrency-probe-failed') | Out-Null }
        foreach ($route in $RouteNames) {
            foreach ($result in @($Before, $After)) {
                $summary = $result.warm_summary[$route]
                if ($null -eq $summary -or $summary.samples -ne $Iterations -or
                    $summary.successful -ne $Iterations -or $summary.failed -ne 0
                ) {
                    $reasons.Add("incomplete-route:$($result.label):$route") | Out-Null
                }
            }
        }
        if ($Before.fixture_request_count -le 0 -or $After.fixture_request_count -le 0) { $reasons.Add('fixture-not-reached') | Out-Null }
    }
    if (-not $CleanupComplete) { $reasons.Add('cleanup-incomplete') | Out-Null }
    if (-not $ToolsUnchanged) { $reasons.Add('harness-files-changed') | Out-Null }
    if (-not $HistoricalSnapshotUnchanged) { $reasons.Add('historical-snapshot-changed') | Out-Null }
    $comparable = $reasons.Count -eq 0
    $routes = [ordered]@{}
    if ($null -ne $Before -and $null -ne $After) {
        foreach ($route in $RouteNames) {
            $beforeSummary = $Before.warm_summary[$route]
            $afterSummary = $After.warm_summary[$route]
            $routes[$route] = [ordered]@{
                before_successful = $beforeSummary.successful
                after_successful = $afterSummary.successful
                before_median_ms = $beforeSummary.median_ms
                after_median_ms = $afterSummary.median_ms
                median_delta_ms = if ($null -ne $beforeSummary.median_ms -and $null -ne $afterSummary.median_ms) { [Math]::Round([double] $afterSummary.median_ms - [double] $beforeSummary.median_ms, 3) } else { $null }
                median_improvement_percent = Get-ImprovementPercent $beforeSummary.median_ms $afterSummary.median_ms $comparable
                before_p95_ms = $beforeSummary.p95_ms
                after_p95_ms = $afterSummary.p95_ms
                p95_delta_ms = if ($null -ne $beforeSummary.p95_ms -and $null -ne $afterSummary.p95_ms) { [Math]::Round([double] $afterSummary.p95_ms - [double] $beforeSummary.p95_ms, 3) } else { $null }
                p95_improvement_percent = Get-ImprovementPercent $beforeSummary.p95_ms $afterSummary.p95_ms $comparable
                before_p99_ms = $beforeSummary.p99_ms
                after_p99_ms = $afterSummary.p99_ms
                p99_delta_ms = if ($null -ne $beforeSummary.p99_ms -and $null -ne $afterSummary.p99_ms) { [Math]::Round([double] $afterSummary.p99_ms - [double] $beforeSummary.p99_ms, 3) } else { $null }
                p99_improvement_percent = Get-ImprovementPercent $beforeSummary.p99_ms $afterSummary.p99_ms $comparable
            }
        }
    }
    return [ordered]@{
        mode = 'historical-common-denominator-v1'
        evidence_complete = $comparable
        comparable = $comparable
        reason = if ($comparable) { 'complete' } else { $reasons -join ';' }
        equality_scope = 'External harness/runtime conditions only. Source and runtime cache/prefetch policies are recorded intervention variables.'
        fixed_loopback_proven = $null -ne $Before -and $null -ne $After -and $Before.fixture_coverage.complete -and $After.fixture_coverage.complete
        routes = $routes
        fixture_requests = if ($null -ne $Before -and $null -ne $After) {
            [ordered]@{
                before = $Before.fixture_request_count
                after = $After.fixture_request_count
                delta = [int64] $After.fixture_request_count - [int64] $Before.fixture_request_count
                reduction_percent = Get-ImprovementPercent $Before.fixture_request_count $After.fixture_request_count $comparable
            }
        } else { $null }
        policy_fingerprints = if ($null -ne $Before -and $null -ne $After) {
            [ordered]@{ before = $Before.runtime_policy_sha256; after = $After.runtime_policy_sha256; equal = $Before.runtime_policy_sha256 -ceq $After.runtime_policy_sha256 }
        } else { $null }
    }
}

$PythonPath = Resolve-RequiredFile $PythonPath 'Python executable'
$PhpPath = Resolve-RequiredFile $PhpPath 'PHP executable'
$ApcuExtension = Resolve-RequiredFile $ApcuExtension 'APCu extension'
$proxyPath = Resolve-RequiredFile $proxyPath 'Transparent HTTPS proxy'
$fixturePath = Resolve-RequiredFile $fixturePath 'Upstream fixture'
$probePath = Resolve-RequiredFile $probePath 'HTTPS probe'
$scriptPath = Resolve-RequiredFile $scriptPath 'Performance orchestrator'
$beforeIndexPath = Resolve-RequiredFile $beforeIndexPath 'BEFORE index.php'
$beforeManifestPath = Resolve-RequiredFile $beforeManifestPath 'BEFORE manifest'
$AfterSourcePath = Resolve-RequiredFile $AfterSourcePath 'AFTER index.php'

Assert-True ($AlbumId -match '^\d{1,20}$') 'AlbumId must contain 1-20 digits.'
Assert-True ($ChapterId -match '^\d{1,20}$') 'ChapterId must contain 1-20 digits.'
$beforeManifest = Get-Content -LiteralPath $beforeManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
$authoritativeBeforeHash = [string] $beforeManifest.sha256.'index.php'
Assert-True ($authoritativeBeforeHash -match '^[0-9A-Fa-f]{64}$') 'BEFORE manifest index hash is invalid.'
$beforeSnapshotPaths = [ordered]@{}
$beforeSnapshotInitialHashes = [ordered]@{}
foreach ($property in @($beforeManifest.sha256.PSObject.Properties)) {
    Assert-True ([string] $property.Value -match '^[0-9A-Fa-f]{64}$') "BEFORE manifest hash is invalid for $($property.Name)."
    $snapshotPath = Resolve-RequiredFile (Join-Path $BeforeSourceDirectory $property.Name) "BEFORE $($property.Name)"
    $actualHash = Get-Sha256 $snapshotPath
    Assert-True ($actualHash -ceq ([string] $property.Value).ToUpperInvariant()) "BEFORE $($property.Name) does not match its authoritative manifest."
    $beforeSnapshotPaths[$property.Name] = $snapshotPath
    $beforeSnapshotInitialHashes[$property.Name] = $actualHash
}
$beforeManifestFileHashInitial = Get-Sha256 $beforeManifestPath
Assert-True ((Get-Sha256 $beforeIndexPath) -ceq $authoritativeBeforeHash.ToUpperInvariant()) 'BEFORE index.php does not match its authoritative manifest.'
Assert-True ((Get-Sha256 $beforeIndexPath) -cne (Get-Sha256 $AfterSourcePath)) 'BEFORE and AFTER source hashes must differ.'

if (-not [System.IO.Path]::IsPathRooted($OutputPath)) { $OutputPath = Join-Path (Get-Location).Path $OutputPath }
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDirectory = Split-Path -Parent $OutputPath
Assert-True (Test-Path -LiteralPath $outputDirectory -PathType Container) "Output directory not found: $outputDirectory"
Assert-True (-not (Test-Path -LiteralPath $OutputPath)) "OutputPath already exists; choose a new path or remove the stale report explicitly: $OutputPath"

$phpRoot = Split-Path -Parent $PhpPath
$extensionDirectory = Join-Path $phpRoot 'ext'
$extensionPaths = [ordered]@{}
foreach ($extensionName in @('php_curl.dll', 'php_openssl.dll', 'php_mbstring.dll', 'php_gd.dll')) {
    $extensionPaths[$extensionName] = Resolve-RequiredFile (Join-Path $extensionDirectory $extensionName) $extensionName
}

$initialHashes = [ordered]@{
    orchestrator = Get-Sha256 $scriptPath
    proxy = Get-Sha256 $proxyPath
    fixture = Get-Sha256 $fixturePath
    probe = Get-Sha256 $probePath
    php = Get-Sha256 $PhpPath
    python = Get-Sha256 $PythonPath
    apcu = Get-Sha256 $ApcuExtension
    php_curl = Get-Sha256 $extensionPaths['php_curl.dll']
    php_openssl = Get-Sha256 $extensionPaths['php_openssl.dll']
    php_mbstring = Get-Sha256 $extensionPaths['php_mbstring.dll']
    php_gd = Get-Sha256 $extensionPaths['php_gd.dll']
}
$pythonVersion = ((& $PythonPath --version) | Out-String).Trim()
$phpVersionLine = [string] ((& $PhpPath -n -v) | Select-Object -First 1)

$measurementId = [guid]::NewGuid().ToString('N')
$tempTree = Join-Path ([System.IO.Path]::GetTempPath()) "jm-transparent-https-performance-$measurementId"
$proxyWork = Join-Path $tempTree 'proxy'
$fixtureStatsDirectory = Join-Path $tempTree 'fixture-stats'
$runtimeDirectory = Join-Path $tempTree 'runtime-inputs'
$runtimeProxyPath = Join-Path $runtimeDirectory 'transparent_https_proxy.py'
$runtimeFixturePath = Join-Path $runtimeDirectory 'upstream-router.php'
$runtimeProbePath = Join-Path $runtimeDirectory 'transparent_https_probe.php'
$proxyStatePath = Join-Path $proxyWork 'state.json'
$fixtureOut = Join-Path $tempTree 'fixture.out.log'
$fixtureErr = Join-Path $tempTree 'fixture.err.log'
$proxyOut = Join-Path $tempTree 'proxy.out.log'
$proxyErr = Join-Path $tempTree 'proxy.err.log'
$ports = Get-DistinctLoopbackPorts 3
$fixturePort = [int] $ports[0]
$proxyPort = [int] $ports[1]
$apiPort = [int] $ports[2]
$fixtureUrl = "http://127.0.0.1:$fixturePort"
$proxyUrl = "http://127.0.0.1:$proxyPort"
$baseUrl = "http://127.0.0.1:$apiPort"
$routeNames = @('health', 'latest_page_1', 'latest_page_4', 'album', 'image_no_prefetch')

$environmentNames = @(
    'JM_FIXTURE_STATS_DIR', 'JM_FIXTURE_INSTANCE_NONCE', 'JM_TEST_MODE', 'JM_TEST_ALLOWED_HOSTS',
    'JM_TEST_API_BASE_URLS', 'JM_TEST_FALLBACK_API_BASE_URLS',
    'JM_TEST_CDN_BASE_URLS', 'JM_TEST_DOMAIN_SOURCE_URLS',
    'JM_TEST_PREFETCH_STATS_DIR', 'https_proxy', 'HTTPS_PROXY',
    'http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY',
    'no_proxy', 'NO_PROXY', 'CURL_CA_BUNDLE', 'SSL_CERT_FILE',
    'PHP_CLI_SERVER_WORKERS', 'JM_PROXY_TEST_URL', 'JM_PROXY_TEST_RUN_ID'
)
$savedEnvironment = @{}
foreach ($name in $environmentNames) { $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }

$script:childPids = New-Object 'System.Collections.Generic.List[int]'
$fixtureProcess = $null
$proxyProcess = $null
$fixturePidRecorded = $null
$proxyState = $null
$caThumbprint = $null
$externalConditions = $null
$externalConditionsSha256 = $null
$beforeResult = $null
$afterResult = $null
$runError = $null
$cleanupError = $null
$cleanup = [ordered]@{
    complete = $false
    processes_exited = $false
    ca_not_installed = $false
    environment_restored = $false
    temp_tree_removed = $false
    temp_tree_path = $tempTree
}

try {
    New-Item -ItemType Directory -Path $proxyWork, $fixtureStatsDirectory, $runtimeDirectory -Force | Out-Null
    Copy-Item -LiteralPath $proxyPath -Destination $runtimeProxyPath
    Copy-Item -LiteralPath $fixturePath -Destination $runtimeFixturePath
    Copy-Item -LiteralPath $probePath -Destination $runtimeProbePath
    foreach ($runtimePath in @($runtimeProxyPath, $runtimeFixturePath, $runtimeProbePath)) {
        (Get-Item -LiteralPath $runtimePath).IsReadOnly = $true
    }
    $runtimeInputPaths = [ordered]@{
        proxy = $runtimeProxyPath
        fixture = $runtimeFixturePath
        probe = $runtimeProbePath
    }
    $runtimeInputInitialHashes = Get-PathHashMap $runtimeInputPaths
    Assert-True ($runtimeInputInitialHashes.proxy -ceq $initialHashes.proxy) 'Runtime proxy copy hash mismatch.'
    Assert-True ($runtimeInputInitialHashes.fixture -ceq $initialHashes.fixture) 'Runtime fixture copy hash mismatch.'
    Assert-True ($runtimeInputInitialHashes.probe -ceq $initialHashes.probe) 'Runtime probe copy hash mismatch.'

    $env:JM_FIXTURE_STATS_DIR = $fixtureStatsDirectory
    $env:JM_FIXTURE_INSTANCE_NONCE = $measurementId
    $fixtureArguments = @(
        '-n',
        '-d', "extension_dir=$extensionDirectory",
        '-d', 'extension=php_openssl.dll',
        '-S', "127.0.0.1:$fixturePort", $runtimeFixturePath
    )
    $fixtureProcess = Start-Process -FilePath $PhpPath -ArgumentList $fixtureArguments `
        -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $fixtureOut -RedirectStandardError $fixtureErr
    $script:childPids.Add([int] $fixtureProcess.Id) | Out-Null
    $fixturePidRecorded = [int] $fixtureProcess.Id
    Wait-Fixture -FixtureUrl $fixtureUrl -Process $fixtureProcess -ErrorLog $fixtureErr

    $proxyProcess = Start-Process -FilePath $PythonPath `
        -ArgumentList @(
            $runtimeProxyPath,
            '--listen-host', '127.0.0.1', '--listen-port', [string] $proxyPort,
            '--upstream-host', '127.0.0.1', '--upstream-port', [string] $fixturePort,
            '--work-dir', $proxyWork, '--state-file', $proxyStatePath
        ) `
        -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $proxyOut -RedirectStandardError $proxyErr
    $script:childPids.Add([int] $proxyProcess.Id) | Out-Null
    $proxyState = Wait-JsonFile -Path $proxyStatePath -Process $proxyProcess -ErrorLog $proxyErr
    Assert-True ($proxyState.ready -eq $true) 'Proxy did not declare ready=true.'
    Assert-True ([int] $proxyState.pid -eq $proxyProcess.Id) 'Proxy state PID mismatch.'
    Assert-True ($proxyState.listen_host -eq '127.0.0.1' -and [int] $proxyState.listen_port -eq $proxyPort) 'Proxy listen state mismatch.'
    Assert-True ($proxyState.upstream_host -eq '127.0.0.1' -and [int] $proxyState.upstream_port -eq $fixturePort) 'Proxy upstream state mismatch.'
    Assert-True (@($proxyState.allowed_hosts).Count -eq 14) 'Proxy state whitelist cardinality mismatch.'
    Assert-True ([string] $proxyState.instance_nonce -match '^[0-9a-f]{32}$') 'Proxy state instance nonce is missing or invalid.'
    $caPath = Resolve-RequiredFile $proxyState.ca_cert_path 'Temporary CA'
    $caCertificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($caPath)
    try { $caThumbprint = $caCertificate.Thumbprint } finally { $caCertificate.Dispose() }
    Assert-True (Test-CertificateAbsentFromRoots $caThumbprint) 'Temporary CA was present in a system Root store before measurement.'

    $env:JM_TEST_MODE = '0'
    foreach ($name in @(
        'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_API_BASE_URLS', 'JM_TEST_FALLBACK_API_BASE_URLS',
        'JM_TEST_CDN_BASE_URLS', 'JM_TEST_DOMAIN_SOURCE_URLS', 'JM_TEST_PREFETCH_STATS_DIR'
    )) { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
    $env:https_proxy = $proxyUrl
    $env:HTTPS_PROXY = $proxyUrl
    $env:http_proxy = ''
    $env:HTTP_PROXY = ''
    $env:all_proxy = ''
    $env:ALL_PROXY = ''
    $env:no_proxy = '127.0.0.1,localhost'
    $env:NO_PROXY = '127.0.0.1,localhost'
    $env:CURL_CA_BUNDLE = $caPath
    $env:SSL_CERT_FILE = $caPath
    [Environment]::SetEnvironmentVariable('PHP_CLI_SERVER_WORKERS', $null, 'Process')

    $phpApiArguments = @(
        '-n',
        '-d', "extension_dir=$extensionDirectory",
        '-d', 'extension=php_curl.dll',
        '-d', 'extension=php_openssl.dll',
        '-d', 'extension=php_mbstring.dll',
        '-d', 'extension=php_gd.dll',
        '-d', "extension=$ApcuExtension",
        '-d', 'apc.enable_cli=1',
        '-d', 'apc.shm_size=128M',
        '-d', 'allow_url_fopen=0',
        '-d', "curl.cainfo=$caPath",
        '-d', "openssl.cafile=$caPath"
    )

    $env:JM_PROXY_TEST_URL = 'https://www.cdnhjk.net/latest?page=1'
    $env:JM_PROXY_TEST_RUN_ID = "warmup-$measurementId"
    try {
        $probeOutput = @(& $PhpPath @phpApiArguments '-f' $runtimeProbePath 2>&1)
        Assert-True ($LASTEXITCODE -eq 0 -and $probeOutput.Count -gt 0) "Proxy warm-up probe failed: $($probeOutput -join ' ')"
    } finally {
        [Environment]::SetEnvironmentVariable('JM_PROXY_TEST_URL', $savedEnvironment['JM_PROXY_TEST_URL'], 'Process')
        [Environment]::SetEnvironmentVariable('JM_PROXY_TEST_RUN_ID', $savedEnvironment['JM_PROXY_TEST_RUN_ID'], 'Process')
    }

    $externalConditions = [ordered]@{
        network_condition = 'fixed-loopback-transparent-https-fixture-v1'
        worker_model = 'windows-php-built-in-server-single-worker'
        listen_host = '127.0.0.1'
        api_port = $apiPort
        proxy_port = $proxyPort
        fixture_port = $fixturePort
        proxy_pid = [int] $proxyProcess.Id
        fixture_pid = [int] $fixtureProcess.Id
        proxy_start_time_utc = $proxyProcess.StartTime.ToUniversalTime().ToString('o')
        fixture_start_time_utc = $fixtureProcess.StartTime.ToUniversalTime().ToString('o')
        proxy_instance_nonce = [string] $proxyState.instance_nonce
        fixture_instance_nonce = $measurementId
        ca_cert_sha256 = ([string] $proxyState.ca_cert_sha256).ToUpperInvariant()
        allowed_hosts = @($proxyState.allowed_hosts)
        php_cli = $phpVersionLine.Trim()
        python = $pythonVersion
        powershell = $PSVersionTable.PSVersion.ToString()
        apcu_shm_size = '128M'
        allow_url_fopen = '0'
        php_ini_mode = '-n plus explicit identical extensions/settings'
        php_extensions = @('curl', 'openssl', 'mbstring', 'gd', 'apcu')
        warmup_iterations = $WarmupIterations
        iterations = $Iterations
        concurrency = $Concurrency
        request_timeout_seconds = $RequestTimeoutSeconds
        album_id = $AlbumId
        chapter_id = $ChapterId
        routes = $routeNames
        execution_order = @('before', 'after')
        file_sha256 = $initialHashes
        runtime_input_sha256 = $runtimeInputInitialHashes
    }
    $externalConditionsSha256 = Get-ObjectSha256 $externalConditions

    $beforeResult = Measure-Version `
        -Label 'before' -Order 1 -SourcePath $beforeIndexPath -BaseUrl $baseUrl `
        -FixtureUrl $fixtureUrl -PhpArguments $phpApiArguments `
        -ConfiguredConditionsSha256 $externalConditionsSha256 `
        -ProxyProcess $proxyProcess -FixtureProcess $fixtureProcess `
        -ProxyInstanceNonce ([string] $proxyState.instance_nonce) -FixtureInstanceNonce $measurementId `
        -RuntimeInputPaths $runtimeInputPaths -AllowedHosts ([string[]] @($proxyState.allowed_hosts)) `
        -TempTree $tempTree
    $afterResult = Measure-Version `
        -Label 'after' -Order 2 -SourcePath $AfterSourcePath -BaseUrl $baseUrl `
        -FixtureUrl $fixtureUrl -PhpArguments $phpApiArguments `
        -ConfiguredConditionsSha256 $externalConditionsSha256 `
        -ProxyProcess $proxyProcess -FixtureProcess $fixtureProcess `
        -ProxyInstanceNonce ([string] $proxyState.instance_nonce) -FixtureInstanceNonce $measurementId `
        -RuntimeInputPaths $runtimeInputPaths -AllowedHosts ([string[]] @($proxyState.allowed_hosts)) `
        -TempTree $tempTree
} catch {
    $runError = $_
} finally {
    try {
        Stop-ManagedProcess $proxyProcess
        Stop-ManagedProcess $fixtureProcess
        foreach ($id in @($script:childPids)) { Stop-ManagedProcessId $id }
        foreach ($name in $environmentNames) {
            [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
        }
        $cleanup.environment_restored = Test-EnvironmentMatchesSnapshot -Names $environmentNames -Snapshot $savedEnvironment
        $cleanup.processes_exited = @($script:childPids | Where-Object { $null -ne (Get-Process -Id $_ -ErrorAction SilentlyContinue) }).Count -eq 0
        $cleanup.ca_not_installed = Test-CertificateAbsentFromRoots $caThumbprint
        Remove-ControlledPerformanceTree $tempTree
        $cleanup.temp_tree_removed = -not (Test-Path -LiteralPath $tempTree)
        $cleanup.complete = $cleanup.processes_exited -and $cleanup.ca_not_installed -and $cleanup.environment_restored -and $cleanup.temp_tree_removed
    } catch {
        $cleanupError = $_
        try {
            foreach ($name in $environmentNames) {
                [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
            }
            $cleanup.environment_restored = Test-EnvironmentMatchesSnapshot -Names $environmentNames -Snapshot $savedEnvironment
        } catch {}
    }
}

$finalHashes = [ordered]@{
    orchestrator = Get-Sha256 $scriptPath
    proxy = Get-Sha256 $proxyPath
    fixture = Get-Sha256 $fixturePath
    probe = Get-Sha256 $probePath
    php = Get-Sha256 $PhpPath
    python = Get-Sha256 $PythonPath
    apcu = Get-Sha256 $ApcuExtension
    php_curl = Get-Sha256 $extensionPaths['php_curl.dll']
    php_openssl = Get-Sha256 $extensionPaths['php_openssl.dll']
    php_mbstring = Get-Sha256 $extensionPaths['php_mbstring.dll']
    php_gd = Get-Sha256 $extensionPaths['php_gd.dll']
}
$toolsUnchanged = (Get-ObjectSha256 $initialHashes) -ceq (Get-ObjectSha256 $finalHashes)
$beforeManifestFileHashFinal = Get-Sha256 $beforeManifestPath
$beforeSnapshotFinalHashes = [ordered]@{}
foreach ($name in @($beforeSnapshotPaths.Keys)) {
    $beforeSnapshotFinalHashes[$name] = Get-Sha256 ([string] $beforeSnapshotPaths[$name])
}
$historicalSnapshotUnchanged = $beforeManifestFileHashInitial -ceq $beforeManifestFileHashFinal -and
    (Get-ObjectSha256 $beforeSnapshotInitialHashes) -ceq (Get-ObjectSha256 $beforeSnapshotFinalHashes)
$comparison = Build-Comparison `
    -Before $beforeResult -After $afterResult -RouteNames $routeNames `
    -CleanupComplete ([bool] $cleanup.complete) -ToolsUnchanged $toolsUnchanged `
    -HistoricalSnapshotUnchanged $historicalSnapshotUnchanged

$sources = [ordered]@{
    before = [ordered]@{
        path = $beforeIndexPath
        authoritative_manifest_path = $beforeManifestPath
        authoritative_index_sha256 = $authoritativeBeforeHash.ToUpperInvariant()
        manifest_file_sha256_before = $beforeManifestFileHashInitial
        manifest_file_sha256_after = $beforeManifestFileHashFinal
        snapshot_sha256_before = $beforeSnapshotInitialHashes
        snapshot_sha256_after = $beforeSnapshotFinalHashes
        snapshot_unchanged = $historicalSnapshotUnchanged
        index_sha256_before = if ($null -ne $beforeResult) { $beforeResult.source_sha256_before } else { Get-Sha256 $beforeIndexPath }
        index_sha256_after = if ($null -ne $beforeResult) { $beforeResult.source_sha256_after } else { Get-Sha256 $beforeIndexPath }
    }
    after = [ordered]@{
        path = $AfterSourcePath
        index_sha256_before = if ($null -ne $afterResult) { $afterResult.source_sha256_before } else { Get-Sha256 $AfterSourcePath }
        index_sha256_after = if ($null -ne $afterResult) { $afterResult.source_sha256_after } else { Get-Sha256 $AfterSourcePath }
    }
}
$report = [ordered]@{
    schema_version = 'historical-common-denominator-v1'
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    status = if ($null -eq $runError -and $null -eq $cleanupError -and $comparison.comparable) { 'complete' } else { 'incomplete' }
    measurement_id = $measurementId
    parameters = [ordered]@{
        warmup_iterations = $WarmupIterations
        iterations = $Iterations
        concurrency = $Concurrency
        request_timeout_seconds = $RequestTimeoutSeconds
        album_id = $AlbumId
        chapter_id = $ChapterId
        routes = $routeNames
    }
    harness = [ordered]@{
        proxy_pid = if ($null -ne $proxyState) { [int] $proxyState.pid } else { $null }
        fixture_pid = $fixturePidRecorded
        proxy_instance_nonce = if ($null -ne $proxyState) { [string] $proxyState.instance_nonce } else { $null }
        fixture_instance_nonce = $measurementId
        same_proxy_instance = $null -ne $beforeResult -and $null -ne $afterResult -and
            $beforeResult.instance_identity_verified -and $afterResult.instance_identity_verified -and
            $beforeResult.proxy_pid -eq $afterResult.proxy_pid -and
            [string] $beforeResult.proxy_instance_nonce -ceq [string] $afterResult.proxy_instance_nonce
        same_fixture_instance = $null -ne $beforeResult -and $null -ne $afterResult -and
            $beforeResult.instance_identity_verified -and $afterResult.instance_identity_verified -and
            $beforeResult.fixture_pid -eq $afterResult.fixture_pid -and
            [string] $beforeResult.fixture_instance_nonce -ceq [string] $afterResult.fixture_instance_nonce
        ca_cert_sha256 = if ($null -ne $proxyState) { ([string] $proxyState.ca_cert_sha256).ToUpperInvariant() } else { $null }
        ca_store_thumbprint = $caThumbprint
        initial_file_sha256 = $initialHashes
        final_file_sha256 = $finalHashes
        files_unchanged = $toolsUnchanged
        runtime_input_sha256 = $runtimeInputInitialHashes
    }
    environment = [ordered]@{
        external_conditions = $externalConditions
        configured_conditions_sha256 = $externalConditionsSha256
        external_conditions_sha256 = if ($null -ne $beforeResult) { $beforeResult.external_conditions_sha256 } else { $null }
    }
    sources = $sources
    results = [ordered]@{ before = $beforeResult; after = $afterResult }
    comparison = $comparison
    cleanup = $cleanup
    scope = 'Deterministic loopback successful-path comparison. It does not claim real-internet absolute latency or production multi-worker behavior.'
}

$json = $report | ConvertTo-Json -Depth 30
Set-Content -LiteralPath $OutputPath -Value $json -Encoding UTF8

if ($null -ne $runError) { throw $runError }
if ($null -ne $cleanupError) { throw $cleanupError }
if (-not $comparison.comparable) {
    $coverageDiagnostic = [ordered]@{
        before = if ($null -ne $beforeResult) { $beforeResult.fixture_coverage } else { $null }
        after = if ($null -ne $afterResult) { $afterResult.fixture_coverage } else { $null }
    } | ConvertTo-Json -Depth 8 -Compress
    throw "Performance report is not comparable: $($comparison.reason). Coverage: $coverageDiagnostic. Report: $OutputPath"
}

Write-Output "Transparent HTTPS performance report written to $OutputPath"
