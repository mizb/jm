param(
    [string] $BaseUrl = 'http://127.0.0.1:8088',
    [string] $FixtureUrl = 'http://127.0.0.1:8091',
    [switch] $SkipComposeUp,
    [switch] $LocalListCache,
    [switch] $LocalMetadataCache,
    [switch] $LocalDomainRefresh,
    [switch] $LocalResources,
    [switch] $BarrierSelfTest,
    [switch] $FixtureResetSelfTest,
    [switch] $BootstrapDiagnosticsSelfTest,
    [string] $PhpPath = '',
    [string] $ApcuExtension = '',
    [ValidateRange(1, 65535)]
    [int] $LocalApiPort = 18088,
    [ValidateRange(1, 65535)]
    [int] $LocalFixturePort = 18090
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $root

function Assert-True { param([bool] $Condition, [string] $Message); if (-not $Condition) { throw $Message } }
function Header-Value { param($Headers, [string] $Name); return (@($Headers[$Name]) -join ', ') }

function Invoke-CapturedRequest {
    param([string] $Url, [string] $Method = 'GET', [hashtable] $Headers = @{})
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Method $Method -Uri $Url -Headers $Headers
        $watch.Stop()
        return [pscustomobject]@{ Status = [int] $response.StatusCode; Headers = $response.Headers; Body = $response.Content; ElapsedMs = $watch.ElapsedMilliseconds }
    } catch {
        $watch.Stop()
        $response = $_.Exception.Response
        if ($null -eq $response) { throw }
        $body = [string] $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($body)) {
            $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
            try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        }
        return [pscustomobject]@{ Status = [int] $response.StatusCode; Headers = $response.Headers; Body = $body; ElapsedMs = $watch.ElapsedMilliseconds }
    }
}

function New-RunId { return ([guid]::NewGuid().ToString('D')) }
function Reset-Fixture { param([string] $RunId); Invoke-WebRequest -UseBasicParsing -Uri "$FixtureUrl/__reset?run_id=$RunId" | Out-Null }
function Get-FixtureStats {
    param([string] $RunId)
    return Invoke-RestMethod -Method Get -Uri "$FixtureUrl/__stats?run_id=$RunId"
}
function Get-FixtureCounts {
    param([string] $RunId)
    return (Get-FixtureStats $RunId).counts
}
function Count-Key { param($Counts, [string] $Key); $property = $Counts.PSObject.Properties[$Key]; return $(if ($null -eq $property) { 0 } else { [int] $property.Value }) }
function Prefetch-Metric {
    param($Health, [string] $Name)
    $property = $Health.diagnostics.prefetch.aggregate.PSObject.Properties[$Name]
    return $(if ($null -eq $property) { 0 } else { [long] $property.Value })
}
function Api-Url { param([string] $Query, [string] $Scenario, [string] $RunId); return "$BaseUrl/?$Query&test_scenario=$Scenario&test_run_id=$RunId" }

function Count-FixtureEndpoint {
    param($Counts, [string] $Path, [string] $Page, [string] $Scenario)
    $pattern = '^[^|]+\|' + [regex]::Escape($Path) + '\|' + [regex]::Escape($Page) + '\|' + [regex]::Escape($Scenario) + '$'
    $total = 0
    foreach ($property in $Counts.PSObject.Properties) {
        if ($property.Name -match $pattern) { $total += [int] $property.Value }
    }
    return $total
}

function Assert-SourceCacheStatuses {
    param([array] $Responses, [string[]] $Expected, [string] $Label)
    $actual = @($Responses | ForEach-Object { Header-Value $_.Headers 'X-JM-Source-Cache' })
    Assert-True (($actual -join ',') -ceq ($Expected -join ',')) "$Label cache statuses expected $($Expected -join ','), got $($actual -join ',')"
}

function Wait-LocalEndpoint {
    param([string] $Url, [System.Diagnostics.Process] $Process)
    for ($attempt = 1; $attempt -le 50; $attempt++) {
        if ($Process.HasExited) { throw "Local PHP server exited before becoming ready: $Url" }
        try {
            Invoke-WebRequest -UseBasicParsing -Uri $Url | Out-Null
            return
        } catch {
            if ($attempt -eq 50) { throw "Local PHP server did not become ready: $Url" }
            Start-Sleep -Milliseconds 100
        }
    }
}

