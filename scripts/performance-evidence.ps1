function Get-PerformanceEvidenceInteger {
    param($Object, [string] $Name)

    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value) { return $null }

    $value = $property.Value
    $typeCode = [System.Type]::GetTypeCode($value.GetType()).ToString()
    if ($typeCode -notin @('SByte', 'Byte', 'Int16', 'UInt16', 'Int32', 'UInt32', 'Int64', 'UInt64')) {
        return $null
    }
    try { return [int64] $value } catch { return $null }
}

function Test-PerformanceEvidenceSkipCountsEmpty {
    param($Aggregate)

    if ($null -eq $Aggregate) { return $false }
    $property = $Aggregate.PSObject.Properties['skip_counts']
    if ($null -eq $property -or $null -eq $property.Value) { return $false }
    $skipCounts = $property.Value
    if ($skipCounts -is [System.Array]) { return $skipCounts.Count -eq 0 }
    if ($skipCounts -is [System.Collections.IDictionary]) { return $skipCounts.Count -eq 0 }
    if ($skipCounts -is [pscustomobject]) { return @($skipCounts.PSObject.Properties).Count -eq 0 }
    return $false
}

function Test-PerformanceEvidenceSkipCountsShape {
    param($Aggregate, [AllowNull()] $MaximumTotal = $null)

    if ($null -eq $Aggregate) { return $false }
    $property = $Aggregate.PSObject.Properties['skip_counts']
    if ($null -eq $property -or $null -eq $property.Value) { return $false }
    $skipCounts = $property.Value
    if ($skipCounts -is [System.Array]) { return $skipCounts.Count -eq 0 }
    $allowedReasons = @(
        'disabled',
        'skipped-pages-zero',
        'skipped-max-active-zero',
        'skipped-wall-zero',
        'skipped-byte-zero',
        'skipped-no-apcu',
        'skipped-low-memory',
        'skipped-pages-covered',
        'skipped-busy',
        'skipped-registration',
        'budget-attempts',
        'budget-wall',
        'budget-bytes',
        'slot-lost',
        'page-lease-lost',
        'executor-error'
    )
    if ($skipCounts -is [System.Collections.IDictionary]) {
        $entries = @($skipCounts.Keys | ForEach-Object {
            [pscustomobject]@{ Name = [string] $_; Value = $skipCounts[$_] }
        })
    } elseif ($skipCounts -is [pscustomobject]) {
        $entries = @($skipCounts.PSObject.Properties | ForEach-Object {
            [pscustomobject]@{ Name = $_.Name; Value = $_.Value }
        })
    } else {
        return $false
    }
    $total = [int64] 0
    foreach ($entry in $entries) {
        if ($allowedReasons -cnotcontains $entry.Name -or $null -eq $entry.Value) { return $false }
        $typeCode = [System.Type]::GetTypeCode($entry.Value.GetType()).ToString()
        if ($typeCode -notin @('SByte', 'Byte', 'Int16', 'UInt16', 'Int32', 'UInt32', 'Int64', 'UInt64')) {
            return $false
        }
        try {
            $count = [int64] $entry.Value
            if ($count -le 0 -or $count -gt [int64]::MaxValue - $total) { return $false }
            $total += $count
        } catch {
            return $false
        }
    }
    if ($null -ne $MaximumTotal) {
        try {
            if ($total -gt [int64] $MaximumTotal) { return $false }
        } catch {
            return $false
        }
    }
    return $true
}

function Get-PerformanceEvidenceMember {
    param($Object, [string] $Name)

    if ($null -eq $Object) {
        return [pscustomobject]@{ exists = $false; value = $null }
    }
    if ($Object -is [System.Collections.IDictionary]) {
        if (-not $Object.Contains($Name)) {
            return [pscustomobject]@{ exists = $false; value = $null }
        }
        return [pscustomobject]@{ exists = $true; value = $Object[$Name] }
    }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return [pscustomobject]@{ exists = $false; value = $null }
    }
    return [pscustomobject]@{ exists = $true; value = $property.Value }
}

function Get-PerformanceEvidenceMemberNames {
    param($Object)

    if ($null -eq $Object) { return @() }
    if ($Object -is [System.Collections.IDictionary]) {
        return @($Object.Keys | ForEach-Object { [string] $_ })
    }
    return @($Object.PSObject.Properties | ForEach-Object { $_.Name })
}

