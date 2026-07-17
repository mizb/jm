param(
    [string] $PhpPath = 'D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$indexPath = Join-Path $root 'index.php'
$parserProbePath = Join-Path $PSScriptRoot 'input-validation-runtime.php'

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
            try { $Process.WaitForExit(5000) | Out-Null } catch {}
        }
    } finally {
        $Process.Dispose()
    }
}

function Invoke-TestRequest {
    param([string] $Url, [string] $Method = 'GET')
    Add-Type -AssemblyName System.Net.Http
    $handler = [System.Net.Http.HttpClientHandler]::new()
    $handler.UseProxy = $false
    $client = [System.Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromSeconds(15)
    $request = [System.Net.Http.HttpRequestMessage]::new(
        [System.Net.Http.HttpMethod]::new($Method),
        $Url
    )
    try {
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        return [pscustomobject]@{
            Status = [int] $response.StatusCode
            ContentType = [string] $response.Content.Headers.ContentType
            Body = [string] $body
            Allow = [string] ($response.Content.Headers.Allow -join ', ')
        }
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
        $client.Dispose()
        $handler.Dispose()
    }
}

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP not found: $PhpPath" }
if (-not (Test-Path -LiteralPath $indexPath -PathType Leaf)) { throw "API entry point not found: $indexPath" }
if (-not (Test-Path -LiteralPath $parserProbePath -PathType Leaf)) { throw "Parser probe not found: $parserProbePath" }

$parserOutput = @(& $PhpPath '-n' $parserProbePath 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw "JM ID parser probe failed with exit $LASTEXITCODE`: $($parserOutput -join ' ')"
}

$token = [guid]::NewGuid().ToString('N')
$tempTree = Join-Path ([System.IO.Path]::GetTempPath()) "jm-input-validation-runtime-$token"
$stdoutPath = Join-Path $tempTree 'php.out.log'
$stderrPath = Join-Path $tempTree 'php.err.log'
$port = Get-FreeLoopbackPort
$baseUrl = "http://127.0.0.1:$port"
$phpRoot = Split-Path -Parent (Resolve-Path -LiteralPath $PhpPath).Path
$extensionDirectory = Join-Path $phpRoot 'ext'
$process = $null

New-Item -ItemType Directory -Path $tempTree -Force | Out-Null
try {
    $process = Start-Process -FilePath $PhpPath -ArgumentList @(
        '-n',
        '-d', "extension_dir=$extensionDirectory",
        '-d', 'extension=php_curl.dll',
        '-d', 'extension=php_openssl.dll',
        '-d', 'extension=php_mbstring.dll',
        '-S', "127.0.0.1:$port", $indexPath
    ) -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath

    $ready = $false
    for ($attempt = 1; $attempt -le 50; $attempt++) {
        if ($process.HasExited) { break }
        try {
            $health = Invoke-RestMethod -Uri "$baseUrl/?health=1" -TimeoutSec 1
            if ($health.code -eq 200 -and $health.success -eq $true) {
                $ready = $true
                break
            }
        } catch {}
        Start-Sleep -Milliseconds 100
    }
    Assert-True $ready "API did not become ready: $((Get-Content -LiteralPath $stderrPath -Tail 20) -join ' ')"

    $response = Invoke-TestRequest "$baseUrl/?jmid%5B%5D=1"
    Assert-True ($response.Status -eq 400) "Array jmid must return HTTP 400, got $($response.Status)."
    Assert-True ($response.ContentType -match '(?i)^application/json\b') "Array jmid must return JSON, got '$($response.ContentType)'."
    $payload = $response.Body | ConvertFrom-Json
    Assert-True ($payload.code -eq 400 -and $payload.success -eq $false -and -not [string]::IsNullOrWhiteSpace([string] $payload.error)) `
        "Array jmid returned an unexpected error payload: $($response.Body)"

    $response = Invoke-TestRequest "$baseUrl/?health=1" -Method 'POST'
    Assert-True ($response.Status -eq 405) "POST must return HTTP 405, got $($response.Status)."
    Assert-True ($response.ContentType -match '(?i)^application/json\b') "POST rejection must return JSON, got '$($response.ContentType)'."
    Assert-True ($response.Allow -match '(?i)\bGET\b' -and $response.Allow -match '(?i)\bOPTIONS\b') `
        "POST rejection must advertise GET and OPTIONS, got '$($response.Allow)'."
    $payload = $response.Body | ConvertFrom-Json
    Assert-True ($payload.code -eq 405 -and $payload.success -eq $false) `
        "POST returned an unexpected error payload: $($response.Body)"

    foreach ($case in @(
        @{ Name = 'array chapter'; Query = 'jmid=350234&chapter%5B%5D=350234' },
        @{ Name = 'array page'; Query = 'jmid=350234&chapter=350234&page%5B%5D=1' }
    )) {
        $response = Invoke-TestRequest "$baseUrl/?$($case.Query)"
        Assert-True ($response.Status -eq 400) "$($case.Name) must return HTTP 400 without an upstream request, got $($response.Status)."
        Assert-True ($response.ContentType -match '(?i)^application/json\b') `
            "$($case.Name) must return JSON, got '$($response.ContentType)'."
        $payload = $response.Body | ConvertFrom-Json
        Assert-True ($payload.code -eq 400 -and $payload.success -eq $false) `
            "$($case.Name) returned an unexpected error payload: $($response.Body)"
    }
} finally {
    Stop-TestProcess $process
    if (Test-Path -LiteralPath $tempTree) {
        Remove-Item -LiteralPath $tempTree -Recurse -Force
    }
}

Write-Output 'Input validation runtime passed.'
