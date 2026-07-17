param(
    [string] $PhpPath = '',
    [string] $RedisExtension = '',
    [string] $ApcuExtension = '',
    [string] $RedisServerPath = '',
    [string] $RedisCliPath = '',
    [ValidateRange(2, 64)] [int] $WorkerCount = 16,
    [ValidateRange(1, 63)] [int] $MaxRequests = 5,
    [ValidateRange(2, 60)] [int] $WindowSeconds = 4,
    [ValidateRange(2, 20)] [int] $BreakerSeconds = 5
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$toolsRoot = Join-Path (Split-Path -Parent $root) '.tools'
$runtimePath = Join-Path $PSScriptRoot 'redis-rate-limit-runtime.php'
$composePath = Join-Path $root 'docker-compose.test.yml'

function Assert-True { param([bool] $Condition, [string] $Message); if (-not $Condition) { throw $Message } }

function Resolve-RequiredFile {
    param([string] $ExplicitPath, [string[]] $Candidates, [string] $Label)
    $paths = @()
    if (-not [string]::IsNullOrWhiteSpace($ExplicitPath)) { $paths += $ExplicitPath }
    $paths += $Candidates
    foreach ($path in $paths) {
        if (-not [string]::IsNullOrWhiteSpace($path) -and (Test-Path -LiteralPath $path -PathType Leaf)) {
            return (Resolve-Path -LiteralPath $path).Path
        }
    }
    throw "$Label was not found. Supply the explicit path; this gate never substitutes a fake Redis."
}

function Get-FreeTcpPort {
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
    try {
        $listener.Start()
        return ([Net.IPEndPoint] $listener.LocalEndpoint).Port
    } finally {
        $listener.Stop()
    }
}

function Resolve-ControlledTempDirectory {
    param([string] $Path, [string] $Token)
    Assert-True ($Token -cmatch '^[a-f0-9]{32}$') 'Redis gate temp token is invalid.'
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\', '/')
    $temp = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\', '/')
    $parent = [IO.Path]::GetDirectoryName($full).TrimEnd('\', '/')
    $leaf = [IO.Path]::GetFileName($full)
    Assert-True ([StringComparer]::OrdinalIgnoreCase.Equals($parent, $temp)) "Refusing uncontrolled Redis gate temp parent: $full"
    Assert-True ($leaf -ceq "jm-redis-rate-$Token") "Refusing uncontrolled Redis gate temp leaf: $full"
    return $full
}

function Invoke-GatePhp {
    param([string[]] $Arguments)
    $output = @(& $script:Php @script:PhpArgs $script:Runtime @Arguments 2>&1)
    $exit = $LASTEXITCODE
    if ($exit -ne 0) { throw "Redis gate PHP command failed (exit $exit): $($output -join [Environment]::NewLine)" }
    $jsonLine = @($output | Where-Object { $_ -is [string] -and -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -Last 1)
    Assert-True ($jsonLine.Count -eq 1) 'Redis gate PHP command did not emit JSON.'
    return ($jsonLine[0] | ConvertFrom-Json)
}

function Wait-RedisReady {
    param([Diagnostics.Process] $Process)
    $watch = [Diagnostics.Stopwatch]::StartNew()
    while ($watch.ElapsedMilliseconds -lt 10000) {
        if ($Process.HasExited) {
            $errorText = if (Test-Path -LiteralPath $script:RedisErrorLog) { Get-Content -Raw -LiteralPath $script:RedisErrorLog } else { '' }
            throw "Redis exited before readiness (code $($Process.ExitCode)): $errorText"
        }
        $reply = @(& $script:RedisCli -h 127.0.0.1 -p $script:RedisPort ping 2>$null)
        if ($LASTEXITCODE -eq 0 -and (($reply -join '').Trim() -ceq 'PONG')) { return }
        Start-Sleep -Milliseconds 50
    }
    throw "Redis did not become ready on 127.0.0.1:$($script:RedisPort)."
}

function Start-TestRedis {
    $process = Start-Process -FilePath $script:RedisServer -ArgumentList @($script:RedisConfig) `
        -WorkingDirectory $script:TempDirectory -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $script:RedisOutputLog -RedirectStandardError $script:RedisErrorLog
    Wait-RedisReady $process
    return $process
}

function Get-OwnedRedisProcesses {
    if ([string]::IsNullOrWhiteSpace($script:RedisConfig)) { return @() }
    $configPath = [IO.Path]::GetFullPath($script:RedisConfig)
    return @(Get-CimInstance Win32_Process -Filter "Name='redis-server.exe'" -ErrorAction SilentlyContinue | Where-Object {
        -not [string]::IsNullOrWhiteSpace([string] $_.CommandLine) -and
        ([string] $_.CommandLine).IndexOf($configPath, [StringComparison]::OrdinalIgnoreCase) -ge 0
    })
}

function Wait-OwnedRedisExit {
    param([ValidateRange(100, 30000)] [int] $TimeoutMs = 5000)
    $watch = [Diagnostics.Stopwatch]::StartNew()
    while ($watch.ElapsedMilliseconds -lt $TimeoutMs) {
        if (@(Get-OwnedRedisProcesses).Count -eq 0) { return $true }
        Start-Sleep -Milliseconds 50
    }
    return (@(Get-OwnedRedisProcesses).Count -eq 0)
}

function Stop-TestRedis {
    param([Diagnostics.Process] $Process, [switch] $Cleanup)
    if ($null -eq $Process) { return }
    try {
        if (-not $Process.HasExited) {
            & $script:RedisCli -h 127.0.0.1 -p $script:RedisPort shutdown nosave 2>$null | Out-Null
            $Process.WaitForExit(5000) | Out-Null
        }
        if (-not $Process.HasExited) {
            if (-not $Cleanup) { throw 'Redis did not stop gracefully within 5 seconds.' }
            Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
            $Process.WaitForExit(5000) | Out-Null
        }
        if (-not (Wait-OwnedRedisExit 5000)) {
            $owned = @(Get-OwnedRedisProcesses)
            if (-not $Cleanup) {
                throw "Redis child processes did not stop gracefully: $(@($owned | Select-Object -ExpandProperty ProcessId) -join ',')"
            }
            foreach ($ownedProcess in $owned) {
                Stop-Process -Id ([int] $ownedProcess.ProcessId) -Force -ErrorAction SilentlyContinue
            }
            Assert-True (Wait-OwnedRedisExit 5000) 'Redis cleanup could not terminate owned child processes.'
        }
    } finally {
        $Process.Dispose()
    }
}

function Wait-HttpReady {
    param([Diagnostics.Process] $Process)
    for ($attempt = 1; $attempt -le 100; $attempt++) {
        if ($Process.HasExited) {
            $errorText = if (Test-Path -LiteralPath $script:ControllerErrorLog) { Get-Content -Raw -LiteralPath $script:ControllerErrorLog } else { '' }
            throw "Redis gate controller exited before readiness: $errorText"
        }
        try {
            $health = Invoke-RestMethod -UseBasicParsing -Uri "$($script:ControllerUrl)/?action=health" -TimeoutSec 2
            Assert-True ($health.ok -eq $true) 'Redis gate controller health was not true.'
            Assert-True ($health.apcu -eq $true) 'Redis gate controller did not load APCu.'
            return
        } catch {
            if ($attempt -eq 100) { throw }
            Start-Sleep -Milliseconds 50
        }
    }
}

function Invoke-ControllerRate {
    param([string] $Key, [int] $Maximum, [int] $Window = 30)
    return Invoke-RestMethod -UseBasicParsing -Uri "$($script:ControllerUrl)/?action=check&key=$Key&window=$Window&max=$Maximum" -TimeoutSec 5
}

function Wait-WorkerProcesses {
    param([object[]] $Workers, [int] $TimeoutMs)
    $watch = [Diagnostics.Stopwatch]::StartNew()
    foreach ($worker in $Workers) {
        $remaining = [Math]::Max(1, $TimeoutMs - [int] $watch.ElapsedMilliseconds)
        Assert-True ($worker.Process.WaitForExit($remaining)) "Redis worker $($worker.Index) timed out."
        $worker.Process.WaitForExit()
    }
}

Assert-True (Test-Path -LiteralPath $runtimePath -PathType Leaf) 'Redis runtime PHP script is missing.'
Assert-True ($WorkerCount -gt $MaxRequests) 'WorkerCount must exceed MaxRequests.'
Assert-True (Test-Path -LiteralPath $composePath -PathType Leaf) 'docker-compose.test.yml is missing.'
$composeContract = Get-Content -Raw -Encoding UTF8 -LiteralPath $composePath
foreach ($snippet in @(
    '  jm-redis:',
    'image: redis:8.8.0-alpine@sha256:9d317178eceac8454a2284a9e6df2466b93c745529947f0cd42a0fa9609d7005',
    'REDIS_HOST: "jm-redis"',
    'REDIS_PORT: "6379"',
    'condition: service_healthy',
    '127.0.0.1:${JM_TEST_REDIS_PORT:-6397}:6379',
    'redis-cli',
    '/data:rw,noexec,nosuid,size=64m'
)) {
    Assert-True ($composeContract.Contains($snippet)) "Redis test Compose contract omitted: $snippet"
}
Assert-True (
    $composeContract -match '(?s)  jm-redis:.*?--protected-mode\s*\r?\n\s*-\s*"no"'
) 'Redis test service must accept isolated Docker-network clients instead of protected-mode loopback only.'

$phpCandidates = @(Join-Path $toolsRoot 'php-8.3.32-nts-Win32-vs16-x64\php.exe')
$redisExtensionCandidates = @(
    (Join-Path $toolsRoot 'php_redis-6.3.0-8.3-nts-vs16-x64\php_redis.dll'),
    (Join-Path $toolsRoot 'php_redis.dll')
)
$apcuCandidates = @(
    (Join-Path $toolsRoot 'php_apcu-5.1.28-8.3-nts-vs16-x64\php_apcu.dll'),
    (Join-Path $toolsRoot 'php_apcu-5.1.28-8.3-nts-vs16-x64\php_apcu.dll')
)
$serverCandidates = @(
    (Join-Path $toolsRoot 'redis-windows-3.2.100\redis-server.exe'),
    (Join-Path $toolsRoot 'redis-windows-5.0.14.1\redis-server.exe')
)
$cliCandidates = @(
    (Join-Path $toolsRoot 'redis-windows-3.2.100\redis-cli.exe'),
    (Join-Path $toolsRoot 'redis-windows-5.0.14.1\redis-cli.exe')
)

$command = Get-Command redis-server -ErrorAction SilentlyContinue
if ($null -ne $command) { $serverCandidates += $command.Source }
$command = Get-Command redis-cli -ErrorAction SilentlyContinue
if ($null -ne $command) { $cliCandidates += $command.Source }

$script:Php = Resolve-RequiredFile $PhpPath $phpCandidates 'PHP executable'
$script:RedisExtensionPath = Resolve-RequiredFile $RedisExtension $redisExtensionCandidates 'phpredis extension'
$script:ApcuExtensionPath = Resolve-RequiredFile $ApcuExtension $apcuCandidates 'APCu extension'
$script:RedisServer = Resolve-RequiredFile $RedisServerPath $serverCandidates 'Redis server executable'
$script:RedisCli = Resolve-RequiredFile $RedisCliPath $cliCandidates 'Redis CLI executable'
$script:Runtime = (Resolve-Path -LiteralPath $runtimePath).Path

$phpRoot = Split-Path -Parent $script:Php
$script:PhpArgs = @(
    '-n',
    '-d', "extension_dir=$(Join-Path $phpRoot 'ext')",
    '-d', "extension=$($script:RedisExtensionPath)",
    '-d', "extension=$($script:ApcuExtensionPath)",
    '-d', 'apc.enable_cli=1'
)

$moduleEvidence = @(& $script:Php @script:PhpArgs -r 'echo (class_exists(Redis::class,false) && class_exists(APCUIterator::class,false)) ? 1 : 0;' 2>&1)
Assert-True ($LASTEXITCODE -eq 0 -and (($moduleEvidence -join '').Trim() -ceq '1')) "PHP could not load phpredis/APCu: $($moduleEvidence -join ' ')"

$token = [guid]::NewGuid().ToString('N')
$script:TempDirectory = Resolve-ControlledTempDirectory (Join-Path $env:TEMP "jm-redis-rate-$token") $token
New-Item -ItemType Directory -Path $script:TempDirectory -ErrorAction Stop | Out-Null
$workerDirectory = Join-Path $script:TempDirectory 'workers'
$dataDirectory = Join-Path $script:TempDirectory 'data'
New-Item -ItemType Directory -Path $workerDirectory, $dataDirectory -ErrorAction Stop | Out-Null

$script:RedisPort = Get-FreeTcpPort
$controllerPort = Get-FreeTcpPort
Assert-True ($script:RedisPort -ne $controllerPort) 'Redis and controller ports unexpectedly collided.'
$script:ControllerUrl = "http://127.0.0.1:$controllerPort"
$script:RedisConfig = Join-Path $script:TempDirectory 'redis.conf'
$script:RedisOutputLog = Join-Path $script:TempDirectory 'redis.out.log'
$script:RedisErrorLog = Join-Path $script:TempDirectory 'redis.err.log'
$redisLog = (Join-Path $script:TempDirectory 'redis.log').Replace('\', '/')
$redisDir = $dataDirectory.Replace('\', '/')
$config = @(
    'bind 127.0.0.1',
    "port $($script:RedisPort)",
    'timeout 0',
    'tcp-keepalive 60',
    'loglevel notice',
    "logfile `"$redisLog`"",
    'databases 1',
    'save ""',
    'appendonly no',
    "dir `"$redisDir`"",
    'protected-mode yes'
) -join "`r`n"
[IO.File]::WriteAllText($script:RedisConfig, $config + "`r`n", (New-Object Text.UTF8Encoding($false)))

$script:ControllerOutputLog = Join-Path $script:TempDirectory 'controller.out.log'
$script:ControllerErrorLog = Join-Path $script:TempDirectory 'controller.err.log'
$prefix = "jm-real-$($token):"
$physicalConcurrencyKey = "${prefix}rate:concurrent"
$unprefixedConcurrencyKey = 'rate:concurrent'
$physicalBreakerKey = "${prefix}rate:breaker"
$physicalRollbackKey = "${prefix}rate:rollback"
$workers = @()
$redisProcess = $null
$controllerProcess = $null
$savedEnvironment = @{}
$successSummary = ''
foreach ($name in @('REDIS_HOST', 'REDIS_PORT', 'REDIS_TIMEOUT_MS', 'REDIS_BREAKER_TTL_SECONDS', 'REDIS_TEST_PREFIX')) {
    $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

try {
    $redisProcess = Start-TestRedis
    $serverInfo = Invoke-GatePhp @('ping', '127.0.0.1', "$($script:RedisPort)")
    Assert-True ($serverInfo.ok -eq $true) 'Real Redis PING failed.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $serverInfo.redis_version)) 'Real Redis version was unavailable.'

    Invoke-GatePhp @('reset', '127.0.0.1', "$($script:RedisPort)") | Out-Null
    $releasePath = Join-Path $workerDirectory 'release.signal'
    for ($index = 1; $index -le $WorkerCount; $index++) {
        $readyPath = Join-Path $workerDirectory ('ready-{0:D2}.signal' -f $index)
        $outputPath = Join-Path $workerDirectory ('worker-{0:D2}.out' -f $index)
        $errorPath = Join-Path $workerDirectory ('worker-{0:D2}.err' -f $index)
        $arguments = $script:PhpArgs + @(
            $script:Runtime, 'worker', '127.0.0.1', "$($script:RedisPort)", $prefix, 'concurrent',
            "$WindowSeconds", "$MaxRequests", $readyPath, $releasePath, $token, "$index"
        )
        $process = Start-Process -FilePath $script:Php -ArgumentList $arguments -WorkingDirectory $root `
            -WindowStyle Hidden -PassThru -RedirectStandardOutput $outputPath -RedirectStandardError $errorPath
        $workers += [pscustomobject]@{ Index = $index; Process = $process; Ready = $readyPath; Output = $outputPath; Error = $errorPath }
    }

    $readyWatch = [Diagnostics.Stopwatch]::StartNew()
    while (@($workers | Where-Object { Test-Path -LiteralPath $_.Ready -PathType Leaf }).Count -ne $WorkerCount) {
        $earlyExit = @($workers | Where-Object { $_.Process.HasExited })
        if ($earlyExit.Count -gt 0) {
            $details = @($earlyExit | ForEach-Object { "worker $($_.Index): $(if(Test-Path $_.Error){Get-Content -Raw $_.Error}else{'no stderr'})" }) -join '; '
            throw "Redis workers exited before barrier release: $details"
        }
        if ($readyWatch.ElapsedMilliseconds -ge 30000) { throw 'Timed out waiting for all Redis workers to reach the barrier.' }
        Start-Sleep -Milliseconds 20
    }
    [IO.File]::WriteAllText($releasePath, $token, [Text.Encoding]::ASCII)
    Wait-WorkerProcesses $workers 30000

    $results = @()
    foreach ($worker in $workers) {
        $workerOutput = if (Test-Path -LiteralPath $worker.Output) { Get-Content -Raw -LiteralPath $worker.Output } else { '' }
        $workerError = if (Test-Path -LiteralPath $worker.Error) { Get-Content -Raw -LiteralPath $worker.Error } else { '' }
        Assert-True ($worker.Process.HasExited) "Redis worker $($worker.Index) did not exit."
        Assert-True ([string]::IsNullOrWhiteSpace($workerError)) "Redis worker $($worker.Index) emitted stderr: $workerError"
        Assert-True (-not [string]::IsNullOrWhiteSpace($workerOutput)) "Redis worker $($worker.Index) emitted no result."
        $payload = $workerOutput | ConvertFrom-Json
        Assert-True ($payload.ok -eq $true) "Redis worker $($worker.Index) did not report success."
        $results += $payload
    }
    $allowed = @($results | Where-Object { $_.result[0] -eq $true })
    $rejected = @($results | Where-Object { $_.result[0] -eq $false })
    Assert-True ($allowed.Count -eq $MaxRequests) "Atomic limiter allowed $($allowed.Count), expected exactly $MaxRequests."
    Assert-True ($rejected.Count -eq ($WorkerCount - $MaxRequests)) "Atomic limiter rejected $($rejected.Count), expected $($WorkerCount - $MaxRequests)."
    Assert-True (@($rejected | Where-Object { [int] $_.result[2] -le 0 }).Count -eq 0) 'At least one Redis rejection omitted a positive retry_after.'

    $concurrencyInspect = Invoke-GatePhp @('inspect', '127.0.0.1', "$($script:RedisPort)", $physicalConcurrencyKey, $unprefixedConcurrencyKey)
    Assert-True ($concurrencyInspect.physical_exists -eq 1) 'The prefixed Redis rate key was not created.'
    Assert-True ($concurrencyInspect.physical_count -eq $MaxRequests) "The real Redis sorted set cardinality was $($concurrencyInspect.physical_count), expected $MaxRequests."
    Assert-True ($concurrencyInspect.unprefixed_exists -eq 0) 'Redis EVAL bypassed the configured key prefix.'
    Assert-True ($concurrencyInspect.eval_calls -eq $WorkerCount) "Redis recorded $($concurrencyInspect.eval_calls) EVAL calls, expected one per checkRate call ($WorkerCount)."

    $env:REDIS_HOST = '127.0.0.1'
    $env:REDIS_PORT = "$($script:RedisPort)"
    $env:REDIS_TIMEOUT_MS = '150'
    $env:REDIS_BREAKER_TTL_SECONDS = "$BreakerSeconds"
    $env:REDIS_TEST_PREFIX = $prefix
    $controllerArguments = $script:PhpArgs + @('-S', "127.0.0.1:$controllerPort", $script:Runtime)
    $controllerProcess = Start-Process -FilePath $script:Php -ArgumentList $controllerArguments -WorkingDirectory $root `
        -WindowStyle Hidden -PassThru -RedirectStandardOutput $script:ControllerOutputLog -RedirectStandardError $script:ControllerErrorLog
    Wait-HttpReady $controllerProcess

    Invoke-GatePhp @('reset', '127.0.0.1', "$($script:RedisPort)") | Out-Null
    $futureSeed = Invoke-GatePhp @('seed-future', '127.0.0.1', "$($script:RedisPort)", $physicalRollbackKey, '30', '120')
    Assert-True ($futureSeed.physical_count -eq 30) 'Redis rollback fixture did not seed exactly 30 future scores.'
    $rollback = Invoke-ControllerRate 'rollback' 30 60
    Assert-True ($rollback.result[0] -eq $false) "Clock rollback limiter failed open: $($rollback.result -join ',')"
    Assert-True ([int] $rollback.result[1] -eq 0) "Clock rollback rejection returned nonzero remaining: $($rollback.result -join ',')"
    Assert-True ([int] $rollback.result[2] -ge 1 -and [int] $rollback.result[2] -le 60) "Clock rollback retry escaped 1..window: $($rollback.result -join ',')"
    $rollbackInspect = Invoke-GatePhp @('inspect', '127.0.0.1', "$($script:RedisPort)", $physicalRollbackKey, 'rate:rollback')
    Assert-True ($rollbackInspect.physical_count -eq 30) "Clock rollback limiter over-admitted a 31st member: $($rollbackInspect.physical_count)"

    Invoke-GatePhp @('reset', '127.0.0.1', "$($script:RedisPort)") | Out-Null
    $initial = Invoke-ControllerRate 'breaker' 3
    Assert-True (($initial.result -join ',') -ceq 'True,2,0') "Initial Redis rate check changed: $($initial.result -join ',')"

    Stop-TestRedis $redisProcess
    $redisProcess = $null
    $failedOpen = Invoke-ControllerRate 'breaker' 3
    Assert-True (($failedOpen.result -join ',') -ceq 'True,3,0') "Redis outage did not fail open: $($failedOpen.result -join ',')"
    Assert-True ([int] $failedOpen.elapsed_ms -lt 2000) "Redis outage exceeded the bounded failure path: $($failedOpen.elapsed_ms) ms"

    $restartWatch = [Diagnostics.Stopwatch]::StartNew()
    $redisProcess = Start-TestRedis
    Assert-True ($restartWatch.Elapsed.TotalSeconds -lt $BreakerSeconds) 'Redis restart consumed the complete breaker interval; suppression could not be tested.'
    $suppressed = Invoke-ControllerRate 'breaker' 3
    Assert-True (($suppressed.result -join ',') -ceq 'True,3,0') "Open Redis breaker did not fail open after service recovery: $($suppressed.result -join ',')"
    $suppressedInspect = Invoke-GatePhp @('inspect', '127.0.0.1', "$($script:RedisPort)", $physicalBreakerKey, 'rate:breaker')
    Assert-True ($suppressedInspect.physical_exists -eq 0) 'Open Redis breaker performed a rate mutation after service recovery.'
    Assert-True ($suppressedInspect.eval_calls -eq 0) 'Open Redis breaker issued EVAL after service recovery.'

    Start-Sleep -Milliseconds (($BreakerSeconds * 1000) + 250)
    $recovered = Invoke-ControllerRate 'breaker' 3
    Assert-True (($recovered.result -join ',') -ceq 'True,2,0') "Redis breaker did not recover after TTL: $($recovered.result -join ',')"
    $recoveryInspect = Invoke-GatePhp @('inspect', '127.0.0.1', "$($script:RedisPort)", $physicalBreakerKey, 'rate:breaker')
    Assert-True ($recoveryInspect.physical_exists -eq 1 -and $recoveryInspect.physical_count -eq 1) 'Recovered Redis limiter did not persist exactly one request.'
    Assert-True ($recoveryInspect.eval_calls -eq 1) "Recovered Redis limiter EVAL count changed: $($recoveryInspect.eval_calls)"

    $successSummary = ("Real Redis rate-limit runtime passed: redis={0}, workers={1}, allowed={2}, rejected={3}, eval_calls={4}, rollback=rejected/bounded/no-over-admit, breaker=fail-open/suppressed/recovered." -f `
        $serverInfo.redis_version, $WorkerCount, $allowed.Count, $rejected.Count, $concurrencyInspect.eval_calls)
} finally {
    foreach ($worker in $workers) {
        if ($null -ne $worker.Process) {
            if (-not $worker.Process.HasExited) { Stop-Process -Id $worker.Process.Id -Force -ErrorAction SilentlyContinue }
            try { $worker.Process.WaitForExit(5000) | Out-Null } catch {}
            $worker.Process.Dispose()
        }
    }
    if ($null -ne $controllerProcess) {
        if (-not $controllerProcess.HasExited) { Stop-Process -Id $controllerProcess.Id -Force -ErrorAction SilentlyContinue }
        try { $controllerProcess.WaitForExit(5000) | Out-Null } catch {}
        $controllerProcess.Dispose()
    }
    if ($null -ne $redisProcess) { Stop-TestRedis $redisProcess -Cleanup }
    foreach ($name in $savedEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
    }
    if (Test-Path -LiteralPath $script:TempDirectory -PathType Container) {
        $controlled = Resolve-ControlledTempDirectory $script:TempDirectory $token
        Remove-Item -LiteralPath $controlled -Recurse -Force
    }
}
Assert-True (@(Get-OwnedRedisProcesses).Count -eq 0) 'Redis gate left owned Redis processes after cleanup.'
Assert-True (-not [string]::IsNullOrWhiteSpace($successSummary)) 'Redis gate did not reach its success state.'
Write-Output $successSummary
