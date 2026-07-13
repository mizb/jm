param(
    [string] $PhpPath = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $php) {
        throw 'PHP runtime not found. Pass -PhpPath <path-to-php.exe>.'
    }
    $PhpPath = $php.Source
}

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP runtime does not exist: $PhpPath"
}

$scriptPath = Join-Path $PSScriptRoot 'catalog-order-runtime.php'
& $PhpPath $scriptPath
if ($LASTEXITCODE -ne 0) {
    throw "Catalog order runtime test failed with exit code $LASTEXITCODE"
}
