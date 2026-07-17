param(
    [Parameter(Mandatory = $true)] [string] $PhpPath,
    [Parameter(Mandatory = $true)] [string] $ApcuExtension,
    [ValidateRange(1, 65535)] [int] $ApiPort = 18188,
    [ValidateRange(1, 65535)] [int] $FixturePort = 18190,
    [switch] $PortCollisionSelfTest
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

function Assert-True { param([bool] $Condition, [string] $Message); if (-not $Condition) { throw $Message } }
function Header-Value { param($Headers, [string] $Name); return (@($Headers[$Name]) -join ', ') }

function Assert-LoopbackPortAvailable {
    param([ValidateRange(1, 65535)] [int] $Port, [string] $Label)
    $probe = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Port)
    try {
        $probe.Server.ExclusiveAddressUse = $true
        try {
            $probe.Start()
        } catch {
            throw "$Label port $Port is already in use on 127.0.0.1."
        }
    } finally {
        $probe.Stop()
    }
}

function Invoke-CapturedRequest {
    param([string] $Url, [hashtable] $Headers = @{})
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -Headers $Headers
        return [pscustomobject]@{ Status = [int] $response.StatusCode; Headers = $response.Headers; Body = $response.Content }
    } catch {
        $response = $_.Exception.Response
        if ($null -eq $response) { throw }
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        return [pscustomobject]@{ Status = [int] $response.StatusCode; Headers = $response.Headers; Body = $body }
    }
}

function Wait-Endpoint {
    param([string] $Url, [System.Diagnostics.Process] $Process)
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        if ($Process.HasExited) { throw "Local PHP server exited before ready: $Url" }
        $ready = $false
        try {
            Invoke-WebRequest -UseBasicParsing -Uri $Url | Out-Null
            $ready = $true
        } catch {
            if ($attempt -eq 60) { throw "Local PHP server did not become ready: $Url" }
            Start-Sleep -Milliseconds 100
        }
        if ($ready) {
            $Process.Refresh()
            if ($Process.HasExited) { throw "Local PHP server exited after a readiness response: $Url" }
            return
        }
    }
}

function New-RunId { return [guid]::NewGuid().ToString('D') }
function Api-Url {
    param([string] $Query, [string] $Scenario, [string] $RunId)
    return "$script:BaseUrl/?$Query&test_scenario=$Scenario&test_run_id=$RunId"
}
function Reset-Fixture { param([string] $RunId); Invoke-WebRequest -UseBasicParsing -Uri "$script:FixtureUrl/__reset?run_id=$RunId" | Out-Null }
function Fixture-Counts { param([string] $RunId); return (Invoke-RestMethod -Uri "$script:FixtureUrl/__stats?run_id=$RunId").counts }
function Count-Key {
    param($Counts, [string] $Key)
    $property = $Counts.PSObject.Properties[$Key]
    return $(if ($null -eq $property) { 0 } else { [int] $property.Value })
}
function Count-MediaHost {
    param($Counts, [string] $HostName, [string] $Scenario)
    $total = 0
    $pattern = '^' + [regex]::Escape($HostName) + '\|/media/photos/[^|]+\|\|' + [regex]::Escape($Scenario) + '$'
    foreach ($property in $Counts.PSObject.Properties) {
        if ($property.Name -match $pattern) { $total += [int] $property.Value }
    }
    return $total
}

function Find-ByteSequence {
    param([byte[]] $Bytes, [byte[]] $Needle, [int] $Start = 0)
    if ($Needle.Length -eq 0) { return $Start }
    for ($offset = [Math]::Max(0, $Start); $offset -le $Bytes.Length - $Needle.Length; $offset++) {
        $match = $true
        for ($needleOffset = 0; $needleOffset -lt $Needle.Length; $needleOffset++) {
            if ($Bytes[$offset + $needleOffset] -ne $Needle[$needleOffset]) {
                $match = $false
                break
            }
        }
        if ($match) { return $offset }
    }
    return -1
}

