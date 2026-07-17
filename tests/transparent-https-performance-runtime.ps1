param(
    [string] $PythonPath = 'C:\Users\MZB\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe',
    [string] $PhpPath = 'D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe',
    [string] $ApcuExtension = 'D:\jm\.tools\php_apcu-5.1.28-8.3-nts-vs16-x64\php_apcu.dll'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$orchestrator = Join-Path $root 'scripts\transparent-https-performance.ps1'

function Assert-True {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

if (-not (Test-Path -LiteralPath $orchestrator -PathType Leaf)) {
    throw "Transparent HTTPS performance orchestrator not found: $orchestrator"
}

$token = [guid]::NewGuid().ToString('N')
$outputPath = Join-Path ([System.IO.Path]::GetTempPath()) "jm-transparent-https-performance-smoke-$token.json"
$expectedRoutes = @('health', 'latest_page_1', 'latest_page_4', 'album', 'image_no_prefetch')

try {
    & $orchestrator `
        -PythonPath $PythonPath -PhpPath $PhpPath -ApcuExtension $ApcuExtension `
        -WarmupIterations 1 -Iterations 2 -Concurrency 2 -OutputPath $outputPath

    Assert-True (Test-Path -LiteralPath $outputPath -PathType Leaf) 'A/B smoke did not write its report.'
    $report = Get-Content -LiteralPath $outputPath -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-True ($report.schema_version -eq 'historical-common-denominator-v1') 'A/B smoke schema version mismatch.'
    Assert-True ($report.status -eq 'complete') 'A/B smoke report is not complete.'
    Assert-True ($report.parameters.warmup_iterations -eq 1) 'A/B smoke warm-up count mismatch.'
    Assert-True ($report.parameters.iterations -eq 2) 'A/B smoke iteration count mismatch.'
    Assert-True ($report.parameters.concurrency -eq 2) 'A/B smoke concurrency mismatch.'
    Assert-True (($report.parameters.routes -join ',') -eq ($expectedRoutes -join ',')) 'A/B smoke route set mismatch.'

    Assert-True ($report.environment.external_conditions_sha256 -match '^[0-9A-F]{64}$') 'External conditions fingerprint is missing or malformed.'
    Assert-True ($report.harness.same_proxy_instance -eq $true) 'BEFORE/AFTER did not use the same proxy instance.'
    Assert-True ($report.harness.same_fixture_instance -eq $true) 'BEFORE/AFTER did not use the same fixture instance.'
    Assert-True ($report.results.before.proxy_pid -eq $report.results.after.proxy_pid) 'Result proxy PIDs differ.'
    Assert-True ($report.results.before.fixture_pid -eq $report.results.after.fixture_pid) 'Result fixture PIDs differ.'

    foreach ($label in @('before', 'after')) {
        $source = $report.sources.PSObject.Properties[$label].Value
        Assert-True ($source.index_sha256_before -match '^[0-9A-F]{64}$') "$label source hash is malformed."
        Assert-True ($source.index_sha256_before -eq $source.index_sha256_after) "$label source changed during measurement."

        $result = $report.results.PSObject.Properties[$label].Value
        Assert-True ($result.external_conditions_sha256 -eq $report.environment.external_conditions_sha256) "$label external fingerprint differs."
        Assert-True ($result.external_conditions_sha256 -eq $result.external_conditions_sha256_after) "$label observed environment changed during its phase."
        Assert-True ($result.instance_identity_verified -eq $true) "$label did not verify the live proxy/fixture identities."
        Assert-True ($result.fixture_request_count -gt 0) "$label did not reach the fixture."
        Assert-True ($result.fixture_coverage.complete -eq $true) "$label did not prove config/API/chapter/CDN fixture coverage."
        foreach ($route in $expectedRoutes) {
            $summary = $result.warm_summary.PSObject.Properties[$route].Value
            Assert-True ($null -ne $summary) "$label is missing route $route."
            Assert-True ($summary.samples -eq 2 -and $summary.successful -eq 2 -and $summary.failed -eq 0) "$label route $route did not produce two successful samples."
        }
    }
    Assert-True ($report.sources.before.index_sha256_before -ne $report.sources.after.index_sha256_before) 'BEFORE and AFTER unexpectedly used the same source hash.'
    Assert-True ($report.sources.before.snapshot_unchanged -eq $true) 'Authoritative four-file historical snapshot changed.'

    Assert-True ($report.comparison.mode -eq 'historical-common-denominator-v1') 'Comparison mode mismatch.'
    Assert-True ($report.comparison.evidence_complete -eq $true) 'Comparison evidence is incomplete.'
    Assert-True ($report.comparison.comparable -eq $true) 'A/B smoke is not comparable.'
    Assert-True ($report.comparison.fixed_loopback_proven -eq $true) 'A/B smoke did not prove fixed-loopback upstream coverage.'
    foreach ($route in $expectedRoutes) {
        Assert-True ($null -ne $report.comparison.routes.PSObject.Properties[$route].Value) "Comparison is missing route $route."
    }

    Assert-True ($report.cleanup.complete -eq $true) 'A/B smoke cleanup is incomplete.'
    Assert-True ($report.cleanup.processes_exited -eq $true) 'A/B smoke left a child process running.'
    Assert-True ($report.cleanup.ca_not_installed -eq $true) 'A/B smoke installed its temporary CA.'
    Assert-True ($report.cleanup.environment_restored -eq $true) 'A/B smoke did not restore the parent environment.'
    Assert-True ($report.cleanup.temp_tree_removed -eq $true) 'A/B smoke did not remove its temporary tree.'
    Assert-True (-not (Test-Path -LiteralPath $report.cleanup.temp_tree_path)) 'A/B smoke temporary tree still exists.'

    Write-Output 'Transparent HTTPS A/B smoke passed: both sources, common evidence, comparability, and cleanup.'
} finally {
    if (Test-Path -LiteralPath $outputPath) {
        Remove-Item -LiteralPath $outputPath -Force
    }
}