function Get-PerformanceEvidenceObjectSha256 {
    param([AllowNull()] $Value)

    if ($null -eq $Value) { return $null }
    try {
        $json = ConvertTo-Json -InputObject $Value -Compress -Depth 20
        if ($null -eq $json) { return $null }
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try {
            $bytes = [System.Text.Encoding]::UTF8.GetBytes([string] $json)
            return (($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '')
        } finally {
            $sha.Dispose()
        }
    } catch {
        return $null
    }
}

function Test-PerformanceEvidenceSha256 {
    param($Value, [switch] $WithPrefix)

    if ($Value -isnot [string]) { return $false }
    $pattern = if ($WithPrefix) { '^sha256:[0-9a-fA-F]{64}$' } else { '^[0-9a-fA-F]{64}$' }
    if ($Value -cnotmatch $pattern) { return $false }
    $digest = if ($WithPrefix) { $Value.Substring(7) } else { $Value }
    return @($digest.ToLowerInvariant().ToCharArray() | Sort-Object -Unique).Count -ge 8
}

function Test-PerformanceEvidenceExactFieldSet {
    param($Object, [string[]] $ExpectedFields)

    $actual = @(Get-PerformanceEvidenceMemberNames -Object $Object | Sort-Object)
    $expected = @($ExpectedFields | Sort-Object)
    return $actual.Count -eq $expected.Count -and
        ($actual -join "`n") -ceq ($expected -join "`n")
}

function Test-PerformanceEvidenceNonNegativeInteger {
    param($Value)

    if ($null -eq $Value) { return $false }
    $typeCode = [System.Type]::GetTypeCode($Value.GetType()).ToString()
    if ($typeCode -notin @('SByte', 'Byte', 'Int16', 'UInt16', 'Int32', 'UInt32', 'Int64', 'UInt64')) {
        return $false
    }
    try { return [int64] $Value -ge 0 } catch { return $false }
}

function Test-PerformanceEvidenceSafeAssertion {
    param($Value)

    return $Value -is [string] -and
        $Value -ne 'unverified' -and
        $Value -match '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'
}

function Test-PerformanceEvidencePositiveInteger {
    param($Value)

    if ($null -eq $Value) { return $false }
    $typeCode = [System.Type]::GetTypeCode($Value.GetType()).ToString()
    if ($typeCode -notin @('SByte', 'Byte', 'Int16', 'UInt16', 'Int32', 'UInt32', 'Int64', 'UInt64')) {
        return $false
    }
    try { return [int64] $Value -gt 0 } catch { return $false }
}

function Get-PerformanceEvidencePercentile {
    param([int64[]] $Values, [double] $Percentile)

    if ($Values.Count -eq 0) { return $null }
    $sorted = @($Values | Sort-Object)
    $index = [Math]::Ceiling($Percentile * $sorted.Count) - 1
    $index = [Math]::Max(0, [Math]::Min($sorted.Count - 1, $index))
    return [int64] $sorted[$index]
}

function Resolve-PerformanceRawWarmEvidence {
    param(
        [Parameter(Mandatory = $true)] $WarmSamples,
        [Parameter(Mandatory = $true)] $WarmSummary,
        [Parameter(Mandatory = $true)] [string[]] $ExpectedRoutes,
        [Parameter(Mandatory = $true)] [int64] $Iterations,
        [string] $Label = 'REPORT'
    )

    $result = [ordered]@{ evidence_complete = $false; reason = $null }
    if ($WarmSamples -is [string] -or $WarmSamples -is [System.Collections.IDictionary]) {
        $result.reason = "$Label-warm-samples-invalid"
        return [pscustomobject] $result
    }
    $samples = @($WarmSamples)
    if ([decimal] $samples.Count -ne ([decimal] $ExpectedRoutes.Count * [decimal] $Iterations)) {
        $result.reason = "$Label-warm-sample-count-invalid"
        return [pscustomobject] $result
    }

    $baseFields = @(
        'name', 'ok', 'status', 'elapsed_ms', 'api_calls', 'request_id',
        'upstream_attempts', 'upstream_ms', 'source_cache', 'image_cache', 'prefetch'
    )
    $failureFields = @($baseFields + 'error')
    foreach ($sample in $samples) {
        if ($null -eq $sample) {
            $result.reason = "$Label-warm-sample-null"
            return [pscustomobject] $result
        }
        $name = (Get-PerformanceEvidenceMember -Object $sample -Name 'name').value
        $ok = (Get-PerformanceEvidenceMember -Object $sample -Name 'ok').value
        if ($name -isnot [string] -or $ExpectedRoutes -cnotcontains $name) {
            $result.reason = "$Label-warm-sample-route-invalid"
            return [pscustomobject] $result
        }
        if ($ok -isnot [bool]) {
            $result.reason = "$Label-warm-sample-ok-invalid:$name"
            return [pscustomobject] $result
        }
        $expectedFields = if ($ok) { $baseFields } else { $failureFields }
        if (-not (Test-PerformanceEvidenceExactFieldSet -Object $sample -ExpectedFields $expectedFields)) {
            $result.reason = "$Label-warm-sample-schema-invalid:$name"
            return [pscustomobject] $result
        }

        $status = (Get-PerformanceEvidenceMember -Object $sample -Name 'status').value
        $elapsed = (Get-PerformanceEvidenceMember -Object $sample -Name 'elapsed_ms').value
        $apiCalls = (Get-PerformanceEvidenceMember -Object $sample -Name 'api_calls').value
        if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value $status) -or [int64] $status -gt 599 -or
            ($ok -and ([int64] $status -lt 200 -or [int64] $status -ge 300)) -or
            (-not $ok -and [int64] $status -ge 200 -and [int64] $status -lt 300)
        ) {
            $result.reason = "$Label-warm-sample-status-invalid:$name"
            return [pscustomobject] $result
        }
        if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value $elapsed)) {
            $result.reason = "$Label-warm-sample-elapsed-invalid:$name"
            return [pscustomobject] $result
        }
        if ($null -ne $apiCalls -and -not (Test-PerformanceEvidenceNonNegativeInteger -Value $apiCalls)) {
            $result.reason = "$Label-warm-sample-api-calls-invalid:$name"
            return [pscustomobject] $result
        }
        if (-not $ok -and $null -ne $apiCalls) {
            $result.reason = "$Label-warm-sample-failed-api-calls-invalid:$name"
            return [pscustomobject] $result
        }
        foreach ($field in @('request_id', 'upstream_attempts', 'upstream_ms', 'source_cache', 'image_cache', 'prefetch')) {
            if ((Get-PerformanceEvidenceMember -Object $sample -Name $field).value -isnot [string]) {
                $result.reason = "$Label-warm-sample-diagnostic-invalid:${name}:$field"
                return [pscustomobject] $result
            }
        }
        if (-not $ok) {
            $errorValue = (Get-PerformanceEvidenceMember -Object $sample -Name 'error').value
            if ($errorValue -isnot [string] -or [string]::IsNullOrWhiteSpace($errorValue)) {
                $result.reason = "$Label-warm-sample-error-invalid:$name"
                return [pscustomobject] $result
            }
        }
    }

    foreach ($route in $ExpectedRoutes) {
        $routeSamples = @($samples | Where-Object {
            (Get-PerformanceEvidenceMember -Object $_ -Name 'name').value -ceq $route
        })
        if ([int64] $routeSamples.Count -ne $Iterations) {
            $result.reason = "$Label-warm-route-sample-count-invalid:$route"
            return [pscustomobject] $result
        }
        $successfulSamples = @($routeSamples | Where-Object {
            (Get-PerformanceEvidenceMember -Object $_ -Name 'ok').value -eq $true
        })
        $times = [int64[]] @($successfulSamples | ForEach-Object {
            [int64] (Get-PerformanceEvidenceMember -Object $_ -Name 'elapsed_ms').value
        })
        [decimal] $upstreamCalls = 0
        foreach ($sample in $successfulSamples) {
            $apiCalls = (Get-PerformanceEvidenceMember -Object $sample -Name 'api_calls').value
            if ($null -ne $apiCalls) { $upstreamCalls += [decimal] $apiCalls }
        }
        if ($upstreamCalls -gt [int64]::MaxValue) {
            $result.reason = "$Label-warm-upstream-calls-overflow:$route"
            return [pscustomobject] $result
        }
        $calculated = [ordered]@{
            samples = [int64] $routeSamples.Count
            successful = [int64] $successfulSamples.Count
            failed = [int64] ($routeSamples.Count - $successfulSamples.Count)
            median_ms = Get-PerformanceEvidencePercentile -Values $times -Percentile 0.50
            p95_ms = $(if ($times.Count -ge 40) { Get-PerformanceEvidencePercentile -Values $times -Percentile 0.95 } else { $null })
            p99_ms = $(if ($times.Count -ge 100) { Get-PerformanceEvidencePercentile -Values $times -Percentile 0.99 } else { $null })
            max_ms = $(if ($times.Count -gt 0) { [int64] ($times | Measure-Object -Maximum).Maximum } else { $null })
            upstream_calls = [int64] $upstreamCalls
        }
        $summaryState = Get-PerformanceEvidenceMember -Object $WarmSummary -Name $route
        foreach ($field in $calculated.Keys) {
            $summaryValue = (Get-PerformanceEvidenceMember -Object $summaryState.value -Name $field).value
            if ($null -eq $calculated[$field]) {
                if ($null -ne $summaryValue) {
                    $result.reason = "$Label-warm-summary-mismatch:${route}:$field"
                    return [pscustomobject] $result
                }
            } elseif ($null -eq $summaryValue -or [int64] $summaryValue -ne [int64] $calculated[$field]) {
                $result.reason = "$Label-warm-summary-mismatch:${route}:$field"
                return [pscustomobject] $result
            }
        }
    }

    $result.evidence_complete = $true
    $result.reason = 'complete'
    return [pscustomobject] $result
}