function Invoke-RawHttpExchange {
    param([int] $Port, [string] $Path, [string] $Scenario)
    $client = New-Object System.Net.Sockets.TcpClient
    $stream = $null
    $memory = New-Object System.IO.MemoryStream
    try {
        $client.ReceiveTimeout = 5000
        $client.SendTimeout = 5000
        $client.Connect('127.0.0.1', $Port)
        $stream = $client.GetStream()
        $request = "GET $Path HTTP/1.1`r`nHost: 127.0.0.1:$Port`r`nX-JM-Test-Scenario: $Scenario`r`nConnection: close`r`n`r`n"
        $requestBytes = [Text.Encoding]::ASCII.GetBytes($request)
        $stream.Write($requestBytes, 0, $requestBytes.Length)
        $stream.Flush()
        $buffer = New-Object byte[] 4096
        while (($read = $stream.Read($buffer, 0, $buffer.Length)) -gt 0) {
            $memory.Write($buffer, 0, $read)
        }
        $responseBytes = $memory.ToArray()
        $separator = [byte[]] @(13, 10, 13, 10)
        $headerEnd = Find-ByteSequence $responseBytes $separator
        if ($headerEnd -lt 0) { throw "$Scenario raw response omitted the HTTP header terminator" }
        $headerBytes = New-Object byte[] $headerEnd
        [Array]::Copy($responseBytes, 0, $headerBytes, 0, $headerEnd)
        $bodyOffset = $headerEnd + $separator.Length
        $bodyBytes = New-Object byte[] ($responseBytes.Length - $bodyOffset)
        [Array]::Copy($responseBytes, $bodyOffset, $bodyBytes, 0, $bodyBytes.Length)
        return [pscustomobject]@{
            Headers = [Text.Encoding]::ASCII.GetString($headerBytes)
            Body = $bodyBytes
        }
    } finally {
        if ($null -ne $stream) { $stream.Dispose() }
        $client.Dispose()
        $memory.Dispose()
    }
}

function ConvertFrom-ChunkedBody {
    param([byte[]] $Body, [string] $Scenario)
    $position = 0
    $payload = New-Object System.IO.MemoryStream
    $frameSizes = New-Object 'System.Collections.Generic.List[int]'
    try {
        while ($true) {
            $lineEnd = Find-ByteSequence $Body ([byte[]] @(13, 10)) $position
            if ($lineEnd -lt 0) { throw "$Scenario chunk size line was not CRLF terminated" }
            $lineLength = $lineEnd - $position
            $sizeLine = [Text.Encoding]::ASCII.GetString($Body, $position, $lineLength)
            $extensionOffset = $sizeLine.IndexOf(';')
            if ($extensionOffset -ge 0) { $sizeLine = $sizeLine.Substring(0, $extensionOffset) }
            $size = 0
            if (-not [int]::TryParse(
                $sizeLine,
                [Globalization.NumberStyles]::AllowHexSpecifier,
                [Globalization.CultureInfo]::InvariantCulture,
                [ref] $size
            )) { throw "$Scenario emitted an invalid chunk size: $sizeLine" }
            $position = $lineEnd + 2
            if ($size -eq 0) {
                if ($position + 2 -gt $Body.Length -or $Body[$position] -ne 13 -or $Body[$position + 1] -ne 10) {
                    throw "$Scenario omitted the terminal empty trailer line"
                }
                $position += 2
                if ($position -ne $Body.Length) { throw "$Scenario emitted bytes after the terminal chunk" }
                break
            }
            if ($size -lt 0 -or $position + $size + 2 -gt $Body.Length) {
                throw "$Scenario chunk payload exceeded the wire body"
            }
            $frameSizes.Add($size)
            $payload.Write($Body, $position, $size)
            $position += $size
            if ($Body[$position] -ne 13 -or $Body[$position + 1] -ne 10) {
                throw "$Scenario chunk payload was not CRLF terminated"
            }
            $position += 2
        }
        return [pscustomobject]@{ Payload = $payload.ToArray(); FrameSizes = $frameSizes.ToArray() }
    } finally {
        $payload.Dispose()
    }
}

