param(
    [string] $PythonPath = 'C:\Users\MZB\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe',
    [string] $PhpPath = 'D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$proxyPath = Join-Path $PSScriptRoot 'fixtures\transparent_https_proxy.py'
$fixturePath = Join-Path $PSScriptRoot 'fixtures\upstream-router.php'
$probePath = Join-Path $PSScriptRoot 'fixtures\transparent_https_probe.php'

function Assert-True {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
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

function Stop-TestProcess {
    param($Process)
    if ($null -eq $Process) { return }
    try {
        if (-not $Process.HasExited) {
            Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
            $Process.WaitForExit(5000) | Out-Null
        }
    } catch {} finally {
        $Process.Dispose()
    }
}

function Remove-ControlledTempTree {
    param([string] $Path)
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) { return }
    $tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
    $resolved = [System.IO.Path]::GetFullPath((Resolve-Path -LiteralPath $Path).ProviderPath)
    $leaf = Split-Path -Leaf $resolved
    if (-not $resolved.StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase) -or
        $leaf -notmatch '^jm-transparent-https-proxy-test-[0-9a-f]{32}$'
    ) {
        throw "Refusing to delete uncontrolled proxy test path: $resolved"
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}

function Wait-JsonFile {
    param([string] $Path, $Process, [int] $Attempts = 100)
    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        if ($null -ne $Process -and $Process.HasExited) {
            throw "Process $($Process.Id) exited before state became ready."
        }
        if (Test-Path -LiteralPath $Path -PathType Leaf) {
            try {
                return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
            } catch {}
        }
        Start-Sleep -Milliseconds 100
    }
    throw "Timed out waiting for JSON state: $Path"
}

function Wait-Fixture {
    param([string] $Url, $Process)
    for ($attempt = 1; $attempt -le 100; $attempt++) {
        if ($Process.HasExited) { throw 'Fixture process exited before becoming ready.' }
        try {
            $response = Invoke-RestMethod -Method Get -Uri "$Url/__stats?run_id=ready" -TimeoutSec 2
            if ($response.ok -eq $true) { return }
        } catch {}
        Start-Sleep -Milliseconds 100
    }
    throw 'Fixture did not become ready.'
}

function Invoke-VerifiedProxyGet {
    param(
        [string] $Url,
        [string] $RunId,
        [string] $ProxyUrl,
        [string] $CaPath,
        [string[]] $PhpBaseArgs,
        [string] $ProbePath
    )
    $names = @('JM_PROXY_TEST_URL', 'JM_PROXY_TEST_RUN_ID', 'https_proxy', 'HTTPS_PROXY', 'CURL_CA_BUNDLE', 'SSL_CERT_FILE')
    $saved = @{}
    foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
    try {
        $env:JM_PROXY_TEST_URL = $Url
        $env:JM_PROXY_TEST_RUN_ID = $RunId
        $env:https_proxy = $ProxyUrl
        $env:HTTPS_PROXY = $ProxyUrl
        $env:CURL_CA_BUNDLE = $CaPath
        $env:SSL_CERT_FILE = $CaPath
        $output = @(& $PhpPath @PhpBaseArgs `
            '-d' "curl.cainfo=$CaPath" '-d' "openssl.cafile=$CaPath" `
            '-f' $ProbePath 2>&1)
        $exitCode = $LASTEXITCODE
        if ($exitCode -ne 0) {
            throw "Verified proxy GET failed with exit $exitCode`: $($output -join ' ')"
        }
        $encoded = [string] ($output | Select-Object -Last 1)
        return [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($encoded.Trim()))
    } finally {
        foreach ($name in $names) {
            [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process')
        }
    }
}

function Invoke-ForbiddenConnect {
    param([int] $Port)
    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $client.ReceiveTimeout = 3000
        $client.SendTimeout = 3000
        $client.Connect('127.0.0.1', $Port)
        $stream = $client.GetStream()
        $request = [System.Text.Encoding]::ASCII.GetBytes("CONNECT example.com:443 HTTP/1.1`r`nHost: example.com:443`r`n`r`n")
        $stream.Write($request, 0, $request.Length)
        $buffer = New-Object byte[] 4096
        $read = $stream.Read($buffer, 0, $buffer.Length)
        return [System.Text.Encoding]::ASCII.GetString($buffer, 0, $read)
    } finally {
        $client.Dispose()
    }
}