function Resolve-PerformanceReportComparisonEvidence {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)] $Report,
        [Parameter(Mandatory = $true)] [string[]] $RequiredEnvironmentFields,
        [Parameter(Mandatory = $true)] [string[]] $ExpectedRoutes,
        [Parameter(Mandatory = $true)] [string[]] $RequiredMetricFields,
        [string] $Label = 'REPORT'
    )

    $result = [ordered]@{ evidence_complete = $false; reason = $null; label = $Label }
    $environmentState = Get-PerformanceEvidenceMember -Object $Report -Name 'environment'
    $warmSummaryState = Get-PerformanceEvidenceMember -Object $Report -Name 'warm_summary'
    $warmSamplesState = Get-PerformanceEvidenceMember -Object $Report -Name 'warm_samples'
    if (-not $environmentState.exists -or $null -eq $environmentState.value) {
        $result.reason = "$Label-environment-missing"
        return [pscustomobject] $result
    }
    if (-not $warmSummaryState.exists -or $null -eq $warmSummaryState.value) {
        $result.reason = "$Label-warm-summary-missing"
        return [pscustomobject] $result
    }
    if (-not $warmSamplesState.exists -or $null -eq $warmSamplesState.value) {
        $result.reason = "$Label-warm-samples-missing"
        return [pscustomobject] $result
    }
    if (-not (Test-PerformanceEvidenceExactFieldSet `
        -Object $environmentState.value -ExpectedFields $RequiredEnvironmentFields)) {
        $result.reason = "$Label-environment-schema-invalid"
        return [pscustomobject] $result
    }

    foreach ($field in $RequiredEnvironmentFields) {
        $state = Get-PerformanceEvidenceMember -Object $environmentState.value -Name $field
        if (-not $state.exists) {
            $result.reason = "$Label-environment-field-missing:$field"
            return [pscustomobject] $result
        }
    }

    foreach ($field in @(
        'script_sha256', 'local_source_performance_evidence_sha256',
        'local_source_compose_sha256', 'local_source_index_sha256',
        'local_source_dockerfile_sha256', 'local_source_entrypoint_sha256',
        'runtime_prefetch_policy_sha256', 'runtime_cache_policy_sha256'
    )) {
        $value = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name $field).value
        if (-not (Test-PerformanceEvidenceSha256 -Value $value)) {
            $result.reason = "$Label-environment-sha256-invalid:$field"
            return [pscustomobject] $result
        }
    }

    foreach ($field in @('base_url', 'album_id', 'chapter_id', 'powershell', 'script_version', 'api_version', 'php_version')) {
        $value = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name $field).value
        if ($value -isnot [string] -or [string]::IsNullOrWhiteSpace($value)) {
            $result.reason = "$Label-environment-string-invalid:$field"
            return [pscustomobject] $result
        }
    }
    $baseUrl = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'base_url').value
    $baseUri = $null
    if (-not [uri]::TryCreate([string] $baseUrl, [UriKind]::Absolute, [ref] $baseUri) -or
        @('http', 'https') -cnotcontains $baseUri.Scheme -or
        -not [string]::IsNullOrEmpty($baseUri.UserInfo) -or
        -not [string]::IsNullOrEmpty($baseUri.Query) -or
        -not [string]::IsNullOrEmpty($baseUri.Fragment)
    ) {
        $result.reason = "$Label-base-url-invalid"
        return [pscustomobject] $result
    }
    foreach ($field in @('album_id', 'chapter_id')) {
        $value = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name $field).value
        if ($value -notmatch '^\d{1,20}$') {
            $result.reason = "$Label-id-invalid:$field"
            return [pscustomobject] $result
        }
    }
    $declaredWorkerCount = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'local_source_compose_declared_worker_count').value
    if (-not (Test-PerformanceEvidencePositiveInteger -Value $declaredWorkerCount)) {
        $result.reason = "$Label-local-declared-worker-count-invalid"
        return [pscustomobject] $result
    }
    $localApcShmSize = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'local_source_entrypoint_apc_shm_size').value
    if ($localApcShmSize -isnot [string] -or $localApcShmSize -notmatch '^[1-9][0-9]*[KMG]?$') {
        $result.reason = "$Label-local-apcu-size-invalid"
        return [pscustomobject] $result
    }

    $measurementOrigin = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'measurement_run_id_origin').value
    $measurementRunId = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'measurement_run_id').value
    if ($measurementOrigin -cne 'script-generated' -or
        $measurementRunId -isnot [string] -or
        $measurementRunId -notmatch '^[A-Za-z0-9._-]{1,64}$'
    ) {
        $result.reason = "$Label-measurement-origin-invalid"
        return [pscustomobject] $result
    }

    foreach ($field in @('asserted_network_condition_id', 'asserted_resource_profile_id')) {
        $value = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name $field).value
        if (-not (Test-PerformanceEvidenceSafeAssertion -Value $value)) {
            $result.reason = "$Label-assertion-unverified:$field"
            return [pscustomobject] $result
        }
    }

    $prefetchPolicy = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'runtime_prefetch_policy').value
    $prefetchPolicyHash = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'runtime_prefetch_policy_sha256').value
    $cachePolicy = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'runtime_cache_policy').value
    $cachePolicyHash = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'runtime_cache_policy_sha256').value
    if ((Get-PerformanceEvidenceObjectSha256 -Value $prefetchPolicy) -cne ([string] $prefetchPolicyHash).ToLowerInvariant()) {
        $result.reason = "$Label-prefetch-policy-fingerprint-mismatch"
        return [pscustomobject] $result
    }
    if ((Get-PerformanceEvidenceObjectSha256 -Value $cachePolicy) -cne ([string] $cachePolicyHash).ToLowerInvariant()) {
        $result.reason = "$Label-cache-policy-fingerprint-mismatch"
        return [pscustomobject] $result
    }
    $prefetchPolicyFields = @(
        'default_pages', 'high_priority_pages', 'next_chapter_pages',
        'wall_budget_ms', 'byte_budget', 'max_active', 'low_memory_policy'
    )
    $cachePolicyFields = @(
        'page_cache_enabled', 'page_cache_ttl_seconds', 'max_item_bytes',
        'page_cache_min_free_bytes', 'page_cache_min_free_ratio',
        'prefetch_min_free_bytes', 'prefetch_min_free_ratio'
    )
    $actualPrefetchPolicyFields = @(Get-PerformanceEvidenceMemberNames -Object $prefetchPolicy | Sort-Object)
    $actualCachePolicyFields = @(Get-PerformanceEvidenceMemberNames -Object $cachePolicy | Sort-Object)
    if (($actualPrefetchPolicyFields -join "`n") -cne (@($prefetchPolicyFields | Sort-Object) -join "`n")) {
        $result.reason = "$Label-prefetch-policy-schema-invalid"
        return [pscustomobject] $result
    }
    if (($actualCachePolicyFields -join "`n") -cne (@($cachePolicyFields | Sort-Object) -join "`n")) {
        $result.reason = "$Label-cache-policy-schema-invalid"
        return [pscustomobject] $result
    }
    foreach ($field in @($prefetchPolicyFields | Where-Object { $_ -ne 'low_memory_policy' })) {
        if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value (Get-PerformanceEvidenceMember -Object $prefetchPolicy -Name $field).value)) {
            $result.reason = "$Label-prefetch-policy-value-invalid:$field"
            return [pscustomobject] $result
        }
    }
    $lowMemoryPolicy = (Get-PerformanceEvidenceMember -Object $prefetchPolicy -Name 'low_memory_policy').value
    if ($lowMemoryPolicy -isnot [string] -or [string]::IsNullOrWhiteSpace($lowMemoryPolicy)) {
        $result.reason = "$Label-prefetch-policy-value-invalid:low_memory_policy"
        return [pscustomobject] $result
    }
    $pageCacheEnabled = (Get-PerformanceEvidenceMember -Object $cachePolicy -Name 'page_cache_enabled').value
    if ($pageCacheEnabled -isnot [bool]) {
        $result.reason = "$Label-cache-policy-value-invalid:page_cache_enabled"
        return [pscustomobject] $result
    }
    foreach ($field in @($cachePolicyFields | Where-Object { $_ -ne 'page_cache_enabled' })) {
        if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value (Get-PerformanceEvidenceMember -Object $cachePolicy -Name $field).value)) {
            $result.reason = "$Label-cache-policy-value-invalid:$field"
            return [pscustomobject] $result
        }
    }
    $prefetchConfig = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'prefetch_config').value
    $cacheConfig = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'cache_policy').value
    if ($null -eq $prefetchConfig -or $null -eq $cacheConfig) {
        $result.reason = "$Label-runtime-policy-source-missing"
        return [pscustomobject] $result
    }
    foreach ($field in @($prefetchPolicyFields | Where-Object { $_ -ne 'low_memory_policy' })) {
        $configValue = (Get-PerformanceEvidenceMember -Object $prefetchConfig -Name $field).value
        $policyValue = (Get-PerformanceEvidenceMember -Object $prefetchPolicy -Name $field).value
        if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value $configValue) -or [int64] $configValue -ne [int64] $policyValue) {
            $result.reason = "$Label-prefetch-policy-source-mismatch:$field"
            return [pscustomobject] $result
        }
    }
    $configLowMemoryPolicy = (Get-PerformanceEvidenceMember -Object $prefetchConfig -Name 'low_memory_policy').value
    if ($configLowMemoryPolicy -isnot [string] -or $configLowMemoryPolicy -cne $lowMemoryPolicy) {
        $result.reason = "$Label-prefetch-policy-source-mismatch:low_memory_policy"
        return [pscustomobject] $result
    }
    $configPageCacheEnabled = (Get-PerformanceEvidenceMember -Object $cacheConfig -Name 'page_cache_enabled').value
    if ($configPageCacheEnabled -isnot [bool] -or $configPageCacheEnabled -ne $pageCacheEnabled) {
        $result.reason = "$Label-cache-policy-source-mismatch:page_cache_enabled"
        return [pscustomobject] $result
    }
    foreach ($field in @($cachePolicyFields | Where-Object { $_ -ne 'page_cache_enabled' })) {
        $configValue = (Get-PerformanceEvidenceMember -Object $cacheConfig -Name $field).value
        $policyValue = (Get-PerformanceEvidenceMember -Object $cachePolicy -Name $field).value
        if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value $configValue) -or [int64] $configValue -ne [int64] $policyValue) {
            $result.reason = "$Label-cache-policy-source-mismatch:$field"
            return [pscustomobject] $result
        }
    }

    $runtimeKind = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'asserted_runtime_kind').value
    if ($runtimeKind -isnot [string] -or @('docker', 'local-fixture', 'external') -cnotcontains $runtimeKind) {
        $result.reason = "$Label-runtime-kind-unverified"
        return [pscustomobject] $result
    }
    $sourceBinding = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'asserted_runtime_source_binding').value
    if ($sourceBinding -isnot [string] -or @('local-process', 'docker-image', 'external-verified') -cnotcontains $sourceBinding) {
        $result.reason = "$Label-runtime-source-binding-unverified"
        return [pscustomobject] $result
    }
    $validRuntimeBinding = ($runtimeKind -ceq 'local-fixture' -and $sourceBinding -ceq 'local-process') -or
        ($runtimeKind -ceq 'docker' -and $sourceBinding -ceq 'docker-image') -or
        ($runtimeKind -ceq 'external' -and $sourceBinding -ceq 'external-verified')
    if (-not $validRuntimeBinding) {
        $result.reason = "$Label-runtime-source-binding-mismatch"
        return [pscustomobject] $result
    }
    $runtimeImageDigest = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'asserted_runtime_image_digest').value
    if ($sourceBinding -ceq 'local-process') {
        if ($null -ne $runtimeImageDigest -and -not [string]::IsNullOrWhiteSpace([string] $runtimeImageDigest)) {
            $result.reason = "$Label-runtime-image-digest-unexpected"
            return [pscustomobject] $result
        }
    } elseif (-not (Test-PerformanceEvidenceSha256 -Value $runtimeImageDigest -WithPrefix)) {
        $result.reason = "$Label-runtime-image-digest-invalid"
        return [pscustomobject] $result
    }
    $workerCount = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'asserted_actual_worker_count').value
    if (-not (Test-PerformanceEvidencePositiveInteger -Value $workerCount)) {
        $result.reason = "$Label-actual-worker-count-invalid"
        return [pscustomobject] $result
    }
    $apcuBytes = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'runtime_apcu_total_memory_bytes').value
    if (-not (Test-PerformanceEvidencePositiveInteger -Value $apcuBytes)) {
        $result.reason = "$Label-runtime-apcu-capacity-invalid"
        return [pscustomobject] $result
    }

    $iterations = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'iterations').value
    $warmupIterations = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'warmup_iterations').value
    $concurrency = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'concurrency').value
    $requestTimeout = (Get-PerformanceEvidenceMember -Object $environmentState.value -Name 'request_timeout_seconds').value
    if (-not (Test-PerformanceEvidencePositiveInteger -Value $iterations) -or [int64] $iterations -lt 100 -or
        -not (Test-PerformanceEvidenceNonNegativeInteger -Value $warmupIterations) -or
        -not (Test-PerformanceEvidencePositiveInteger -Value $concurrency) -or
        -not (Test-PerformanceEvidencePositiveInteger -Value $requestTimeout)
    ) {
        $result.reason = "$Label-measurement-counts-invalid"
        return [pscustomobject] $result
    }

    $actualRoutes = @(Get-PerformanceEvidenceMemberNames -Object $warmSummaryState.value)
    if ($actualRoutes.Count -ne $ExpectedRoutes.Count) {
        $result.reason = "$Label-warm-route-set-mismatch"
        return [pscustomobject] $result
    }
    foreach ($actualRoute in $actualRoutes) {
        if ($ExpectedRoutes -cnotcontains $actualRoute) {
            $result.reason = "$Label-warm-route-set-mismatch"
            return [pscustomobject] $result
        }
    }
    foreach ($route in $ExpectedRoutes) {
        $routeState = Get-PerformanceEvidenceMember -Object $warmSummaryState.value -Name $route
        if (-not $routeState.exists -or $null -eq $routeState.value) {
            $result.reason = "$Label-warm-route-missing:$route"
            return [pscustomobject] $result
        }
        if (-not (Test-PerformanceEvidenceExactFieldSet `
            -Object $routeState.value -ExpectedFields $RequiredMetricFields)) {
            $result.reason = "$Label-warm-metric-schema-invalid:$route"
            return [pscustomobject] $result
        }
        foreach ($metric in $RequiredMetricFields) {
            $metricState = Get-PerformanceEvidenceMember -Object $routeState.value -Name $metric
            if (-not $metricState.exists) {
                $result.reason = "$Label-warm-metric-missing:${route}:$metric"
                return [pscustomobject] $result
            }
        }
        $samples = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'samples').value
        $successful = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'successful').value
        $failed = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'failed').value
        $median = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'median_ms').value
        $p95 = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'p95_ms').value
        $p99 = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'p99_ms').value
        $maximum = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'max_ms').value
        $upstreamCalls = (Get-PerformanceEvidenceMember -Object $routeState.value -Name 'upstream_calls').value
        foreach ($pair in @(
            [pscustomobject]@{ Name = 'samples'; Value = $samples },
            [pscustomobject]@{ Name = 'successful'; Value = $successful },
            [pscustomobject]@{ Name = 'failed'; Value = $failed },
            [pscustomobject]@{ Name = 'median_ms'; Value = $median },
            [pscustomobject]@{ Name = 'p95_ms'; Value = $p95 },
            [pscustomobject]@{ Name = 'p99_ms'; Value = $p99 },
            [pscustomobject]@{ Name = 'max_ms'; Value = $maximum },
            [pscustomobject]@{ Name = 'upstream_calls'; Value = $upstreamCalls }
        )) {
            if (-not (Test-PerformanceEvidenceNonNegativeInteger -Value $pair.Value)) {
                $result.reason = "$Label-warm-metric-invalid:${route}:$($pair.Name)"
                return [pscustomobject] $result
            }
        }
        try {
            if ([int64] $samples -ne [int64] $iterations -or
                [int64] $successful -lt 100 -or
                [int64] $successful -gt [int64]::MaxValue - [int64] $failed -or
                [int64] $samples -ne ([int64] $successful + [int64] $failed) -or
                [int64] $median -gt [int64] $p95 -or
                [int64] $p95 -gt [int64] $p99 -or
                [int64] $p99 -gt [int64] $maximum
            ) {
                $result.reason = "$Label-warm-metric-relationship-invalid:$route"
                return [pscustomobject] $result
            }
        } catch {
            $result.reason = "$Label-warm-metric-relationship-invalid:$route"
            return [pscustomobject] $result
        }
    }

    $rawWarmEvidence = Resolve-PerformanceRawWarmEvidence `
        -WarmSamples $warmSamplesState.value -WarmSummary $warmSummaryState.value `
        -ExpectedRoutes $ExpectedRoutes -Iterations ([int64] $iterations) -Label $Label
    if (-not $rawWarmEvidence.evidence_complete) {
        $result.reason = $rawWarmEvidence.reason
        return [pscustomobject] $result
    }

    $result.evidence_complete = $true
    $result.reason = 'complete'
    return [pscustomobject] $result
}