function Remove-ControlledArtifact {
    param([string] $Path, [string] $Token)
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $full = [IO.Path]::GetFullPath($Path)
    $temp = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\', '/')
    $parent = [IO.Path]::GetDirectoryName($full).TrimEnd('\', '/')
    $leaf = [IO.Path]::GetFileName($full)
    if (-not [StringComparer]::OrdinalIgnoreCase.Equals($parent, $temp) -or $leaf -notlike "jm-resource-$Token*") {
        throw "Refusing to remove uncontrolled resource test artifact: $full"
    }
    if (Test-Path -LiteralPath $full -PathType Container) { Remove-Item -LiteralPath $full -Recurse -Force }
    else { Remove-Item -LiteralPath $full -Force }
}

function Invoke-PortCollisionSelfTest {
    $occupied = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
    $occupied.Server.ExclusiveAddressUse = $true
    $occupied.Start()
    $port = ([Net.IPEndPoint] $occupied.LocalEndpoint).Port
    $fixtureResetCalls = 0
    $rejected = $false
    try {
        try {
            Assert-LoopbackPortAvailable -Port $port -Label 'Collision self-test'
            $fixtureResetCalls++
        } catch {
            if ($_.Exception.Message -notmatch '^Collision self-test port \d+ is already in use on 127\.0\.0\.1\.$') { throw }
            $rejected = $true
        }
    } finally {
        $occupied.Stop()
    }
    Assert-True $rejected 'Port collision self-test did not reject the occupied loopback port.'
    Assert-True ($fixtureResetCalls -eq 0) 'Port collision self-test reached the simulated old fixture reset.'
    Write-Output "Resource HTTP port collision self-test passed: occupied=$port, fixture_reset_calls=$fixtureResetCalls."
}

if ($PortCollisionSelfTest) {
    Invoke-PortCollisionSelfTest
    return
}

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PhpPath must point to php.exe.' }
if (-not (Test-Path -LiteralPath $ApcuExtension -PathType Leaf)) { throw 'ApcuExtension must point to php_apcu.dll.' }
if ($ApiPort -eq $FixturePort) { throw 'API and fixture ports must differ.' }
Assert-LoopbackPortAvailable -Port $ApiPort -Label 'API'
Assert-LoopbackPortAvailable -Port $FixturePort -Label 'Fixture'

$script:BaseUrl = "http://127.0.0.1:$ApiPort"
$script:FixtureUrl = "http://127.0.0.1:$FixturePort"
$script:ChunkFixturePort = $FixturePort
$phpRoot = Split-Path -Parent (Resolve-Path -LiteralPath $PhpPath).Path
$apcuPath = (Resolve-Path -LiteralPath $ApcuExtension).Path
$basePhpArgs = @(
    '-n',
    '-d', "extension_dir=$(Join-Path $phpRoot 'ext')",
    '-d', 'extension=php_curl.dll',
    '-d', 'extension=php_openssl.dll',
    '-d', 'extension=php_mbstring.dll',
    '-d', 'extension=php_gd.dll'
)
$apiPhpArgs = $basePhpArgs + @('-d', "extension=$apcuPath", '-d', 'apc.enable_cli=1')
$environmentNames = @(
    'JM_FIXTURE_STATS_DIR', 'JM_TEST_MODE', 'JM_TEST_ALLOWED_HOSTS',
    'JM_TEST_API_BASE_URLS', 'JM_TEST_FALLBACK_API_BASE_URLS',
    'JM_TEST_DOMAIN_SOURCE_URLS', 'JM_TEST_CDN_BASE_URLS',
    'JM_DOMAIN_REFRESH_DEFERRED', 'JM_REQUEST_BUDGET_MS',
    'JM_MAX_UPSTREAM_ATTEMPTS', 'JM_IMAGE_MAX_COMPRESSED_BYTES',
    'JM_IMAGE_MAX_PIXELS', 'JM_PREFETCH_PAGES', 'JM_PREFETCH_MAX_ACTIVE',
    'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_MIN_FREE_RATIO',
    'JM_TRUSTED_PROXY_CIDRS', 'REDIS_TIMEOUT_MS'
)
$savedEnvironment = @{}
foreach ($name in $environmentNames) { $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }

$token = [guid]::NewGuid().ToString('N')
$statsDirectory = Join-Path $env:TEMP "jm-resource-$token-stats"
$fixtureOut = Join-Path $env:TEMP "jm-resource-$token-fixture.out"
$fixtureErr = Join-Path $env:TEMP "jm-resource-$token-fixture.err"
$apiOut = Join-Path $env:TEMP "jm-resource-$token-api.out"
$apiErr = Join-Path $env:TEMP "jm-resource-$token-api.err"
$artifacts = @($statsDirectory, $fixtureOut, $fixtureErr, $apiOut, $apiErr)
$fixtureProcess = $null
$apiProcess = $null
$completed = $false

try {
    $env:JM_FIXTURE_STATS_DIR = $statsDirectory
    $fixtureProcess = Start-Process -FilePath $PhpPath `
        -ArgumentList ($basePhpArgs + @('-S', "127.0.0.1:$FixturePort", 'tests\fixtures\upstream-router.php')) `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $fixtureOut -RedirectStandardError $fixtureErr

    $env:JM_TEST_MODE = '1'
    $env:JM_TEST_ALLOWED_HOSTS = '127.0.0.1,localhost'
    $env:JM_TEST_API_BASE_URLS = $script:FixtureUrl
    $env:JM_TEST_FALLBACK_API_BASE_URLS = $script:FixtureUrl
    $env:JM_TEST_DOMAIN_SOURCE_URLS = 'disabled'
    $env:JM_TEST_CDN_BASE_URLS = "$($script:FixtureUrl),http://localhost:$FixturePort"
    $env:JM_DOMAIN_REFRESH_DEFERRED = '0'
    $env:JM_REQUEST_BUDGET_MS = '5000'
    $env:JM_MAX_UPSTREAM_ATTEMPTS = '8'
    $env:JM_IMAGE_MAX_COMPRESSED_BYTES = '149'
    $env:JM_IMAGE_MAX_PIXELS = '1024'
    $env:JM_PREFETCH_PAGES = '0'
    $env:JM_PREFETCH_MAX_ACTIVE = '0'
    $env:JM_PAGE_CACHE_MIN_FREE_BYTES = '0'
    $env:JM_PAGE_CACHE_MIN_FREE_RATIO = '0'
    $env:JM_TRUSTED_PROXY_CIDRS = ''
    $env:REDIS_TIMEOUT_MS = '10'
    $apiProcess = Start-Process -FilePath $PhpPath `
        -ArgumentList ($apiPhpArgs + @('-S', "127.0.0.1:$ApiPort", 'index.php')) `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $apiOut -RedirectStandardError $apiErr

    Wait-Endpoint "$script:FixtureUrl/__stats?run_id=ready-check" $fixtureProcess
    Wait-Endpoint "$script:BaseUrl/?health=1" $apiProcess
    $health = Invoke-RestMethod -Uri "$script:BaseUrl/?health=1"
    Assert-True ($health.diagnostics.apcu -eq $true) 'Resource HTTP API did not enable APCu.'
    Assert-True ($health.diagnostics.test_mode -eq $true) 'Resource HTTP API did not enable exact test mode.'

    $wireExpectations = @{
        'image-body-chunked-exact' = @{ Length = 149; Frames = '37,43,69' }
        'image-body-chunked-over' = @{ Length = 150; Frames = '37,43,69,1' }
    }
    foreach ($scenario in @('image-body-chunked-exact', 'image-body-chunked-over')) {
        $wire = Invoke-RawHttpExchange $script:ChunkFixturePort '/media/photos/350234/00001.png' $scenario
        Assert-True ($wire.Headers -match '(?im)^HTTP/1\.1 200(?:\s|$)') "$scenario did not return HTTP/1.1 200"
        Assert-True ($wire.Headers -match '(?im)^Transfer-Encoding\s*:\s*chunked\s*$') "$scenario was not transferred with HTTP/1.1 chunked framing"
        Assert-True ($wire.Headers -notmatch '(?im)^Content-Length\s*:') "$scenario incorrectly supplied Content-Length"
        $decodedWire = ConvertFrom-ChunkedBody $wire.Body $scenario
        Assert-True ($decodedWire.Payload.Length -eq $wireExpectations[$scenario].Length) "$scenario decoded wire length changed"
        Assert-True ((@($decodedWire.FrameSizes) -join ',') -ceq $wireExpectations[$scenario].Frames) "$scenario wire frame plan changed"
    }

    $normalized = @{}
    foreach ($scenario in @('chapter-images-strings', 'chapter-images-objects', 'chapter-images-mixed')) {
        $runId = New-RunId
        Reset-Fixture $runId
        $response = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&format=min' $scenario $runId)
        Assert-True ($response.Status -eq 200) "$scenario chapter request failed"
        $json = $response.Body | ConvertFrom-Json
        $chapter = $json.data.chapters[0]
        Assert-True ($chapter.page_count -eq 12) "$scenario page_count changed"
        $normalized[$scenario] = @($chapter.images | ForEach-Object { "$($_.index)|$($_.filename)|$($_.decode_segments)" }) -join ','
        foreach ($image in $chapter.images) {
            Assert-True ($image.source_url -like "$($script:FixtureUrl)/media/photos/350234/*") "$scenario source_url escaped the CDN allowlist"
            Assert-True ($image.url -like "$($script:BaseUrl)/*") "$scenario decoded URL changed"
            Assert-True ($null -eq $image.media_path) "$scenario leaked internal media_path"
            Assert-True (@($image.PSObject.Properties.Name).Count -eq 7) "$scenario public image field set changed"
        }
    }
    Assert-True ($normalized['chapter-images-strings'] -ceq $normalized['chapter-images-objects']) 'string/object chapter normalization differs'
    Assert-True ($normalized['chapter-images-strings'] -ceq $normalized['chapter-images-mixed']) 'mixed chapter normalization lost ordering'

    $runId = New-RunId
    Reset-Fixture $runId
    $empty = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&format=min' 'chapter-images-empty' $runId)
    Assert-True ($empty.Status -eq 200) 'explicit empty chapter request failed'
    $emptyJson = $empty.Body | ConvertFrom-Json
    Assert-True ($emptyJson.data.chapters[0].page_count -eq 0) 'explicit empty chapter was not preserved'

    foreach ($scenario in @('chapter-images-object-empty', 'chapter-images-object-zero')) {
        $runId = New-RunId
        Reset-Fixture $runId
        1..2 | ForEach-Object {
            $bad = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&format=min' $scenario $runId)
            Assert-True ($bad.Status -eq 502) "$scenario did not fail the entire response as 502"
        }
        $badCounts = Fixture-Counts $runId
        Assert-True ((Count-Key $badCounts "127.0.0.1|/chapter||$scenario") -eq 2) "$scenario was cached or swallowed"
    }

    $runId = New-RunId
    Reset-Fixture $runId
    1..2 | ForEach-Object {
        $bad = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&format=min' 'chapter-images-malformed' $runId)
        Assert-True ($bad.Status -eq 502) 'malformed chapter did not fail the entire response as 502'
    }
    $badCounts = Fixture-Counts $runId
    Assert-True ((Count-Key $badCounts "127.0.0.1|/chapter||chapter-images-malformed") -eq 2) 'malformed chapter was cached or swallowed'

    foreach ($scenario in @(
        'image-body-exact', 'image-body-over', 'image-body-no-length', 'image-body-forged-length',
        'image-body-chunked-exact', 'image-body-chunked-over', 'image-pixel-over', 'image-invalid'
    )) {
        $runId = New-RunId
        Reset-Fixture $runId
        $response = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&page=1&prefetch=0' $scenario $runId)
        $expected = if ($scenario -in @('image-body-exact', 'image-body-chunked-exact')) { 200 } else { 502 }
        Assert-True ($response.Status -eq $expected) "$scenario expected HTTP $expected, got $($response.Status)"
        $counts = Fixture-Counts $runId
        Assert-True ((Count-MediaHost $counts '127.0.0.1' $scenario) -eq 1) "$scenario primary media count changed"
        Assert-True ((Count-MediaHost $counts 'localhost' $scenario) -eq 0) "$scenario incorrectly triggered CDN failover"
    }

    foreach ($scenario in @('image-http-302', 'image-http-404', 'image-http-429')) {
        $runId = New-RunId
        Reset-Fixture $runId
        $response = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&page=1&prefetch=0' $scenario $runId)
        Assert-True ($response.Status -eq 502) "$scenario did not fail safely"
        $counts = Fixture-Counts $runId
        Assert-True ((Count-MediaHost $counts '127.0.0.1' $scenario) -eq 1) "$scenario primary media count changed"
        Assert-True ((Count-MediaHost $counts 'localhost' $scenario) -eq 0) "$scenario incorrectly triggered CDN failover"
    }

    $runId = New-RunId
    Reset-Fixture $runId
    $requestTimeout = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&page=1&prefetch=0' 'image-http-408' $runId)
    Assert-True ($requestTimeout.Status -eq 502) 'HTTP 408 CDN response did not fail safely without failover'
    $requestTimeoutCounts = Fixture-Counts $runId
    Assert-True ((Count-MediaHost $requestTimeoutCounts '127.0.0.1' 'image-http-408') -eq 1) 'HTTP 408 CDN primary was not attempted exactly once'
    Assert-True ((Count-MediaHost $requestTimeoutCounts 'localhost' 'image-http-408') -eq 0) 'HTTP 408 incorrectly triggered CDN failover'

    $runId = New-RunId
    Reset-Fixture $runId
    $failover = Invoke-CapturedRequest (Api-Url 'jmid=350234&chapter=350234&page=1&prefetch=0' 'cdn-502' $runId)
    Assert-True ($failover.Status -eq 200) 'network/5xx CDN failover did not recover'
    $failoverCounts = Fixture-Counts $runId
    Assert-True ((Count-MediaHost $failoverCounts '127.0.0.1' 'cdn-502') -eq 1) 'CDN primary was not attempted exactly once'
    Assert-True ((Count-MediaHost $failoverCounts 'localhost' 'cdn-502') -eq 1) 'CDN secondary was not attempted exactly once'

    $runId = New-RunId
    Reset-Fixture $runId
    $spoof = Invoke-CapturedRequest (Api-Url 'list=latest&page=1&format=min' 'valid-list-80' $runId) @{ 'X-Forwarded-For' = '203.0.113.99' }
    Assert-True ($spoof.Status -eq 200) 'untrusted proxy request failed'
    Assert-True ((Header-Value $spoof.Headers 'X-JM-Test-Client-Ip') -eq '127.0.0.1') 'untrusted XFF changed effective client IP'
    $trusted = Invoke-CapturedRequest (Api-Url 'list=latest&page=2&format=min&test_trusted_proxy=1' 'valid-list-80' $runId) @{ 'X-Forwarded-For' = '198.51.100.7' }
    Assert-True ($trusted.Status -eq 200) 'trusted proxy request failed'
    Assert-True ((Header-Value $trusted.Headers 'X-JM-Test-Client-Ip') -eq '198.51.100.7') 'trusted proxy did not accept first valid XFF client'

    Write-Output 'Resource HTTP runtime passed.'
    $completed = $true
} finally {
    foreach ($process in @($apiProcess, $fixtureProcess)) {
        if ($null -ne $process) {
            if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
            try { $process.WaitForExit(5000) | Out-Null } catch {}
            $process.Dispose()
        }
    }
    foreach ($name in $environmentNames) { [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process') }
    foreach ($artifact in $artifacts) { Remove-ControlledArtifact $artifact $token }
    if (-not $completed) { Write-Output 'Resource HTTP runtime failed; local processes and controlled artifacts were cleaned.' }
}