function Get-LocalTempRoot {
    if ([string]::IsNullOrWhiteSpace($env:TEMP)) {
        throw 'TEMP is unavailable for local list-cache verification.'
    }
    $separators = [char[]] @([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
    return [IO.Path]::GetFullPath($env:TEMP).TrimEnd($separators)
}

function Resolve-ControlledFixtureStatsDirectory {
    param([string] $Path, [string] $Token)
    if ($Token -cnotmatch '^[a-f0-9]{32}$') {
        throw 'Local fixture stats token is invalid.'
    }
    $separators = [char[]] @([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
    $fullPath = [IO.Path]::GetFullPath($Path).TrimEnd($separators)
    $parent = [IO.Path]::GetFullPath([IO.Path]::GetDirectoryName($fullPath)).TrimEnd($separators)
    $leaf = [IO.Path]::GetFileName($fullPath)
    $expectedLeaf = "jm-list-cache-stats-$Token"
    if (
        -not [StringComparer]::OrdinalIgnoreCase.Equals($parent, (Get-LocalTempRoot)) -or
        $leaf -cne $expectedLeaf -or
        $leaf -cnotmatch '^jm-list-cache-stats-[a-f0-9]{32}$'
    ) {
        throw "Refusing to remove uncontrolled fixture stats path: $fullPath"
    }
    return $fullPath
}

function Remove-ControlledLocalLogFiles {
    param([string[]] $Paths, [string] $Token)
    if ($Token -cnotmatch '^[a-f0-9]{32}$') {
        throw 'Local log token is invalid.'
    }
    $separators = [char[]] @([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
    $tempRoot = Get-LocalTempRoot
    $expectedNames = @(
        "jm-list-cache-fixture-$Token.out",
        "jm-list-cache-fixture-$Token.err",
        "jm-list-cache-api-$Token.out",
        "jm-list-cache-api-$Token.err"
    )
    foreach ($path in $Paths) {
        $fullPath = [IO.Path]::GetFullPath($path)
        $parent = [IO.Path]::GetFullPath([IO.Path]::GetDirectoryName($fullPath)).TrimEnd($separators)
        $leaf = [IO.Path]::GetFileName($fullPath)
        if (
            -not [StringComparer]::OrdinalIgnoreCase.Equals($parent, $tempRoot) -or
            $expectedNames -cnotcontains $leaf
        ) {
            throw "Refusing to remove uncontrolled local log path: $fullPath"
        }
        if (Test-Path -LiteralPath $fullPath -PathType Leaf) {
            Remove-Item -LiteralPath $fullPath -Force
        }
    }
}

function Resolve-ControlledConcurrentJobBarrierDirectory {
    param([string] $Path, [string] $Token)
    if ($Token -cnotmatch '^[a-f0-9]{32}$') {
        throw 'Concurrent job barrier token is invalid.'
    }
    $separators = [char[]] @([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
    $fullPath = [IO.Path]::GetFullPath($Path).TrimEnd($separators)
    $parent = [IO.Path]::GetFullPath([IO.Path]::GetDirectoryName($fullPath)).TrimEnd($separators)
    $leaf = [IO.Path]::GetFileName($fullPath)
    $expectedLeaf = "jm-concurrent-job-barrier-$Token"
    if (
        -not [StringComparer]::OrdinalIgnoreCase.Equals($parent, (Get-LocalTempRoot)) -or
        $leaf -cne $expectedLeaf -or
        $leaf -cnotmatch '^jm-concurrent-job-barrier-[a-f0-9]{32}$'
    ) {
        throw "Refusing uncontrolled concurrent job barrier path: $fullPath"
    }
    return $fullPath
}

function New-ControlledConcurrentJobBarrier {
    param(
        [ValidateRange(1, 100)] [int] $JobCount,
        [ValidateRange(1000, 300000)] [int] $ReadyTimeoutMs = 120000,
        [ValidateRange(1000, 600000)] [int] $ReleaseTimeoutMs = 180000
    )
    if ($ReleaseTimeoutMs -le $ReadyTimeoutMs) {
        throw 'Concurrent job barrier release timeout must exceed its ready timeout.'
    }
    $token = [guid]::NewGuid().ToString('N')
    $directory = Resolve-ControlledConcurrentJobBarrierDirectory `
        (Join-Path (Get-LocalTempRoot) "jm-concurrent-job-barrier-$token") `
        $token
    if (Test-Path -LiteralPath $directory) {
        throw "Concurrent job barrier path already exists: $directory"
    }
    New-Item -ItemType Directory -Path $directory -ErrorAction Stop | Out-Null
    $readyPaths = @(for ($index = 0; $index -lt $JobCount; $index++) {
        Join-Path $directory ('ready-{0:D2}.signal' -f $index)
    })
    return [pscustomobject]@{
        Token = $token
        Directory = $directory
        ReleasePath = Join-Path $directory 'release.signal'
        ReadyPaths = $readyPaths
        JobCount = $JobCount
        ReadyTimeoutMs = $ReadyTimeoutMs
        ReleaseTimeoutMs = $ReleaseTimeoutMs
    }
}

$script:ConcurrentRequestJob = {
    param(
        [string] $Url,
        [string] $ReadyPath,
        [string] $ReleasePath,
        [string] $BarrierToken,
        [int] $ReleaseTimeoutMs,
        [int] $RequestTimeoutSeconds
    )
    $ErrorActionPreference = 'Stop'
    [IO.File]::WriteAllText($ReadyPath, $BarrierToken, [Text.Encoding]::ASCII)
    $releaseWait = [Diagnostics.Stopwatch]::StartNew()
    while (-not [IO.File]::Exists($ReleasePath)) {
        if ($releaseWait.ElapsedMilliseconds -ge $ReleaseTimeoutMs) {
            throw "Timed out waiting for concurrent job barrier release: $ReleasePath"
        }
        Start-Sleep -Milliseconds 10
    }
    $releaseToken = [IO.File]::ReadAllText($ReleasePath, [Text.Encoding]::ASCII)
    if ($releaseToken -cne $BarrierToken) {
        throw 'Concurrent job barrier release token mismatch.'
    }
    if ([string]::IsNullOrWhiteSpace($Url)) {
        return [DateTime]::UtcNow.Ticks
    }
    try {
        return (Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec $RequestTimeoutSeconds).StatusCode
    } catch {
        return 0
    }
}

function Wait-ConcurrentJobBarrierReady {
    param(
        $Barrier,
        [object[]] $Jobs,
        [ValidateRange(1000, 300000)] [int] $ReadyTimeoutMs = 120000
    )
    if (@($Jobs).Count -ne [int] $Barrier.JobCount) {
        throw "Concurrent barrier expected $($Barrier.JobCount) jobs, got $(@($Jobs).Count)."
    }
    $readyWait = [Diagnostics.Stopwatch]::StartNew()
    while ($true) {
        $readyCount = 0
        foreach ($readyPath in $Barrier.ReadyPaths) {
            if ([IO.File]::Exists($readyPath)) {
                try {
                    if ([IO.File]::ReadAllText($readyPath, [Text.Encoding]::ASCII) -ceq $Barrier.Token) {
                        $readyCount++
                    }
                } catch {
                    # A just-created marker may still be opening; retry until the bounded deadline.
                }
            }
        }
        if ($readyCount -eq [int] $Barrier.JobCount) { return }

        $terminalJobs = @($Jobs | Where-Object { $_.State -in @('Completed', 'Failed', 'Stopped') })
        if ($terminalJobs.Count -gt 0) {
            $states = @($terminalJobs | ForEach-Object { "$($_.Id):$($_.State)" }) -join ', '
            throw "Concurrent jobs terminated before barrier release: $states"
        }
        if ($readyWait.ElapsedMilliseconds -ge $ReadyTimeoutMs) {
            $states = @($Jobs | ForEach-Object { "$($_.Id):$($_.State)" }) -join ', '
            throw "Timed out waiting for concurrent jobs to become ready ($readyCount/$($Barrier.JobCount)): $states"
        }
        Start-Sleep -Milliseconds 25
    }
}

function Release-ConcurrentJobBarrier {
    param($Barrier)
    if ([IO.File]::Exists($Barrier.ReleasePath)) {
        throw "Concurrent job barrier was already released: $($Barrier.ReleasePath)"
    }
    $temporaryPath = Join-Path $Barrier.Directory ("release-{0}.tmp" -f [guid]::NewGuid().ToString('N'))
    try {
        [IO.File]::WriteAllText($temporaryPath, $Barrier.Token, [Text.Encoding]::ASCII)
        [IO.File]::Move($temporaryPath, $Barrier.ReleasePath)
    } finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction Stop
        }
        if (Test-Path -LiteralPath $temporaryPath) {
            throw "Concurrent job barrier temporary release remained after publication: $temporaryPath"
        }
    }
}

function Stop-AndRemoveConcurrentJobs {
    param([object[]] $Jobs)
    $cleanupErrors = @()
    foreach ($job in @($Jobs)) {
        if ($null -eq $job) { continue }
        try {
            if ($job.State -notin @('Completed', 'Failed', 'Stopped')) {
                Stop-Job -Job $job -ErrorAction Stop | Out-Null
            }
        } catch {
            $cleanupErrors += "stop job $($job.Id): $($_.Exception.Message)"
        }
        try {
            Remove-Job -Job $job -Force -ErrorAction Stop | Out-Null
        } catch {
            $cleanupErrors += "remove job $($job.Id): $($_.Exception.Message)"
        }
    }
    if ($cleanupErrors.Count -gt 0) {
        throw 'Concurrent job cleanup failed: ' + ($cleanupErrors -join ' | ')
    }
}

function Remove-ControlledConcurrentJobBarrier {
    param($Barrier)
    $directory = Resolve-ControlledConcurrentJobBarrierDirectory $Barrier.Directory $Barrier.Token
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) { return }

    $allowedPaths = @($Barrier.ReadyPaths) + @($Barrier.ReleasePath)
    $allowedNames = @($allowedPaths | ForEach-Object { [IO.Path]::GetFileName($_) })
    $entries = @(Get-ChildItem -LiteralPath $directory -Force)
    foreach ($entry in $entries) {
        if ($entry.PSIsContainer -or $allowedNames -cnotcontains $entry.Name) {
            throw "Unexpected concurrent job barrier artifact: $($entry.FullName)"
        }
    }
    foreach ($path in $allowedPaths) {
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            Remove-Item -LiteralPath $path -Force -ErrorAction Stop
        }
        if (Test-Path -LiteralPath $path) {
            throw "Concurrent job barrier signal remained after cleanup: $path"
        }
    }
    $remaining = @(Get-ChildItem -LiteralPath $directory -Force)
    if ($remaining.Count -gt 0) {
        throw "Concurrent job barrier directory is not empty: $directory"
    }
    Remove-Item -LiteralPath $directory -Force -ErrorAction Stop
    if (Test-Path -LiteralPath $directory) {
        throw "Concurrent job barrier directory remained after cleanup: $directory"
    }
}

function Invoke-ConcurrentJobBarrierSelfTest {
    $barrier = $null
    $jobs = @()
    try {
        $barrier = New-ControlledConcurrentJobBarrier -JobCount 3 -ReadyTimeoutMs 30000 -ReleaseTimeoutMs 60000
        for ($jobIndex = 0; $jobIndex -lt 3; $jobIndex++) {
            $jobs += Start-Job -ScriptBlock $script:ConcurrentRequestJob -ArgumentList `
                '', $barrier.ReadyPaths[$jobIndex], $barrier.ReleasePath, $barrier.Token, $barrier.ReleaseTimeoutMs, 5
        }
        Wait-ConcurrentJobBarrierReady -Barrier $barrier -Jobs $jobs -ReadyTimeoutMs $barrier.ReadyTimeoutMs
        Assert-True (@($jobs | Where-Object { $_.State -ne 'Running' }).Count -eq 0) `
            'Concurrent barrier self-test jobs escaped before release.'
        $releaseTicks = [DateTime]::UtcNow.Ticks
        Release-ConcurrentJobBarrier -Barrier $barrier
        $observedTicks = @()
        foreach ($job in $jobs) {
            $jobTicks = @($job | Receive-Job -Wait -ErrorAction Stop)
            if ($jobTicks.Count -ne 1) {
                $jobErrors = @($job.ChildJobs | ForEach-Object { @($_.Error) } | ForEach-Object { $_.ToString() }) -join ' | '
                throw "Concurrent barrier job $($job.Id) returned $($jobTicks.Count) results; state=$($job.State); errors=$jobErrors"
            }
            $observedTicks += $jobTicks
        }
        Assert-True ($observedTicks.Count -eq 3) "Concurrent barrier self-test returned the wrong result count: $($observedTicks.Count)."
        Assert-True (@($observedTicks | Where-Object { [long] $_ -lt $releaseTicks }).Count -eq 0) `
            'Concurrent barrier self-test observed work before release.'
    } finally {
        $cleanupErrors = @()
        try { Stop-AndRemoveConcurrentJobs -Jobs $jobs } catch { $cleanupErrors += $_.Exception.Message }
        if ($null -ne $barrier) {
            try { Remove-ControlledConcurrentJobBarrier -Barrier $barrier } catch { $cleanupErrors += $_.Exception.Message }
        }
        if ($cleanupErrors.Count -gt 0) {
            throw 'Concurrent barrier self-test cleanup failed: ' + ($cleanupErrors -join ' | ')
        }
    }

    $barrier = $null
    $jobs = @()
    try {
        $barrier = New-ControlledConcurrentJobBarrier -JobCount 1 -ReadyTimeoutMs 30000 -ReleaseTimeoutMs 60000
        $jobs += Start-Job -ScriptBlock $script:ConcurrentRequestJob -ArgumentList `
            '', $barrier.ReadyPaths[0], $barrier.ReleasePath, $barrier.Token, $barrier.ReleaseTimeoutMs, 5
        Wait-ConcurrentJobBarrierReady -Barrier $barrier -Jobs $jobs -ReadyTimeoutMs $barrier.ReadyTimeoutMs
        [IO.File]::WriteAllText($barrier.ReleasePath, "foreign-$($barrier.Token)", [Text.Encoding]::ASCII)
        $terminalJob = Wait-Job -Job $jobs[0] -Timeout 10
        Assert-True ($null -ne $terminalJob) 'Concurrent barrier foreign-token self-test timed out.'
        Assert-True ($terminalJob.State -eq 'Failed') 'Concurrent barrier accepted a foreign release token.'

        $foreignTokenRejected = $false
        try {
            $null = $jobs | Receive-Job -Wait -ErrorAction Stop
        } catch {
            if ($_.Exception.Message -notmatch 'Concurrent job barrier release token mismatch') { throw }
            $foreignTokenRejected = $true
        }
        Assert-True $foreignTokenRejected 'Concurrent barrier self-test rejects a foreign release token.'
    } finally {
        $cleanupErrors = @()
        try { Stop-AndRemoveConcurrentJobs -Jobs $jobs } catch { $cleanupErrors += $_.Exception.Message }
        if ($null -ne $barrier) {
            try { Remove-ControlledConcurrentJobBarrier -Barrier $barrier } catch { $cleanupErrors += $_.Exception.Message }
        }
        if ($cleanupErrors.Count -gt 0) {
            throw 'Concurrent barrier foreign-token self-test cleanup failed: ' + ($cleanupErrors -join ' | ')
        }
    }

    Write-Output 'Concurrent job barrier self-test passed.'
}

function Invoke-FixtureResetFailureSelfTest {
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {
        throw 'Fixture reset failure self-test requires Windows file deletion semantics.'
    }
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw 'Fixture reset failure self-test requires -PhpPath pointing to php.exe.'
    }

    $logToken = [guid]::NewGuid().ToString('N')
    $statsDirectory = Resolve-ControlledFixtureStatsDirectory `
        (Join-Path (Get-LocalTempRoot) "jm-list-cache-stats-$logToken") `
        $logToken
    $fixtureOut = Join-Path (Get-LocalTempRoot) "jm-list-cache-fixture-$logToken.out"
    $fixtureErr = Join-Path (Get-LocalTempRoot) "jm-list-cache-fixture-$logToken.err"
    $logPaths = @($fixtureOut, $fixtureErr)
    $fixtureSelfTestUrl = "http://127.0.0.1:$LocalFixturePort"
    $savedStatsDirectory = [Environment]::GetEnvironmentVariable('JM_FIXTURE_STATS_DIR', 'Process')
    $fixtureProcess = $null
    $statsLock = $null
    $completed = $false
    try {
        if (Test-Path -LiteralPath $statsDirectory) {
            throw "Fixture reset self-test stats path already exists: $statsDirectory"
        }
        New-Item -ItemType Directory -Path $statsDirectory -ErrorAction Stop | Out-Null
        [Environment]::SetEnvironmentVariable('JM_FIXTURE_STATS_DIR', $statsDirectory, 'Process')
        $fixtureStart = @{
            FilePath = $PhpPath
            ArgumentList = @('-n', '-S', "127.0.0.1:$LocalFixturePort", 'tests\fixtures\upstream-router.php')
            WorkingDirectory = $root
            WindowStyle = 'Hidden'
            PassThru = $true
            RedirectStandardOutput = $fixtureOut
            RedirectStandardError = $fixtureErr
        }
        $fixtureProcess = Start-Process @fixtureStart
        Wait-LocalEndpoint "$fixtureSelfTestUrl/__stats?run_id=ready-check" $fixtureProcess

        $runId = New-RunId
        $statsPath = Join-Path $statsDirectory "$runId.json"
        [IO.File]::WriteAllText($statsPath, '{"fixture-reset-self-test":1}', [Text.Encoding]::UTF8)
        $statsLock = [IO.File]::Open($statsPath, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
        try {
            $lockedReset = Invoke-CapturedRequest "$fixtureSelfTestUrl/__reset?run_id=$runId"
            Assert-True ($lockedReset.Status -eq 500) 'fixture reset under an exclusive Windows file lock must return HTTP 500'
            $lockedResetJson = $lockedReset.Body | ConvertFrom-Json
            Assert-True ($lockedResetJson.ok -eq $false) 'fixture reset unlink failure must return JSON ok=false'
            Assert-True (([string] $lockedResetJson.error) -eq 'unable to reset fixture stats') 'fixture reset unlink failure returned the wrong JSON error'
            Assert-True (Test-Path -LiteralPath $statsPath -PathType Leaf) 'fixture reset unlink failure must retain the locked stats file'
        } finally {
            $statsLock.Dispose()
            $statsLock = $null
        }

        $releasedReset = Invoke-CapturedRequest "$fixtureSelfTestUrl/__reset?run_id=$runId"
        $releasedResetJson = $releasedReset.Body | ConvertFrom-Json
        Assert-True ($releasedReset.Status -eq 200 -and $releasedResetJson.ok -eq $true) 'fixture reset must succeed after releasing the file lock'
        Assert-True (-not (Test-Path -LiteralPath $statsPath)) 'fixture reset left stats behind after releasing the file lock'

        $completed = $true
        Write-Output ('Fixture reset failure self-test passed. locked_http={0} locked_ok={1} released_http={2} released_ok={3}' -f `
            $lockedReset.Status, `
            ([string] $lockedResetJson.ok).ToLowerInvariant(), `
            $releasedReset.Status, `
            ([string] $releasedResetJson.ok).ToLowerInvariant())
    } finally {
        $cleanupErrors = [Collections.Generic.List[string]]::new()
        if ($null -ne $statsLock) {
            try { $statsLock.Dispose() } catch { $cleanupErrors.Add("Failed to release fixture reset stats lock: $($_.Exception.Message)") }
        }
        if ($null -ne $fixtureProcess) {
            try {
                if (-not $fixtureProcess.HasExited) {
                    Stop-Process -Id $fixtureProcess.Id -Force -ErrorAction Stop
                }
                if (-not $fixtureProcess.WaitForExit(5000)) {
                    throw "Fixture reset PHP process did not exit: $($fixtureProcess.Id)"
                }
            } catch {
                $cleanupErrors.Add("Failed to stop fixture reset PHP process: $($_.Exception.Message)")
            }
        }
        if (-not $completed) {
            try {
                if (Test-Path -LiteralPath $fixtureErr -PathType Leaf) {
                    Get-Content -LiteralPath $fixtureErr -Tail 30
                }
            } catch {
                $cleanupErrors.Add("Failed to read fixture reset PHP error log: $($_.Exception.Message)")
            }
        }
        try {
            [Environment]::SetEnvironmentVariable('JM_FIXTURE_STATS_DIR', $savedStatsDirectory, 'Process')
        } catch {
            $cleanupErrors.Add("Failed to restore JM_FIXTURE_STATS_DIR: $($_.Exception.Message)")
        }
        try {
            Remove-ControlledLocalLogFiles $logPaths $logToken
        } catch {
            $cleanupErrors.Add("Failed to remove fixture reset PHP logs: $($_.Exception.Message)")
        }
        try {
            $controlledStatsDirectory = Resolve-ControlledFixtureStatsDirectory $statsDirectory $logToken
            if (Test-Path -LiteralPath $controlledStatsDirectory -PathType Container) {
                Remove-Item -LiteralPath $controlledStatsDirectory -Recurse -Force
            } elseif (Test-Path -LiteralPath $controlledStatsDirectory) {
                throw "Fixture reset stats path is not a directory: $controlledStatsDirectory"
            }
            if (Test-Path -LiteralPath $controlledStatsDirectory) {
                throw "Fixture reset stats path remained after cleanup: $controlledStatsDirectory"
            }
        } catch {
            $cleanupErrors.Add("Failed to remove fixture reset stats: $($_.Exception.Message)")
        }
        if ($cleanupErrors.Count -gt 0) {
            throw 'Fixture reset self-test cleanup failed: ' + ($cleanupErrors -join ' | ')
        }
    }
}

if ($BarrierSelfTest) {
    Invoke-ConcurrentJobBarrierSelfTest
    return
}
if ($FixtureResetSelfTest) {
    Invoke-FixtureResetFailureSelfTest
    return
}

function Invoke-BootstrapDiagnosticsSelfTest {
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw 'Bootstrap diagnostics self-test requires -PhpPath pointing to php.exe.'
    }
    $script:BaseUrl = "http://127.0.0.1:$LocalApiPort"
    $token = [guid]::NewGuid().ToString('N')
    $outLog = Join-Path $env:TEMP "jm-list-cache-api-$token.out"
    $errLog = Join-Path $env:TEMP "jm-list-cache-api-$token.err"
    $process = $null
    $completed = $false
    try {
        $process = Start-Process -FilePath $PhpPath `
            -ArgumentList @('-n', '-S', "127.0.0.1:$LocalApiPort", 'index.php') `
            -WorkingDirectory $root -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput $outLog -RedirectStandardError $errLog
        $response = $null
        for ($attempt = 1; $attempt -le 50; $attempt++) {
            if ($process.HasExited) { throw 'Bootstrap diagnostics PHP process exited before responding.' }
            try {
                $response = Invoke-CapturedRequest "$BaseUrl/"
                if ($response.Status -eq 500) { break }
            } catch {}
            Start-Sleep -Milliseconds 100
        }
        Assert-True ($null -ne $response -and $response.Status -eq 500) 'Missing-extension bootstrap did not return HTTP 500.'
        Assert-True ((Header-Value $response.Headers 'X-JM-Request-Id') -match '^[0-9a-f]{16}$') 'Bootstrap 500 omitted a safe request id.'
        Assert-True ((Header-Value $response.Headers 'X-JM-Upstream-Attempts') -eq '0') 'Bootstrap 500 attempts header was not zero.'
        Assert-True ((Header-Value $response.Headers 'X-JM-Upstream-Ms') -eq '0') 'Bootstrap 500 upstream time header was not zero.'
        Assert-True ((Header-Value $response.Headers 'X-JM-Deadline-Exhausted') -eq '0') 'Bootstrap 500 deadline header was not zero.'
        $payload = $response.Body | ConvertFrom-Json
        $expectedBootstrapError = -join @([char]0x670D, [char]0x52A1, [char]0x5668, [char]0x5185, [char]0x90E8, [char]0x9519, [char]0x8BEF)
        Assert-True ($payload.code -eq 500 -and $payload.success -eq $false -and $payload.error -eq $expectedBootstrapError) 'Bootstrap 500 leaked its missing-extension implementation detail.'
        $completed = $true
        Write-Output 'Bootstrap missing-extension diagnostics self-test passed.'
    } finally {
        if ($null -ne $process) {
            if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
            try { $process.WaitForExit(5000) | Out-Null } catch {}
            $process.Dispose()
        }
        if (-not $completed -and (Test-Path -LiteralPath $errLog)) { Get-Content -LiteralPath $errLog -Tail 30 }
        Remove-ControlledLocalLogFiles @($outLog, $errLog) $token
    }
}

if ($BootstrapDiagnosticsSelfTest) {
    Invoke-BootstrapDiagnosticsSelfTest
    return
}
function Invoke-LocalListCacheVerification {
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw 'Local list-cache verification requires -PhpPath pointing to php.exe.'
    }
    if (-not (Test-Path -LiteralPath $ApcuExtension -PathType Leaf)) {
        throw 'Local list-cache verification requires -ApcuExtension pointing to php_apcu.dll.'
    }
    if ($LocalApiPort -eq $LocalFixturePort) {
        throw 'Local API and fixture ports must differ.'
    }

    $script:BaseUrl = "http://127.0.0.1:$LocalApiPort"
    $script:FixtureUrl = "http://127.0.0.1:$LocalFixturePort"
    $phpRoot = Split-Path -Parent (Resolve-Path -LiteralPath $PhpPath).Path
    $apcuPath = (Resolve-Path -LiteralPath $ApcuExtension).Path
    $phpArgsWithoutApcu = @(
        '-n',
        '-d', "extension_dir=$(Join-Path $phpRoot 'ext')",
        '-d', 'extension=php_curl.dll',
        '-d', 'extension=php_openssl.dll',
        '-d', 'extension=php_mbstring.dll'
    )
    $phpArgs = $phpArgsWithoutApcu + @(
        '-d', "extension=$apcuPath",
        '-d', 'apc.enable_cli=1'
    )
    $environmentNames = @(
        'JM_TEST_MODE',
        'JM_TEST_ALLOWED_HOSTS',
        'JM_TEST_API_BASE_URLS',
        'JM_TEST_DOMAIN_SOURCE_URLS',
        'JM_LIST_CACHE_TTL',
        'JM_SEARCH_CACHE_TTL',
        'JM_WEEKLY_LIST_CACHE_TTL',
        'JM_ALBUM_CACHE_TTL',
        'JM_WEEK_DEFAULTS_CACHE_TTL',
        'JM_WEEK_DEFAULTS_STALE_TTL',
        'JM_DOMAIN_REFRESH_DEFERRED',
        'JM_DOMAIN_SOURCE_TIMEOUT_MS',
        'JM_DOMAIN_REFRESH_BUDGET_MS',
        'JM_DOMAIN_REFRESH_FAILURE_TTL',
        'JM_REQUEST_BUDGET_MS',
        'JM_MAX_UPSTREAM_ATTEMPTS',
        'JM_FIXTURE_STATS_DIR'
    )
    $savedEnvironment = @{}
    foreach ($name in $environmentNames) {
        $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }

    $logToken = [guid]::NewGuid().ToString('N')
    $fixtureOut = Join-Path $env:TEMP "jm-list-cache-fixture-$logToken.out"
    $fixtureErr = Join-Path $env:TEMP "jm-list-cache-fixture-$logToken.err"
    $apiOut = Join-Path $env:TEMP "jm-list-cache-api-$logToken.out"
    $apiErr = Join-Path $env:TEMP "jm-list-cache-api-$logToken.err"
    $logPaths = @($fixtureOut, $fixtureErr, $apiOut, $apiErr)
    $statsDirectory = Resolve-ControlledFixtureStatsDirectory (Join-Path $env:TEMP "jm-list-cache-stats-$logToken") $logToken
    $fixtureProcess = $null
    $apiProcess = $null
    $completed = $false

    try {
        $env:JM_FIXTURE_STATS_DIR = $statsDirectory
        $fixtureStart = @{
            FilePath = $PhpPath
            ArgumentList = $phpArgs + @('-S', "127.0.0.1:$LocalFixturePort", 'tests\fixtures\upstream-router.php')
            WorkingDirectory = $root
            WindowStyle = 'Hidden'
            PassThru = $true
            RedirectStandardOutput = $fixtureOut
            RedirectStandardError = $fixtureErr
        }
        $fixtureProcess = Start-Process @fixtureStart

        $env:JM_TEST_MODE = '1'
        $env:JM_TEST_ALLOWED_HOSTS = '127.0.0.1'
        $env:JM_TEST_API_BASE_URLS = $FixtureUrl
        $env:JM_TEST_DOMAIN_SOURCE_URLS = $(if ($LocalDomainRefresh) { "$FixtureUrl/domain-config-timeout" } else { '' })
        $env:JM_DOMAIN_REFRESH_DEFERRED = $(if ($LocalDomainRefresh) { '1' } else { '0' })
        $env:JM_DOMAIN_SOURCE_TIMEOUT_MS = '1500'
        $env:JM_DOMAIN_REFRESH_BUDGET_MS = '3000'
        $env:JM_DOMAIN_REFRESH_FAILURE_TTL = '30'
        $env:JM_REQUEST_BUDGET_MS = '5000'
        $env:JM_MAX_UPSTREAM_ATTEMPTS = '3'

        $startApi = {
            param(
                [int] $ListTtl,
                [int] $SearchTtl,
                [int] $WeeklyTtl,
                [int] $AlbumTtl = 45,
                [int] $WeekDefaultsTtl = 600,
                [int] $WeekDefaultsStaleTtl = 3600,
                [bool] $UseApcu = $true
            )
            $env:JM_LIST_CACHE_TTL = [string] $ListTtl
            $env:JM_SEARCH_CACHE_TTL = [string] $SearchTtl
            $env:JM_WEEKLY_LIST_CACHE_TTL = [string] $WeeklyTtl
            $env:JM_ALBUM_CACHE_TTL = [string] $AlbumTtl
            $env:JM_WEEK_DEFAULTS_CACHE_TTL = [string] $WeekDefaultsTtl
            $env:JM_WEEK_DEFAULTS_STALE_TTL = [string] $WeekDefaultsStaleTtl
            $selectedPhpArgs = if ($UseApcu) { $phpArgs } else { $phpArgsWithoutApcu }
            $apiStart = @{
                FilePath = $PhpPath
                ArgumentList = $selectedPhpArgs + @('-S', "127.0.0.1:$LocalApiPort", 'index.php')
                WorkingDirectory = $root
                WindowStyle = 'Hidden'
                PassThru = $true
                RedirectStandardOutput = $apiOut
                RedirectStandardError = $apiErr
            }
            return Start-Process @apiStart
        }

        $apiProcess = if ($LocalMetadataCache) {
            & $startApi 60 60 0 300 2 3
        } else {
            & $startApi 60 60 60
        }
        Wait-LocalEndpoint "$FixtureUrl/__stats?run_id=ready-check" $fixtureProcess
        Wait-LocalEndpoint "$BaseUrl/?health=1" $apiProcess
        $health = Invoke-RestMethod -Uri "$BaseUrl/?health=1"
        Assert-True ($health.diagnostics.apcu -eq $true) 'Local API did not enable APCu.'
        Assert-True ($health.diagnostics.test_mode -eq $true) 'Local API did not enable test mode.'

        if ($LocalDomainRefresh) {
            $runId = New-RunId
            Reset-Fixture $runId
            $domainFallback = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'valid-list-80' $runId)
            Assert-True ($domainFallback.Status -eq 200) 'Local fallback API must serve while the domain source times out.'
            Assert-True ($domainFallback.ElapsedMs -lt 750) "Deferred domain refresh delayed the complete client response by $($domainFallback.ElapsedMs) ms."
            $domainSourceCalls = 0
            for ($attempt = 1; $attempt -le 20; $attempt++) {
                $domainCounts = Get-FixtureCounts $runId
                $domainSourceCalls = Count-FixtureEndpoint $domainCounts '/domain-config-timeout' '' 'valid-list-80'
                if ($domainSourceCalls -ge 1) { break }
                Start-Sleep -Milliseconds 250
            }
            Assert-True ($domainSourceCalls -eq 1) 'Deferred local domain refresh did not execute exactly once after response publication.'
            $secondFallback = Invoke-CapturedRequest (Api-Url 'list=latest&page=2&format=min' 'valid-list-80' $runId)
            Assert-True ($secondFallback.Status -eq 200 -and $secondFallback.ElapsedMs -lt 750) 'Domain refresh negative cache did not keep the second fallback response fast.'
            $domainCounts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $domainCounts '/domain-config-timeout' '' 'valid-list-80') -eq 1) 'Domain refresh lease/negative cache did not suppress a duplicate local refresh.'
            $completed = $true
            Write-Output "Local deferred domain-refresh response verification passed: first_ms=$($domainFallback.ElapsedMs), second_ms=$($secondFallback.ElapsedMs), source_calls=1."
            return
        }

        if ($LocalMetadataCache) {
            # The PHP built-in server is single-worker. The synchronized clients still prove one
            # cache fill and nine subsequent hits, but do not claim true threaded concurrency.
            $runId = New-RunId
            Reset-Fixture $runId
            $albumBarrier = $null
            $jobs = @()
            try {
                $albumBarrier = New-ControlledConcurrentJobBarrier -JobCount 10
                for ($jobIndex = 0; $jobIndex -lt 10; $jobIndex++) {
                    $jobs += Start-Job -ScriptBlock $script:ConcurrentRequestJob -ArgumentList `
                        (Api-Url 'jmid=350234&format=min' 'valid-album' $runId), `
                        $albumBarrier.ReadyPaths[$jobIndex], `
                        $albumBarrier.ReleasePath, `
                        $albumBarrier.Token, `
                        $albumBarrier.ReleaseTimeoutMs, `
                        30
                }
                Wait-ConcurrentJobBarrierReady -Barrier $albumBarrier -Jobs $jobs -ReadyTimeoutMs $albumBarrier.ReadyTimeoutMs
                Release-ConcurrentJobBarrier -Barrier $albumBarrier
                $statuses = @($jobs | Receive-Job -Wait -ErrorAction Stop)
            } finally {
                $cleanupErrors = @()
                try { Stop-AndRemoveConcurrentJobs -Jobs $jobs } catch { $cleanupErrors += $_.Exception.Message }
                if ($null -ne $albumBarrier) {
                    try { Remove-ControlledConcurrentJobBarrier -Barrier $albumBarrier } catch { $cleanupErrors += $_.Exception.Message }
                }
                if ($cleanupErrors.Count -gt 0) {
                    throw 'Metadata concurrent request cleanup failed: ' + ($cleanupErrors -join ' | ')
                }
            }
            Assert-True (($statuses | Where-Object { $_ -ne 200 }).Count -eq 0) 'all ten local album clients must succeed'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'valid-album') -eq 1) 'same-ID album cache fill must reach /album exactly once'

            $runId = New-RunId
            Reset-Fixture $runId
            $albumA = Invoke-CapturedRequest (Api-Url 'jmid=350234&format=min' 'valid-album-a' $runId)
            $albumB = Invoke-CapturedRequest (Api-Url 'jmid=350235&format=min' 'valid-album-b' $runId)
            $albumAHit = Invoke-CapturedRequest (Api-Url 'jmid=350234&format=min' 'valid-album-a' $runId)
            $albumBHit = Invoke-CapturedRequest (Api-Url 'jmid=350235&format=min' 'valid-album-b' $runId)
            Assert-True (($albumA.Status -eq 200) -and ($albumB.Status -eq 200) -and ($albumAHit.Status -eq 200) -and ($albumBHit.Status -eq 200)) 'isolated album ID requests failed'
            $albumAJson = $albumAHit.Body | ConvertFrom-Json
            $albumBJson = $albumBHit.Body | ConvertFrom-Json
            Assert-True (([string] $albumAJson.data.album.album_id) -eq '350234' -and ([string] $albumAJson.data.album.name) -eq 'Fixture Album A') 'album A cache value was crossed'
            Assert-True (([string] $albumBJson.data.album.album_id) -eq '350235' -and ([string] $albumBJson.data.album.name) -eq 'Fixture Album B') 'album B cache value was crossed'
            foreach ($albumResponse in @($albumA, $albumB, $albumAHit, $albumBHit)) {
                Assert-True ([string]::IsNullOrWhiteSpace((Header-Value $albumResponse.Headers 'X-JM-Source-Cache'))) 'album metadata cache must not emit X-JM-Source-Cache'
            }
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'valid-album-a') -eq 1) 'album A must have one isolated upstream fill'
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'valid-album-b') -eq 1) 'album B must have one isolated upstream fill'

            $runId = New-RunId
            Reset-Fixture $runId
            $minimalAlbums = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'jmid=350236&format=min' 'valid-album-minimal' $runId)
            })
            Assert-True (($minimalAlbums | Where-Object Status -ne 200).Count -eq 0) 'minimal album payload must retain fallback compatibility'
            $minimalJson = $minimalAlbums[1].Body | ConvertFrom-Json
            Assert-True (([string] $minimalJson.data.album.album_id) -eq '350236') 'minimal album must normalize missing ID to the requested ID'
            Assert-True (([string] $minimalJson.data.album.name) -eq 'Fixture Minimal Album') 'minimal album name changed during normalization'
            Assert-True ([int] $minimalJson.data.chapters_total -eq 1) 'minimal album must retain the fallback single chapter'
            Assert-True (([string] $minimalJson.data.chapters[0].photo_id) -eq '350236') 'minimal album fallback chapter must use the requested ID'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'valid-album-minimal') -eq 1) 'minimal normalized album payload must be cacheable'

            $runId = New-RunId
            Reset-Fixture $runId
            $malformedSeriesAlbums = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'jmid=350234&format=min' 'malformed-album-series' $runId)
            })
            Assert-True (($malformedSeriesAlbums | Where-Object Status -lt 400).Count -eq 0) 'album series with any malformed item must fail as a whole'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'malformed-album-series') -eq 2) 'partial album series results must never enter cache'

            $runId = New-RunId
            Reset-Fixture $runId
            $malformedAlbums = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'jmid=350234&format=min' 'malformed-200' $runId)
            })
            Assert-True (($malformedAlbums | Where-Object Status -lt 400).Count -eq 0) 'malformed album payloads must fail'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'malformed-200') -eq 2) 'malformed album payloads must never enter cache'

            $runId = New-RunId
            Reset-Fixture $runId
            $invalidWeekDefaults = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-invalid' $runId)
            })
            Assert-True (($invalidWeekDefaults | Where-Object Status -lt 400).Count -eq 0) 'non-numeric weekly default IDs must fail'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-default-invalid') -eq 2) 'invalid weekly default IDs must never enter cache'
            Assert-True ((Count-FixtureEndpoint $counts '/week/filter' '1' 'week-default-invalid') -eq 0) 'invalid weekly default IDs must not reach /week/filter'

            $weekRunId = New-RunId
            Reset-Fixture $weekRunId
            $weekPrime = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v1' $weekRunId)
            $weekFreshHit = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v2' $weekRunId)
            Assert-True ($weekPrime.Status -eq 200 -and $weekFreshHit.Status -eq 200) 'weekly fresh-cache requests failed'
            Assert-SourceCacheStatuses @($weekPrime, $weekFreshHit) @('disabled', 'disabled') 'weekly metadata fresh cache'
            $weekPrimeJson = $weekPrime.Body | ConvertFrom-Json
            $weekFreshJson = $weekFreshHit.Body | ConvertFrom-Json
            Assert-True (([string] $weekPrimeJson.data.items[0].id) -eq 'week-11-21') 'weekly v1 defaults were not forwarded to /week/filter'
            Assert-True (([string] $weekFreshJson.data.items[0].id) -eq 'week-11-21') 'fresh weekly defaults did not suppress the changed /week payload'
            $counts = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-default-v1') -eq 1) 'weekly defaults prime must call /week once'
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-default-v2') -eq 0) 'fresh weekly defaults hit must not call /week'

            $isolatedRunId = New-RunId
            Reset-Fixture $isolatedRunId
            $isolatedWeek = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v2' $isolatedRunId)
            $isolatedJson = $isolatedWeek.Body | ConvertFrom-Json
            Assert-True ($isolatedWeek.Status -eq 200 -and ([string] $isolatedJson.data.items[0].id) -eq 'week-12-22') 'weekly defaults test namespace was not isolated'
            $isolatedCounts = Get-FixtureCounts $isolatedRunId
            Assert-True ((Count-FixtureEndpoint $isolatedCounts '/week' '' 'week-default-v2') -eq 1) 'isolated weekly namespace must perform its own /week fill'

            Start-Sleep -Milliseconds 2200
            $weekRefresh = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v2' $weekRunId)
            $weekRefreshJson = $weekRefresh.Body | ConvertFrom-Json
            Assert-True ($weekRefresh.Status -eq 200 -and ([string] $weekRefreshJson.data.items[0].id) -eq 'week-12-22') 'expired weekly defaults did not refresh to v2'
            Assert-SourceCacheStatuses @($weekRefresh) @('disabled') 'weekly metadata successful refresh'
            $counts = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-default-v2') -eq 1) 'expired weekly defaults refresh must call /week once'

            $healthBeforeFallback = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_scenario=week-default-v2&test_run_id=$weekRunId"
            Assert-True ([int] $healthBeforeFallback.diagnostics.metadata_cache.week_defaults.fresh_ttl_seconds -eq 2) 'health weekly fresh TTL is incorrect'
            Assert-True ([int] $healthBeforeFallback.diagnostics.metadata_cache.week_defaults.stale_ttl_seconds -eq 3) 'health weekly stale TTL is incorrect'
            Assert-True ([int] $healthBeforeFallback.diagnostics.metadata_cache.week_defaults.stale_fallback_count -eq 0) 'weekly stale fallback counter must start at zero'
            $healthMetadataJson = $healthBeforeFallback.diagnostics.metadata_cache | ConvertTo-Json -Depth 8 -Compress
            Assert-True ($healthMetadataJson -notmatch 'category_id|type_id|week-11-21|week-12-22') 'metadata health diagnostics must not expose cached IDs or values'
            $countsAfterHealth = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $countsAfterHealth '/week' '' 'week-default-v2') -eq 1) 'health must not trigger /week'

            Start-Sleep -Milliseconds 2200
            $weekFallback = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-only-502' $weekRunId)
            $weekFallbackJson = $weekFallback.Body | ConvertFrom-Json
            Assert-True ($weekFallback.Status -eq 200 -and ([string] $weekFallbackJson.data.items[0].id) -eq 'week-12-22') 'week-only 502 did not use bounded stale defaults while /week/filter stayed healthy'
            Assert-SourceCacheStatuses @($weekFallback) @('disabled') 'weekly metadata stale fallback'
            $counts = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-only-502') -eq 2) 'failed weekly refresh must use the bounded upstream retry policy'
            Assert-True ((Count-FixtureEndpoint $counts '/week/filter' '1' 'week-only-502') -eq 1) 'stale weekly defaults must still call healthy /week/filter once'
            $healthAfterFallback = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_scenario=week-default-v2&test_run_id=$weekRunId"
            Assert-True ([int] $healthAfterFallback.diagnostics.metadata_cache.week_defaults.stale_fallback_count -eq 1) 'weekly stale fallback counter did not increase'
            Assert-True (([string] $healthAfterFallback.diagnostics.metadata_cache.week_defaults.entry_status) -eq 'stale') 'health must report the retained weekly entry as stale'
            $countsAfterFallbackHealth = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $countsAfterFallbackHealth '/week' '' 'week-default-v2') -eq 1) 'stale health inspection must not call /week'

            Start-Sleep -Seconds 4
            $weekPastStale = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-only-502' $weekRunId)
            Assert-True ($weekPastStale.Status -ge 400) 'weekly defaults beyond stale_until must surface the upstream error'
            Assert-True ([string]::IsNullOrWhiteSpace((Header-Value $weekPastStale.Headers 'X-JM-Source-Cache'))) 'past-stale failure before /week/filter must not emit X-JM-Source-Cache'
            $counts = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-only-502') -eq 4) 'request beyond stale_until must attempt /week and surface its failure'
            Assert-True ((Count-FixtureEndpoint $counts '/week/filter' '1' 'week-only-502') -eq 1) 'request beyond stale_until must not call /week/filter'
            $healthPastStale = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_scenario=week-default-v2&test_run_id=$weekRunId"
            Assert-True ([int] $healthPastStale.diagnostics.metadata_cache.week_defaults.stale_fallback_count -eq 1) 'request beyond stale_until must not increase fallback count'
            $countsAfterPastStaleHealth = Get-FixtureCounts $weekRunId
            Assert-True ((Count-FixtureEndpoint $countsAfterPastStaleHealth '/week' '' 'week-default-v2') -eq 1) 'past-stale health inspection must not call /week'

            Stop-Process -Id $apiProcess.Id -Force
            $apiProcess.WaitForExit()
            $apiProcess = & $startApi 60 60 0 0 0 10
            Wait-LocalEndpoint "$BaseUrl/?health=1" $apiProcess

            $runId = New-RunId
            Reset-Fixture $runId
            $albumDisabled = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'jmid=350234&format=min' 'valid-album' $runId)
            })
            Assert-True (($albumDisabled | Where-Object Status -ne 200).Count -eq 0) 'album TTL zero requests failed'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'valid-album') -eq 2) 'album TTL zero must call /album for every request'

            $runId = New-RunId
            Reset-Fixture $runId
            $weekDisabled = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v1' $runId)
            })
            Assert-True (($weekDisabled | Where-Object Status -ne 200).Count -eq 0) 'weekly fresh TTL zero requests failed'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-default-v1') -eq 2) 'weekly fresh TTL zero must call /week for every request'
            $disabledHealth = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_run_id=$runId"
            Assert-True (([string] $disabledHealth.diagnostics.metadata_cache.week_defaults.entry_status) -eq 'disabled') 'health must report weekly defaults cache disabled when fresh TTL is zero'

            Stop-Process -Id $apiProcess.Id -Force
            $apiProcess.WaitForExit()
            $apiProcess = & $startApi 60 60 0 45 1 0
            Wait-LocalEndpoint "$BaseUrl/?health=1" $apiProcess

            $runId = New-RunId
            Reset-Fixture $runId
            $staleZeroPrime = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v1' $runId)
            Assert-True ($staleZeroPrime.Status -eq 200) 'weekly stale-zero prime failed'
            Assert-SourceCacheStatuses @($staleZeroPrime) @('disabled') 'weekly stale-zero prime'
            Start-Sleep -Seconds 2
            $staleZeroFailure = Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-only-502' $runId)
            Assert-True ($staleZeroFailure.Status -ge 400) 'weekly stale TTL zero must surface refresh failure'
            Assert-True ([string]::IsNullOrWhiteSpace((Header-Value $staleZeroFailure.Headers 'X-JM-Source-Cache'))) 'weekly stale TTL zero failure before /week/filter must not emit X-JM-Source-Cache'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-only-502') -eq 2) 'weekly stale TTL zero failure must attempt /week exactly twice'
            Assert-True ((Count-FixtureEndpoint $counts '/week/filter' '1' 'week-only-502') -eq 0) 'weekly stale TTL zero must not continue to /week/filter'
            $staleZeroHealth = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_scenario=week-only-502&test_run_id=$runId"
            Assert-True ([int] $staleZeroHealth.diagnostics.metadata_cache.week_defaults.stale_fallback_count -eq 0) 'weekly stale TTL zero must not increase fallback count'
            $countsAfterStaleZeroHealth = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $countsAfterStaleZeroHealth '/week' '' 'week-only-502') -eq 2) 'stale-zero health inspection must not call /week'

            Stop-Process -Id $apiProcess.Id -Force
            $apiProcess.WaitForExit()
            $apiProcess = & $startApi 60 60 0 45 10 10 $false
            Wait-LocalEndpoint "$BaseUrl/?health=1" $apiProcess

            $unavailableHealth = Invoke-RestMethod -Uri "$BaseUrl/?health=1"
            Assert-True ($unavailableHealth.diagnostics.apcu -eq $false) 'APCu-unavailable metadata test unexpectedly loaded APCu'
            Assert-True (([string] $unavailableHealth.diagnostics.metadata_cache.week_defaults.entry_status) -eq 'unavailable') 'health must report unavailable metadata state without APCu'

            $runId = New-RunId
            Reset-Fixture $runId
            $unavailableAlbums = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'jmid=350234&format=min' 'valid-album' $runId)
            })
            Assert-True (($unavailableAlbums | Where-Object Status -ne 200).Count -eq 0) 'APCu-unavailable album requests failed'
            foreach ($albumResponse in $unavailableAlbums) {
                Assert-True ([string]::IsNullOrWhiteSpace((Header-Value $albumResponse.Headers 'X-JM-Source-Cache'))) 'APCu-unavailable album metadata must not emit X-JM-Source-Cache'
            }
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/album' '' 'valid-album') -eq 2) 'APCu-unavailable album requests must call /album every time'

            $runId = New-RunId
            Reset-Fixture $runId
            $unavailableWeeks = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'list=weekly&page=1&format=min' 'week-default-v1' $runId)
            })
            Assert-True (($unavailableWeeks | Where-Object Status -ne 200).Count -eq 0) 'APCu-unavailable weekly requests failed'
            Assert-SourceCacheStatuses $unavailableWeeks @('disabled', 'disabled') 'APCu-unavailable weekly metadata'
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/week' '' 'week-default-v1') -eq 2) 'APCu-unavailable weekly requests must call /week every time'

            $completed = $true
            Write-Output 'Local metadata-cache runtime verification passed: album same-id=1 isolated=1+1 minimal=1 malformed=2 malformed-series=2 disabled=2 unavailable=2; week invalid=2 fresh=1 refresh=1 stale-fallback=1 expired=error fresh0=2 stale0=error unavailable=2; health /week calls=0.'
            Write-Output 'Weekly source headers: fresh=disabled refresh=disabled stale-fallback=disabled past-stale=absent stale0=absent; album metadata headers=absent.'
            Write-Output 'Docker multi-worker owner/loser acceptance: NOT ACCEPTED (Docker runtime unavailable); Windows PHP built-in server is single-worker and only validates exact serialized cache counts.'
            return
        }

        $runId = New-RunId
        Reset-Fixture $runId
        $responses = @(1..4 | ForEach-Object {
            Invoke-CapturedRequest (Api-Url "list=latest&page=$_&format=min" 'valid-list-80' $runId)
        })
        Assert-True (($responses | Where-Object Status -ne 200).Count -eq 0) 'latest pages 1..4 failed'
        Assert-SourceCacheStatuses $responses @('miss', 'hit', 'hit', 'hit') 'latest pages 1..4'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/latest' '0' 'valid-list-80') -eq 1) 'latest source page 0 must be fetched exactly once'
        $page5Responses = @(1..2 | ForEach-Object {
            Invoke-CapturedRequest (Api-Url 'list=latest&page=5&format=min' 'valid-list-80' $runId)
        })
        Assert-True (($page5Responses | Where-Object Status -ne 200).Count -eq 0) 'latest page 5 requests failed'
        Assert-SourceCacheStatuses $page5Responses @('miss', 'hit') 'latest page 5'
        $page5FirstJson = $page5Responses[0].Body | ConvertFrom-Json
        $page5SecondJson = $page5Responses[1].Body | ConvertFrom-Json
        Assert-True ([int] $page5FirstJson.data.api_calls -eq 1) 'latest page 5 miss must make one upstream API call'
        Assert-True ([int] $page5SecondJson.data.api_calls -eq 0) 'latest page 5 hit must make no upstream API calls'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/latest' '1' 'valid-list-80') -eq 1) 'latest source page 1 must be fetched exactly once'

        $runId = New-RunId
        Reset-Fixture $runId
        $queries = @(
            'list=popular&page=1&order=new&format=min',
            'list=popular&page=1&order=new&format=min',
            'list=popular&page=1&order=mv&format=min',
            'list=popular&page=1&order=mv&format=min'
        )
        $responses = @($queries | ForEach-Object { Invoke-CapturedRequest (Api-Url $_ 'valid-list-80' $runId) })
        Assert-SourceCacheStatuses $responses @('miss', 'hit', 'miss', 'hit') 'popular order variants'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/categories/filter' '0' 'valid-list-80') -eq 2) 'popular orders must use isolated cache keys'

        $runId = New-RunId
        Reset-Fixture $runId
        $queries = @(
            'search=alpha&page=1&order=mr&format=min',
            'search=alpha&page=1&order=mr&format=min',
            'search=alpha&page=1&order=mv&format=min',
            'search=alpha&page=1&order=mv&format=min',
            'search=beta&page=1&order=mr&format=min',
            'search=beta&page=1&order=mr&format=min'
        )
        $responses = @($queries | ForEach-Object { Invoke-CapturedRequest (Api-Url $_ 'valid-list-80' $runId) })
        Assert-SourceCacheStatuses $responses @('miss', 'hit', 'miss', 'hit', 'miss', 'hit') 'search query/order variants'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/search' '1' 'valid-list-80') -eq 3) 'search query/order variants must use isolated cache keys'

        $runId = New-RunId
        Reset-Fixture $runId
        $responses = @(1..2 | ForEach-Object {
            Invoke-CapturedRequest (Api-Url 'search=redirect&page=1&order=mr&format=min' 'valid-search-redirect' $runId)
        })
        Assert-True (($responses | Where-Object Status -ne 200).Count -eq 0) 'valid search redirect payload failed'
        Assert-SourceCacheStatuses $responses @('miss', 'hit') 'valid search redirect payload'
        $redirectJson = $responses[1].Body | ConvertFrom-Json
        Assert-True (([string] $redirectJson.data.items[0].id) -eq '350234') 'search redirect payload did not produce the redirect album'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/search' '1' 'valid-search-redirect') -eq 1) 'valid search redirect payload was not cached'

        $runId = New-RunId
        Reset-Fixture $runId
        $queries = @(
            'list=weekly&page=1&category_id=1&type_id=1&format=min',
            'list=weekly&page=1&category_id=1&type_id=1&format=min',
            'list=weekly&page=1&category_id=2&type_id=1&format=min',
            'list=weekly&page=1&category_id=2&type_id=1&format=min',
            'list=weekly&page=1&category_id=1&type_id=2&format=min',
            'list=weekly&page=1&category_id=1&type_id=2&format=min'
        )
        $responses = @($queries | ForEach-Object { Invoke-CapturedRequest (Api-Url $_ 'valid-weekly-list' $runId) })
        Assert-SourceCacheStatuses $responses @('miss', 'hit', 'miss', 'hit', 'miss', 'hit') 'weekly category/type variants'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/week/filter' '1' 'valid-weekly-list') -eq 3) 'weekly category/type variants must use isolated cache keys'

        $runId = New-RunId
        Reset-Fixture $runId
        $responses = @(1..2 | ForEach-Object {
            Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'valid-empty-list' $runId)
        })
        Assert-True (($responses | Where-Object Status -ne 200).Count -eq 0) 'valid empty list failed'
        Assert-SourceCacheStatuses $responses @('miss', 'hit') 'valid empty list'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/latest' '0' 'valid-empty-list') -eq 1) 'valid empty list was not cached'

        foreach ($scenario in @('malformed-200', 'bad-encrypted')) {
            $runId = New-RunId
            Reset-Fixture $runId
            $responses = @(1..2 | ForEach-Object {
                Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' $scenario $runId)
            })
            Assert-True (($responses | Where-Object Status -lt 400).Count -eq 0) "$scenario must fail"
            $counts = Get-FixtureCounts $runId
            Assert-True ((Count-FixtureEndpoint $counts '/latest' '0' $scenario) -eq 2) "$scenario must not enter the list cache"
        }

        $runId = New-RunId
        Reset-Fixture $runId
        $prime = Invoke-CapturedRequest (Api-Url 'list=promote&page=1&section=7&format=min' 'valid-list-80' $runId)
        $mixed = Invoke-CapturedRequest (Api-Url 'list=promote&page=2&section=7&format=min' 'valid-list-80' $runId)
        Assert-True ($prime.Status -eq 200 -and $mixed.Status -eq 200) 'promote mixed-cache requests failed'
        Assert-True ((Header-Value $mixed.Headers 'X-JM-Source-Cache') -eq 'mixed') 'promote multi-source request must report mixed'
        $mixedJson = $mixed.Body | ConvertFrom-Json
        Assert-True ([int] $mixedJson.data.source_cache_hits -eq 1) 'promote mixed request source_cache_hits must equal 1'
        Assert-True ([int] $mixedJson.data.source_cache_misses -eq 1) 'promote mixed request source_cache_misses must equal 1'
        Assert-True ([int] $mixedJson.data.api_calls -eq 1) 'promote mixed request api_calls must equal 1'
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/promote_list' '0' 'valid-list-80') -eq 1) 'promote source page 0 count must equal 1'
        Assert-True ((Count-FixtureEndpoint $counts '/promote_list' '1' 'valid-list-80') -eq 1) 'promote source page 1 count must equal 1'

        Stop-Process -Id $apiProcess.Id -Force
        $apiProcess.WaitForExit()
        $apiProcess = & $startApi 0 0 0
        Wait-LocalEndpoint "$BaseUrl/?health=1" $apiProcess
        $runId = New-RunId
        Reset-Fixture $runId
        $responses = @(1..2 | ForEach-Object {
            Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'valid-list-80' $runId)
        })
        Assert-SourceCacheStatuses $responses @('disabled', 'disabled') 'TTL zero'
        foreach ($response in $responses) {
            $json = $response.Body | ConvertFrom-Json
            Assert-True ([int] $json.data.api_calls -eq 1) 'TTL zero request must call the upstream producer'
        }
        $counts = Get-FixtureCounts $runId
        Assert-True ((Count-FixtureEndpoint $counts '/latest' '0' 'valid-list-80') -eq 2) 'TTL zero must call upstream for every request'

        $completed = $true
        Write-Output 'Local list-cache runtime verification passed: latest=1+1 popular=2 search=3 weekly=3 empty=1 malformed=2 redirect=1 promote=mixed disabled=2.'
    } finally {
        $ownedProcesses = @($apiProcess, $fixtureProcess) | Where-Object { $null -ne $_ }
        $processExitErrors = [Collections.Generic.List[string]]::new()
        $artifactCleanupErrors = [Collections.Generic.List[string]]::new()
        foreach ($ownedProcess in $ownedProcesses) {
            try {
                if (-not $ownedProcess.HasExited) {
                    Stop-Process -Id $ownedProcess.Id -Force -ErrorAction Stop
                }
            } catch {
                $processExitErrors.Add("Failed to stop owned PHP process $($ownedProcess.Id): $($_.Exception.Message)")
            }
        }
        foreach ($ownedProcess in $ownedProcesses) {
            try {
                if (-not $ownedProcess.WaitForExit(5000)) {
                    $processExitErrors.Add("Owned local PHP process did not exit: $($ownedProcess.Id)")
                }
            } catch {
                $processExitErrors.Add("Failed while waiting for owned PHP process $($ownedProcess.Id): $($_.Exception.Message)")
            }
        }

        if (-not $completed) {
            foreach ($errorLog in @($apiErr, $fixtureErr)) {
                try {
                    if (Test-Path -LiteralPath $errorLog) { Get-Content -LiteralPath $errorLog -Tail 30 }
                } catch {
                    $artifactCleanupErrors.Add("Failed to read local PHP error log $($errorLog): $($_.Exception.Message)")
                }
            }
        }

        try {
            Remove-ControlledLocalLogFiles $logPaths $logToken
        } catch {
            $artifactCleanupErrors.Add("Failed to remove local PHP logs: $($_.Exception.Message)")
        }

        $controlledStatsDirectory = $null
        try {
            $controlledStatsDirectory = Resolve-ControlledFixtureStatsDirectory $statsDirectory $logToken
            if (Test-Path -LiteralPath $controlledStatsDirectory -PathType Container) {
                Remove-Item -LiteralPath $controlledStatsDirectory -Recurse -Force
            } elseif (Test-Path -LiteralPath $controlledStatsDirectory) {
                throw "Fixture stats path is not a directory: $controlledStatsDirectory"
            }
        } catch {
            $artifactCleanupErrors.Add("Failed to remove fixture stats: $($_.Exception.Message)")
        }

        try {
            $remainingLogs = @($logPaths | Where-Object { Test-Path -LiteralPath $_ })
            $statsRemain = $null -ne $controlledStatsDirectory -and (Test-Path -LiteralPath $controlledStatsDirectory)
            if ($remainingLogs.Count -ne 0 -or $statsRemain) {
                throw 'Local list-cache temporary artifact cleanup was incomplete.'
            }
        } catch {
            $artifactCleanupErrors.Add($_.Exception.Message)
        }

        foreach ($name in $environmentNames) {
            try {
                [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
            } catch {
                $artifactCleanupErrors.Add("Failed to restore $($name): $($_.Exception.Message)")
            }
        }

        if ($processExitErrors.Count -gt 0 -or $artifactCleanupErrors.Count -gt 0) {
            $cleanupMessages = @($processExitErrors) + @($artifactCleanupErrors)
            throw 'Local list-cache cleanup failed: ' + ($cleanupMessages -join ' | ')
        }
    }
}