function ConvertFrom-EncryptedDomainConfig {
    param([string] $CipherText)
    $cipherBytes = [Convert]::FromBase64String($CipherText.Trim())
    $md5 = [System.Security.Cryptography.MD5]::Create()
    try {
        $secretHash = $md5.ComputeHash([System.Text.Encoding]::UTF8.GetBytes('diosfjckwpqpdfjkvnqQjsik'))
    } finally {
        $md5.Dispose()
    }
    $hexKey = -join ($secretHash | ForEach-Object { $_.ToString('x2') })
    $aes = [System.Security.Cryptography.Aes]::Create()
    try {
        $aes.Mode = [System.Security.Cryptography.CipherMode]::ECB
        $aes.Padding = [System.Security.Cryptography.PaddingMode]::None
        $aes.Key = [System.Text.Encoding]::ASCII.GetBytes($hexKey)
        $decryptor = $aes.CreateDecryptor()
        try {
            $plainPadded = $decryptor.TransformFinalBlock($cipherBytes, 0, $cipherBytes.Length)
        } finally {
            $decryptor.Dispose()
        }
    } finally {
        $aes.Dispose()
    }
    $padding = [int] $plainPadded[$plainPadded.Length - 1]
    Assert-True ($padding -ge 1 -and $padding -le 16 -and $padding -le $plainPadded.Length) 'Config fixture returned invalid PKCS7 padding.'
    $plain = [System.Text.Encoding]::UTF8.GetString($plainPadded, 0, $plainPadded.Length - $padding)
    return $plain | ConvertFrom-Json
}

if (-not (Test-Path -LiteralPath $proxyPath -PathType Leaf)) {
    throw "Transparent HTTPS proxy implementation not found: $proxyPath"
}
if (-not (Test-Path -LiteralPath $fixturePath -PathType Leaf)) { throw "Fixture not found: $fixturePath" }
if (-not (Test-Path -LiteralPath $probePath -PathType Leaf)) { throw "Proxy probe not found: $probePath" }
if (-not (Test-Path -LiteralPath $PythonPath -PathType Leaf)) { throw "Python not found: $PythonPath" }
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP not found: $PhpPath" }

$token = [guid]::NewGuid().ToString('N')
$tempTree = Join-Path ([System.IO.Path]::GetTempPath()) "jm-transparent-https-proxy-test-$token"
$proxyWork = Join-Path $tempTree 'proxy'
$invalidWork = Join-Path $tempTree 'invalid'
$statsDirectory = Join-Path $tempTree 'fixture-stats'
$statePath = Join-Path $proxyWork 'state.json'
$fixtureOut = Join-Path $tempTree 'fixture.out.log'
$fixtureErr = Join-Path $tempTree 'fixture.err.log'
$proxyOut = Join-Path $tempTree 'proxy.out.log'
$proxyErr = Join-Path $tempTree 'proxy.err.log'
$fixtureProcess = $null
$proxyProcess = $null
$caught = $null
$caThumbprint = $null

New-Item -ItemType Directory -Path $proxyWork, $invalidWork, $statsDirectory -Force | Out-Null
$fixturePort = Get-FreeLoopbackPort
do { $proxyPort = Get-FreeLoopbackPort } while ($proxyPort -eq $fixturePort)
$fixtureUrl = "http://127.0.0.1:$fixturePort"
$proxyUrl = "http://127.0.0.1:$proxyPort"
$phpRoot = Split-Path -Parent (Resolve-Path -LiteralPath $PhpPath).Path
$phpBaseArgs = @(
    '-n',
    '-d', "extension_dir=$(Join-Path $phpRoot 'ext')",
    '-d', 'extension=php_curl.dll',
    '-d', 'extension=php_openssl.dll'
)
$savedStatsDir = [Environment]::GetEnvironmentVariable('JM_FIXTURE_STATS_DIR', 'Process')