function Resolve-PerformanceComparisonEvidence {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)] $BeforeReport,
        [Parameter(Mandatory = $true)] $AfterReport,
        [Parameter(Mandatory = $true)] [string[]] $RequiredEnvironmentFields,
        [Parameter(Mandatory = $true)] [string[]] $EqualityEnvironmentFields,
        [Parameter(Mandatory = $true)] [string[]] $ExpectedRoutes,
        [Parameter(Mandatory = $true)] [string[]] $RequiredMetricFields
    )

    $result = [ordered]@{
        comparable = $false
        evidence_complete = $false
        reason = $null
        before = $null
        after = $null
    }
    $beforeEvidence = Resolve-PerformanceReportComparisonEvidence `
        -Report $BeforeReport -RequiredEnvironmentFields $RequiredEnvironmentFields `
        -ExpectedRoutes $ExpectedRoutes -RequiredMetricFields $RequiredMetricFields -Label 'BEFORE'
    $result.before = $beforeEvidence
    if (-not $beforeEvidence.evidence_complete) {
        $result.reason = $beforeEvidence.reason
        return [pscustomobject] $result
    }
    $afterEvidence = Resolve-PerformanceReportComparisonEvidence `
        -Report $AfterReport -RequiredEnvironmentFields $RequiredEnvironmentFields `
        -ExpectedRoutes $ExpectedRoutes -RequiredMetricFields $RequiredMetricFields -Label 'AFTER'
    $result.after = $afterEvidence
    if (-not $afterEvidence.evidence_complete) {
        $result.reason = $afterEvidence.reason
        return [pscustomobject] $result
    }
    $beforeEnvironment = (Get-PerformanceEvidenceMember -Object $BeforeReport -Name 'environment').value
    $afterEnvironment = (Get-PerformanceEvidenceMember -Object $AfterReport -Name 'environment').value
    foreach ($field in $EqualityEnvironmentFields) {
        $beforeValue = (Get-PerformanceEvidenceMember -Object $beforeEnvironment -Name $field).value
        $afterValue = (Get-PerformanceEvidenceMember -Object $afterEnvironment -Name $field).value
        if ([string] $beforeValue -cne [string] $afterValue) {
            $result.reason = "environment-mismatch:$field"
            return [pscustomobject] $result
        }
    }
    $beforeWarmSummary = (Get-PerformanceEvidenceMember -Object $BeforeReport -Name 'warm_summary').value
    $afterWarmSummary = (Get-PerformanceEvidenceMember -Object $AfterReport -Name 'warm_summary').value
    foreach ($route in $ExpectedRoutes) {
        $beforeRoute = (Get-PerformanceEvidenceMember -Object $beforeWarmSummary -Name $route).value
        $afterRoute = (Get-PerformanceEvidenceMember -Object $afterWarmSummary -Name $route).value
        $beforeRate = [decimal] (Get-PerformanceEvidenceMember -Object $beforeRoute -Name 'successful').value / [decimal] (Get-PerformanceEvidenceMember -Object $beforeRoute -Name 'samples').value
        $afterRate = [decimal] (Get-PerformanceEvidenceMember -Object $afterRoute -Name 'successful').value / [decimal] (Get-PerformanceEvidenceMember -Object $afterRoute -Name 'samples').value
        if ($afterRate -lt $beforeRate) {
            $result.reason = "success-rate-regressed:$route"
            return [pscustomobject] $result
        }
    }
    $result.comparable = $true
    $result.evidence_complete = $true
    $result.reason = 'complete-and-equal'
    return [pscustomobject] $result
}