if ($LocalResources) {
    & (Join-Path $PSScriptRoot 'resource-http-runtime.ps1') `
        -PhpPath $PhpPath `
        -ApcuExtension $ApcuExtension `
        -ApiPort $LocalApiPort `
        -FixturePort $LocalFixturePort
    return
}
if ($LocalListCache) {
    Invoke-LocalListCacheVerification
    return
}
if ($LocalMetadataCache) {
    Invoke-LocalListCacheVerification
    return
}
if ($LocalDomainRefresh) {
    Invoke-LocalListCacheVerification
    return
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'docker is not available on PATH; fault-injection runtime verification requires Docker.'
}
if (-not $SkipComposeUp) {
    & docker compose -f docker-compose.yml -f docker-compose.test.yml up -d --build --force-recreate
    if ($LASTEXITCODE -ne 0) { throw 'test compose startup failed' }
}

for ($attempt = 1; $attempt -le 30; $attempt++) {
    try { Invoke-WebRequest -UseBasicParsing -Uri "$FixtureUrl/__stats?run_id=ready-check" | Out-Null; break } catch { if ($attempt -eq 30) { throw 'fixture did not become ready' }; Start-Sleep -Seconds 1 }
}

for ($attempt = 1; $attempt -le 30; $attempt++) {
    try { Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/?health=1" | Out-Null; break } catch { if ($attempt -eq 30) { throw }; Start-Sleep -Seconds 1 }
}
$testHealth = Invoke-RestMethod -Uri "$BaseUrl/?health=1"
Assert-True ($testHealth.diagnostics.test_mode -eq $true) 'API did not enable JM_TEST_MODE; refusing to run fault tests against real upstream'
Assert-True (-not [string]::IsNullOrWhiteSpace([string] $testHealth.diagnostics.test_api_source)) 'API did not report a test upstream source; refusing to access real upstream'

# Domain config sources can all time out without blocking the business request.
if ($SkipComposeUp) {
    Write-Output 'Skipping domain-source timeout scenario because -SkipComposeUp forbids compose mutation.'
} else {
    $dockerEnvironmentNames = @(
        'JM_TEST_API_BASE_URLS',
        'JM_TEST_DOMAIN_SOURCE_URLS',
        'JM_TEST_FALLBACK_API_BASE_URLS'
    )
    $savedDockerEnvironment = @{}
    foreach ($name in $dockerEnvironmentNames) {
        $savedDockerEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }

    try {
        $env:JM_TEST_API_BASE_URLS = 'disabled'
        $env:JM_TEST_DOMAIN_SOURCE_URLS = 'http://domain-config:8090/domain-config-timeout'
        $env:JM_TEST_FALLBACK_API_BASE_URLS = 'http://api-good:8090'
        & docker compose -f docker-compose.yml -f docker-compose.test.yml up -d --force-recreate jmcomic-api
        if ($LASTEXITCODE -ne 0) { throw 'failed to recreate API for domain-source timeout test' }
        for ($attempt = 1; $attempt -le 30; $attempt++) {
            try { Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/?health=1" | Out-Null; break } catch { if ($attempt -eq 30) { throw }; Start-Sleep -Seconds 1 }
        }
        $runId = New-RunId
        Reset-Fixture $runId
        $domainFallback = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'valid-list-80' $runId)
        Assert-True ($domainFallback.Status -eq 200) 'fallback API must serve while all domain sources time out'
        Assert-True ($domainFallback.ElapsedMs -lt 750) 'domain source timeout blocked the complete business response'
        for ($attempt = 1; $attempt -le 20; $attempt++) {
            $domainCounts = Get-FixtureCounts $runId
            $domainSourceCalls = Count-Key $domainCounts 'domain-config|/domain-config-timeout||valid-list-80'
            if ($domainSourceCalls -ge 1) { break }
            Start-Sleep -Milliseconds 250
        }
        Assert-True ($domainSourceCalls -eq 1) 'deferred domain refresh must have one owner'
        $secondFallback = Invoke-CapturedRequest (Api-Url 'list=latest&page=2&format=min' 'valid-list-80' $runId)
        Assert-True ($secondFallback.Status -eq 200) 'second fallback request failed'
        Start-Sleep -Milliseconds 250
        $domainCounts = Get-FixtureCounts $runId
        Assert-True ((Count-Key $domainCounts 'domain-config|/domain-config-timeout||valid-list-80') -eq 1) 'domain refresh lease/negative cache did not suppress a duplicate refresh'
    } finally {
        $domainRestoreErrors = [Collections.Generic.List[string]]::new()
        foreach ($name in $dockerEnvironmentNames) {
            try {
                [Environment]::SetEnvironmentVariable($name, $savedDockerEnvironment[$name], 'Process')
            } catch {
                $domainRestoreErrors.Add("Failed to restore $($name): $($_.Exception.Message)")
            }
        }
        try {
            & docker compose -f docker-compose.yml -f docker-compose.test.yml up -d --force-recreate jmcomic-api
            if ($LASTEXITCODE -ne 0) { throw 'failed to restore API test compose environment' }
            for ($attempt = 1; $attempt -le 30; $attempt++) {
                try { Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/?health=1" | Out-Null; break } catch { if ($attempt -eq 30) { throw }; Start-Sleep -Seconds 1 }
            }
        } catch {
            $domainRestoreErrors.Add($_.Exception.Message)
        }
        if ($domainRestoreErrors.Count -gt 0) {
            throw 'Docker domain-source cleanup failed: ' + (@($domainRestoreErrors) -join ' | ')
        }
    }
}

# List source page cache: client pages 1..4 must fetch source page 0 once.
$runId = New-RunId
Reset-Fixture $runId
1..4 | ForEach-Object {
    $response = Invoke-CapturedRequest (Api-Url "list=latest&page=$_&format=min" 'valid-list-80' $runId)
    Assert-True ($response.Status -eq 200) "latest page $_ failed"
}
$counts = Get-FixtureCounts $runId
Assert-True ((Count-Key $counts 'api-good|/latest|0|valid-list-80') -eq 1) 'latest source page 0 must be fetched exactly once for client pages 1..4'
$page5 = Invoke-CapturedRequest (Api-Url 'list=latest&page=5&format=min' 'valid-list-80' $runId)
Assert-True ($page5.Status -eq 200) 'latest page 5 failed'
$counts = Get-FixtureCounts $runId
Assert-True ((Count-Key $counts 'api-good|/latest|1|valid-list-80') -eq 1) 'latest source page 1 must be fetched once for client page 5'

# Album single-flight: deterministic fixture count must be exactly one.
$runId = New-RunId
Reset-Fixture $runId
$dockerAlbumBarrier = $null
$dockerAlbumJobs = @()
try {
    $dockerAlbumBarrier = New-ControlledConcurrentJobBarrier -JobCount 10
    for ($jobIndex = 0; $jobIndex -lt 10; $jobIndex++) {
        $dockerAlbumJobs += Start-Job -ScriptBlock $script:ConcurrentRequestJob -ArgumentList `
            (Api-Url 'jmid=350234&format=min' 'valid-album' $runId), `
            $dockerAlbumBarrier.ReadyPaths[$jobIndex], `
            $dockerAlbumBarrier.ReleasePath, `
            $dockerAlbumBarrier.Token, `
            $dockerAlbumBarrier.ReleaseTimeoutMs, `
            30
    }
    Wait-ConcurrentJobBarrierReady -Barrier $dockerAlbumBarrier -Jobs $dockerAlbumJobs -ReadyTimeoutMs $dockerAlbumBarrier.ReadyTimeoutMs
    Release-ConcurrentJobBarrier -Barrier $dockerAlbumBarrier
    $dockerAlbumStatuses = @($dockerAlbumJobs | Receive-Job -Wait -ErrorAction Stop)
} finally {
    $cleanupErrors = @()
    try { Stop-AndRemoveConcurrentJobs -Jobs $dockerAlbumJobs } catch { $cleanupErrors += $_.Exception.Message }
    if ($null -ne $dockerAlbumBarrier) {
        try { Remove-ControlledConcurrentJobBarrier -Barrier $dockerAlbumBarrier } catch { $cleanupErrors += $_.Exception.Message }
    }
    if ($cleanupErrors.Count -gt 0) {
        throw 'Docker album concurrent request cleanup failed: ' + ($cleanupErrors -join ' | ')
    }
}
Assert-True ($dockerAlbumStatuses.Count -eq 10) 'all ten album clients must return exactly one status'
Assert-True (($dockerAlbumStatuses | Where-Object { $_ -ne 200 }).Count -eq 0) 'all ten album clients must succeed'
$counts = Get-FixtureCounts $runId
Assert-True ((Count-Key $counts 'api-good|/album||valid-album') -eq 1) 'fixture /album count must be exactly one'

# Error responses must retain safe request diagnostics.
foreach ($scenario in @('bad-json', 'bad-encrypted', 'business-error')) {
    $runId = New-RunId
    Reset-Fixture $runId
    $response = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' $scenario $runId)
    Assert-True ($response.Status -ge 400) "$scenario must fail"
    foreach ($header in @('X-JM-Request-Id', 'X-JM-Upstream-Attempts', 'X-JM-Upstream-Ms', 'X-JM-Deadline-Exhausted')) {
        Assert-True (-not [string]::IsNullOrWhiteSpace((Header-Value $response.Headers $header))) "$scenario missing $header"
    }
}

# Failures are never cached: two malformed responses must reach fixture twice.
$runId = New-RunId
Reset-Fixture $runId
1..2 | ForEach-Object { Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'bad-json' $runId) | Out-Null }
$counts = Get-FixtureCounts $runId
Assert-True ((Count-Key $counts 'api-good|/latest|0|bad-json') -eq 2) 'bad JSON responses must not enter list cache'

# Bounded retry: two primary 502 attempts then a secondary success.
$runId = New-RunId
Reset-Fixture $runId
$retry = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' '502-then-valid' $runId)
Assert-True ($retry.Status -eq 200) '502 failover must succeed on secondary fixture'
Assert-True ([int] (Header-Value $retry.Headers 'X-JM-Upstream-Attempts') -eq 3) '502 failover must use exactly three attempts'

foreach ($scenario in @('429-seconds', '429-date', '429-invalid')) {
    $runId = New-RunId
    Reset-Fixture $runId
    $response = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' $scenario $runId)
    Assert-True ($response.Status -eq 200) "$scenario must recover on secondary fixture"
    $attempts = [int] (Header-Value $response.Headers 'X-JM-Upstream-Attempts')
    Assert-True ($attempts -ge 2 -and $attempts -le 3) "$scenario must recover within bounded attempts"
    Assert-True ($response.ElapsedMs -lt 12000) "$scenario exceeded request budget"
    if ($scenario -eq '429-invalid') { Assert-True ($response.ElapsedMs -lt 1500) 'invalid Retry-After must not cause long sleep' }
}

# Overlapping prefetch windows use direct page-owner and slot observations. CDN
# request counts are deliberately not treated as lease-owner evidence.
$runId = New-RunId
Reset-Fixture $runId
$prefetchHealthBefore = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_run_id=$runId"
$imageBarrier = $null
$imageJobs = @()
try {
    $imageBarrier = New-ControlledConcurrentJobBarrier -JobCount 10
    for ($jobIndex = 0; $jobIndex -lt 10; $jobIndex++) {
        $page = 1 + ($jobIndex % 3)
        $imageJobs += Start-Job -ScriptBlock $script:ConcurrentRequestJob -ArgumentList `
            (Api-Url "jmid=350234&chapter=350234&page=$page" 'prefetch-slow' $runId), `
            $imageBarrier.ReadyPaths[$jobIndex], `
            $imageBarrier.ReleasePath, `
            $imageBarrier.Token, `
            $imageBarrier.ReleaseTimeoutMs, `
            30
    }
    Wait-ConcurrentJobBarrierReady -Barrier $imageBarrier -Jobs $imageJobs -ReadyTimeoutMs $imageBarrier.ReadyTimeoutMs
    Release-ConcurrentJobBarrier -Barrier $imageBarrier
    $imageStatuses = @($imageJobs | Receive-Job -Wait -ErrorAction Stop)
} finally {
    $cleanupErrors = @()
    try { Stop-AndRemoveConcurrentJobs -Jobs $imageJobs } catch { $cleanupErrors += $_.Exception.Message }
    if ($null -ne $imageBarrier) {
        try { Remove-ControlledConcurrentJobBarrier -Barrier $imageBarrier } catch { $cleanupErrors += $_.Exception.Message }
    }
    if ($cleanupErrors.Count -gt 0) {
        throw 'Docker prefetch concurrent request cleanup failed: ' + ($cleanupErrors -join ' | ')
    }
}
Assert-True (($imageStatuses | Where-Object { $_ -ne 200 }).Count -eq 0) 'overlapping image requests must succeed'
$prefetchOwnersObserved = $false
$fixtureStats = $null
for ($attempt = 1; $attempt -le 90; $attempt++) {
    $fixtureStats = Get-FixtureStats $runId
    $owners = $fixtureStats.prefetch_owners
    $ownerPages = @($owners.pages.PSObject.Properties)
    if ($ownerPages.Count -gt 0 -and [int] $owners.slots.acquire -gt 0 -and [int] $owners.slots.current -eq 0) {
        $prefetchOwnersObserved = $true
        break
    }
    Start-Sleep -Milliseconds 250
}
Assert-True $prefetchOwnersObserved 'prefetch overlap test observed no direct page-owner and slot lifecycle'
$owners = $fixtureStats.prefetch_owners
foreach ($property in @($owners.pages.PSObject.Properties)) {
    $pageOwner = $property.Value
    Assert-True ([int] $pageOwner.acquire -eq 1) "prefetch page had multiple owners: $($property.Name) acquire=$($pageOwner.acquire)"
    Assert-True ([int] $pageOwner.peak -le 1) "prefetch page owner peak exceeded one: $($property.Name) peak=$($pageOwner.peak)"
    Assert-True ([int] $pageOwner.current -eq 0) "prefetch page owner leaked: $($property.Name) current=$($pageOwner.current)"
    Assert-True ([int] $pageOwner.release -eq [int] $pageOwner.acquire) "prefetch page owner acquire/release mismatch: $($property.Name)"
}
Assert-True ([int] $owners.slots.acquire -eq [int] $owners.slots.release) 'prefetch slot acquire/release counts differ'
Assert-True ([int] $owners.slots.current -eq 0) 'prefetch slots were still held after callbacks completed'
Assert-True ([int] $owners.slots.peak -eq 2) 'prefetch overlap test did not exercise both JM_PREFETCH_MAX_ACTIVE=2 slots'
Assert-True ([int] $owners.callbacks_started -eq [int] $owners.slots.acquire) 'every acquired slot must reach exactly one callback'
$ownerPageCount = @($owners.pages.PSObject.Properties).Count
$prefetchMedia = @($fixtureStats.counts.PSObject.Properties | Where-Object { $_.Name -like 'prefetch-media|*' })
Assert-True ($prefetchMedia.Count -gt 0) 'prefetch overlap test observed no actual prefetch media attempt'
$prefetchMediaTotal = 0
foreach ($property in $prefetchMedia) {
    $count = [int] $property.Value
    $prefetchMediaTotal += $count
    Assert-True ($count -le 1) "prefetch fetched the same candidate URL more than once: $($property.Name)=$count"
}
Assert-True ($prefetchMediaTotal -le ($ownerPageCount * 2)) 'prefetch media attempts exceeded one primary + one secondary attempt per owned page'
$prefetchHealthAfter = $null
for ($attempt = 1; $attempt -le 40; $attempt++) {
    $prefetchHealthAfter = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_run_id=$runId"
    $observedScheduled = (Prefetch-Metric $prefetchHealthAfter 'scheduled') - (Prefetch-Metric $prefetchHealthBefore 'scheduled')
    $observedAttempted = (Prefetch-Metric $prefetchHealthAfter 'attempted') - (Prefetch-Metric $prefetchHealthBefore 'attempted')
    if ($observedScheduled -eq [int] $owners.callbacks_started -and $observedAttempted -ge 1) { break }
    Start-Sleep -Milliseconds 50
}
$scheduledDelta = (Prefetch-Metric $prefetchHealthAfter 'scheduled') - (Prefetch-Metric $prefetchHealthBefore 'scheduled')
$attemptedDelta = (Prefetch-Metric $prefetchHealthAfter 'attempted') - (Prefetch-Metric $prefetchHealthBefore 'attempted')
$cacheHitDelta = (Prefetch-Metric $prefetchHealthAfter 'cache_hits') - (Prefetch-Metric $prefetchHealthBefore 'cache_hits')
$storedDelta = (Prefetch-Metric $prefetchHealthAfter 'stored') - (Prefetch-Metric $prefetchHealthBefore 'stored')
$byteDelta = (Prefetch-Metric $prefetchHealthAfter 'bytes') - (Prefetch-Metric $prefetchHealthBefore 'bytes')
$wallDelta = (Prefetch-Metric $prefetchHealthAfter 'wall_ms') - (Prefetch-Metric $prefetchHealthBefore 'wall_ms')
Assert-True ($scheduledDelta -eq [int] $owners.callbacks_started) 'scheduled stats do not match directly observed callbacks'
Assert-True ($attemptedDelta -ge 1 -and $attemptedDelta -le $ownerPageCount) 'attempted stats are inconsistent with owned candidate pages'
Assert-True ($cacheHitDelta + $storedDelta -le $attemptedDelta) 'cache_hits + stored exceeded attempted during overlap'
Assert-True ($byteDelta -gt 0 -and $byteDelta -le ([int] $owners.callbacks_started * 16777216)) 'prefetch byte stats exceeded the configured per-callback page-boundary budget'
Assert-True ($wallDelta -ge 0 -and $wallDelta -le ([int] $owners.callbacks_started * 6000)) 'prefetch callback wall stats exceeded budget tolerance'

# prefetch=0 must not create any current-window, page3+, next-chapter, page
# lease, or slot background work on any fixture CDN host.
$runId = New-RunId
Reset-Fixture $runId
$disabledHealthBefore = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_run_id=$runId"
$disabled = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&page=1&next_chapter=350235&prefetch=0' 'prefetch-slow' $runId)
Assert-True ($disabled.Status -eq 200) 'prefetch=0 image request failed'
Start-Sleep -Seconds 1
$fixtureStats = Get-FixtureStats $runId
$counts = $fixtureStats.counts
$owners = $fixtureStats.prefetch_owners
Assert-True (@($owners.pages.PSObject.Properties).Count -eq 0) 'prefetch=0 acquired a page lease'
Assert-True ([int] $owners.slots.acquire -eq 0 -and [int] $owners.slots.release -eq 0 -and [int] $owners.slots.current -eq 0 -and [int] $owners.slots.peak -eq 0) 'prefetch=0 performed slot background work'
$disabledHealthAfter = Invoke-RestMethod -Uri "$BaseUrl/?health=1&test_run_id=$runId"
foreach ($metric in @('scheduled', 'attempted', 'cache_hits', 'stored', 'bytes', 'wall_ms')) {
    Assert-True ((Prefetch-Metric $disabledHealthAfter $metric) - (Prefetch-Metric $disabledHealthBefore $metric) -eq 0) "prefetch=0 changed background metric ${metric}"
}
foreach ($property in $counts.PSObject.Properties) {
    if ($property.Name -match '^cdn-[^|]+\|/media/photos/(?<photo>\d+)/(?<file>\d+)\.png\|\|prefetch-slow$') {
        $isDirectPage = $Matches.photo -eq '350234' -and $Matches.file -eq '00001'
        Assert-True $isDirectPage "prefetch=0 performed background image work on a fixture host: $($property.Name)"
    }
}

# CDN failover must try failing primary once and good secondary once.
$runId = New-RunId
Reset-Fixture $runId
$cdn = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&page=1&prefetch=0' 'cdn-502' $runId)
Assert-True ($cdn.Status -eq 200) 'CDN failover request failed'
$counts = Get-FixtureCounts $runId
Assert-True ((Count-Key $counts 'cdn-fail|/media/photos/350234/00001.png||cdn-502') -eq 1) 'failing CDN must be attempted exactly once'
Assert-True ((Count-Key $counts 'cdn-good|/media/photos/350234/00001.png||cdn-502') -eq 1) 'good CDN must be attempted exactly once'

# Untrusted forwarded headers must not control the effective client IP.
$runId = New-RunId
Reset-Fixture $runId
$proxy = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'valid-list-80' $runId) 'GET' @{ 'X-Forwarded-For' = '203.0.113.99' }
Assert-True ($proxy.Status -eq 200) 'proxy spoof test request failed'
$effectiveIp = Header-Value $proxy.Headers 'X-JM-Test-Client-Ip'
Assert-True (-not [string]::IsNullOrWhiteSpace($effectiveIp)) 'test client IP diagnostic is missing'
$parsedIp = $null
Assert-True ([System.Net.IPAddress]::TryParse($effectiveIp, [ref] $parsedIp)) 'test client IP diagnostic is not a valid IP address'
Assert-True ($effectiveIp -ne '203.0.113.99') 'untrusted X-Forwarded-For controlled effective client IP'

# Trusted proxy positive path: test mode allows loopback proxy CIDR and then accepts forwarded client IP.
$trusted = Invoke-CapturedRequest (Api-Url 'list=latest&page=2&format=min&test_trusted_proxy=1' 'valid-list-80' $runId) 'GET' @{ 'X-Forwarded-For' = '198.51.100.7' }
Assert-True ($trusted.Status -eq 200) 'trusted proxy positive test failed'
Assert-True ((Header-Value $trusted.Headers 'X-JM-Test-Client-Ip') -eq '198.51.100.7') 'trusted proxy did not accept forwarded client IP'

Write-Output 'Fault-injection runtime verification passed.'