try {
    $invalidState = Join-Path $invalidWork 'state.json'
    $invalidOut = Join-Path $invalidWork 'stdout.log'
    $invalidErr = Join-Path $invalidWork 'stderr.log'
    $invalidProcess = Start-Process -FilePath $PythonPath `
        -ArgumentList @(
            $proxyPath,
            '--listen-host', '0.0.0.0', '--listen-port', '18443',
            '--upstream-host', '127.0.0.1', '--upstream-port', '18090',
            '--work-dir', $invalidWork, '--state-file', $invalidState, '--validate-only'
        ) `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru -Wait `
        -RedirectStandardOutput $invalidOut -RedirectStandardError $invalidErr
    try {
        Assert-True ($invalidProcess.ExitCode -ne 0) 'Proxy accepted a non-loopback listen host.'
    } finally {
        $invalidProcess.Dispose()
    }
    Assert-True (-not (Test-Path -LiteralPath $invalidState)) 'Invalid proxy configuration wrote a ready state.'

    $invalidUpstreamState = Join-Path $invalidWork 'upstream-state.json'
    $invalidUpstreamProcess = Start-Process -FilePath $PythonPath `
        -ArgumentList @(
            $proxyPath,
            '--listen-host', '127.0.0.1', '--listen-port', '18443',
            '--upstream-host', '0.0.0.0', '--upstream-port', '18090',
            '--work-dir', $invalidWork, '--state-file', $invalidUpstreamState, '--validate-only'
        ) `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru -Wait `
        -RedirectStandardOutput (Join-Path $invalidWork 'upstream-stdout.log') `
        -RedirectStandardError (Join-Path $invalidWork 'upstream-stderr.log')
    try {
        Assert-True ($invalidUpstreamProcess.ExitCode -ne 0) 'Proxy accepted a non-loopback upstream host.'
    } finally {
        $invalidUpstreamProcess.Dispose()
    }
    Assert-True (-not (Test-Path -LiteralPath $invalidUpstreamState)) 'Invalid upstream configuration wrote a ready state.'

    $env:JM_FIXTURE_STATS_DIR = $statsDirectory
    $fixtureProcess = Start-Process -FilePath $PhpPath `
        -ArgumentList ($phpBaseArgs + @('-S', "127.0.0.1:$fixturePort", $fixturePath)) `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $fixtureOut -RedirectStandardError $fixtureErr
    Wait-Fixture -Url $fixtureUrl -Process $fixtureProcess

    $proxyProcess = Start-Process -FilePath $PythonPath `
        -ArgumentList @(
            $proxyPath,
            '--listen-host', '127.0.0.1', '--listen-port', [string] $proxyPort,
            '--upstream-host', '127.0.0.1', '--upstream-port', [string] $fixturePort,
            '--work-dir', $proxyWork, '--state-file', $statePath
        ) `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $proxyOut -RedirectStandardError $proxyErr
    $state = Wait-JsonFile -Path $statePath -Process $proxyProcess
    Assert-True ($state.ready -eq $true) 'Proxy state did not declare ready=true.'
    Assert-True ([int] $state.pid -eq $proxyProcess.Id) 'Proxy state PID did not match the started process.'
    Assert-True ($state.listen_host -eq '127.0.0.1' -and [int] $state.listen_port -eq $proxyPort) 'Proxy state listen address mismatch.'
    Assert-True ($state.upstream_host -eq '127.0.0.1' -and [int] $state.upstream_port -eq $fixturePort) 'Proxy state upstream address mismatch.'
    Assert-True (Test-Path -LiteralPath $state.ca_cert_path -PathType Leaf) 'Proxy state CA certificate is missing.'

    $certificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($state.ca_cert_path)
    try { $caThumbprint = $certificate.Thumbprint } finally { $certificate.Dispose() }
    Assert-True (-not (Test-Path -LiteralPath "Cert:\CurrentUser\Root\$caThumbprint")) 'Temporary CA was installed in CurrentUser Root.'
    Assert-True (-not (Test-Path -LiteralPath "Cert:\LocalMachine\Root\$caThumbprint")) 'Temporary CA was installed in LocalMachine Root.'

    Invoke-RestMethod -Method Get -Uri "$fixtureUrl/__reset?run_id=$token" -TimeoutSec 3 | Out-Null
    $apiBody = Invoke-VerifiedProxyGet `
        -Url 'https://www.cdnhjk.net/latest?page=1' -RunId $token `
        -ProxyUrl $proxyUrl -CaPath $state.ca_cert_path -PhpBaseArgs $phpBaseArgs -ProbePath $probePath
    Assert-True (-not [string]::IsNullOrWhiteSpace($apiBody)) 'Allowed API request returned an empty body.'

    $configBody = Invoke-VerifiedProxyGet `
        -Url 'https://rup4a04-c01.tos-ap-southeast-1.bytepluses.com/newsvr-2025.txt' -RunId $token `
        -ProxyUrl $proxyUrl -CaPath $state.ca_cert_path -PhpBaseArgs $phpBaseArgs -ProbePath $probePath
    $config = ConvertFrom-EncryptedDomainConfig $configBody
    Assert-True (@($config.Server) -contains 'www.cdnhjk.net') 'Config fixture did not return the fixed encrypted API domain list.'

    $forbiddenResponse = Invoke-ForbiddenConnect -Port $proxyPort
    Assert-True ($forbiddenResponse -match '^HTTP/1\.[01] 403\b') 'Non-whitelisted CONNECT was not rejected with HTTP 403.'

    $stats = Invoke-RestMethod -Method Get -Uri "$fixtureUrl/__stats?run_id=$token" -TimeoutSec 3
    $countNames = @($stats.counts.PSObject.Properties | ForEach-Object { $_.Name })
    Assert-True (@($countNames | Where-Object { $_ -eq 'www.cdnhjk.net|/latest|1|valid' }).Count -eq 1) 'Fixture did not observe the original allowed API Host/path.'
    Assert-True (@($countNames | Where-Object { $_ -eq 'rup4a04-c01.tos-ap-southeast-1.bytepluses.com|/newsvr-2025.txt||valid' }).Count -eq 1) 'Fixture did not observe the original config Host/path.'
    Assert-True (@($countNames | Where-Object { $_ -match 'example\.com' }).Count -eq 0) 'Rejected host reached the fixture.'
} catch {
    $caught = $_
    foreach ($log in @($proxyErr, $fixtureErr)) {
        if (Test-Path -LiteralPath $log) {
            Write-Warning ("{0}: {1}" -f $log, ((Get-Content -LiteralPath $log -Tail 20) -join "`n"))
        }
    }
} finally {
    Stop-TestProcess $proxyProcess
    Stop-TestProcess $fixtureProcess
    [Environment]::SetEnvironmentVariable('JM_FIXTURE_STATS_DIR', $savedStatsDir, 'Process')
    if ($null -ne $caThumbprint) {
        if (Test-Path -LiteralPath "Cert:\CurrentUser\Root\$caThumbprint") {
            $caught = [System.Management.Automation.ErrorRecord]::new(
                [System.InvalidOperationException]::new('Temporary CA remained in CurrentUser Root after proxy exit.'),
                'TemporaryCaInstalled',
                [System.Management.Automation.ErrorCategory]::SecurityError,
                $caThumbprint
            )
        }
        if (Test-Path -LiteralPath "Cert:\LocalMachine\Root\$caThumbprint") {
            $caught = [System.Management.Automation.ErrorRecord]::new(
                [System.InvalidOperationException]::new('Temporary CA remained in LocalMachine Root after proxy exit.'),
                'TemporaryCaInstalledMachine',
                [System.Management.Automation.ErrorCategory]::SecurityError,
                $caThumbprint
            )
        }
    }
    try { Remove-ControlledTempTree $tempTree } catch { if ($null -eq $caught) { $caught = $_ } }
}

Assert-True (-not (Test-Path -LiteralPath $tempTree)) 'Proxy runtime temporary directory remained after cleanup.'
if ($null -ne $caught) { throw $caught }

Write-Output 'Transparent HTTPS proxy runtime passed: loopback enforcement, verified TLS/Host forwarding, strict rejection, encrypted config, CA isolation, and cleanup.'