function Resolve-PrefetchAttributionEvidence {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [bool] $MeasurementRunIdGenerated,
        [Parameter(Mandatory = $true)]
        [bool] $TestCacheScoped,
        [Parameter(Mandatory = $true)]
        [bool] $TriggerSucceeded,
        [AllowNull()]
        $AggregateBefore,
        [AllowNull()]
        $AggregateAfter,
        [ValidateRange(0, [int]::MaxValue)]
        [int] $FollowupHits = 0
    )

    $origin = if ($MeasurementRunIdGenerated) { 'script-generated' } else { 'caller-provided' }
    $result = [ordered]@{
        attribution_verified = $false
        origin = $origin
        reason = $null
        events_delta = $null
        utilization_ratio = $null
        waste_ratio = $null
    }

    if (-not $MeasurementRunIdGenerated) {
        $result.reason = 'caller-provided-run-id'
        return [pscustomobject] $result
    }
    if (-not $TestCacheScoped) {
        $result.reason = 'test-cache-not-scoped'
        return [pscustomobject] $result
    }
    if ($null -eq $AggregateBefore) {
        $result.reason = 'initial-aggregate-missing'
        return [pscustomobject] $result
    }

    foreach ($metric in @('events', 'scheduled', 'attempted', 'cache_hits', 'stored', 'bytes', 'wall_ms')) {
        $value = Get-PerformanceEvidenceInteger -Object $AggregateBefore -Name $metric
        if ($null -eq $value) {
            $result.reason = 'initial-aggregate-invalid'
            return [pscustomobject] $result
        }
        if ($value -ne 0) {
            $result.reason = 'initial-aggregate-not-zero'
            return [pscustomobject] $result
        }
    }
    foreach ($property in $AggregateBefore.PSObject.Properties) {
        if ($property.Name -eq 'skip_counts') { continue }
        $value = Get-PerformanceEvidenceInteger -Object $AggregateBefore -Name $property.Name
        if ($null -eq $value) {
            $result.reason = 'initial-aggregate-invalid'
            return [pscustomobject] $result
        }
        if ($value -ne 0) {
            $result.reason = 'initial-aggregate-not-zero'
            return [pscustomobject] $result
        }
    }
    if (-not (Test-PerformanceEvidenceSkipCountsEmpty -Aggregate $AggregateBefore)) {
        $result.reason = 'initial-skip-counts-not-empty'
        return [pscustomobject] $result
    }
    if (-not $TriggerSucceeded) {
        $result.reason = 'trigger-failed'
        return [pscustomobject] $result
    }

    if ($null -eq $AggregateAfter -or -not (Test-PerformanceEvidenceSkipCountsShape -Aggregate $AggregateAfter)) {
        $result.reason = 'final-aggregate-invalid'
        return [pscustomobject] $result
    }
    $beforeMetricNames = @($AggregateBefore.PSObject.Properties | Where-Object { $_.Name -ne 'skip_counts' } | ForEach-Object { $_.Name } | Sort-Object)
    $afterMetricNames = @($AggregateAfter.PSObject.Properties | Where-Object { $_.Name -ne 'skip_counts' } | ForEach-Object { $_.Name } | Sort-Object)
    if (($beforeMetricNames -join "`n") -cne ($afterMetricNames -join "`n")) {
        $result.reason = 'final-aggregate-invalid'
        return [pscustomobject] $result
    }
    foreach ($metric in $beforeMetricNames) {
        $beforeValue = Get-PerformanceEvidenceInteger -Object $AggregateBefore -Name $metric
        $afterValue = Get-PerformanceEvidenceInteger -Object $AggregateAfter -Name $metric
        if ($null -eq $beforeValue -or $null -eq $afterValue) {
            $result.reason = 'final-aggregate-invalid'
            return [pscustomobject] $result
        }
        if ($afterValue -lt $beforeValue) {
            $result.reason = 'final-aggregate-counter-regressed'
            return [pscustomobject] $result
        }
    }

    $eventsBefore = Get-PerformanceEvidenceInteger -Object $AggregateBefore -Name 'events'
    $eventsAfter = Get-PerformanceEvidenceInteger -Object $AggregateAfter -Name 'events'
    $result.events_delta = [int64] $eventsAfter - [int64] $eventsBefore
    if (-not (Test-PerformanceEvidenceSkipCountsShape -Aggregate $AggregateAfter -MaximumTotal $result.events_delta)) {
        $result.reason = 'final-aggregate-invalid'
        return [pscustomobject] $result
    }
    if ($result.events_delta -ne 1) {
        $result.reason = 'completed-event-delta-not-one'
        return [pscustomobject] $result
    }

    $result.attribution_verified = $true
    $result.reason = 'verified-single-event'
    $storedBefore = Get-PerformanceEvidenceInteger -Object $AggregateBefore -Name 'stored'
    $storedAfter = Get-PerformanceEvidenceInteger -Object $AggregateAfter -Name 'stored'
    if ($null -ne $storedBefore -and $null -ne $storedAfter) {
        $storedDelta = [int64] $storedAfter - [int64] $storedBefore
        if ($storedDelta -gt 0) {
            $observedUseful = [Math]::Min($storedDelta, [int64] $FollowupHits)
            $result.utilization_ratio = [Math]::Round($observedUseful / [double] $storedDelta, 4)
            $result.waste_ratio = [Math]::Round(1.0 - $result.utilization_ratio, 4)
        }
    }
    return [pscustomobject] $result
}
