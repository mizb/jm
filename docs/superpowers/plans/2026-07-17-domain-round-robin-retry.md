# Domain Round-Robin Retry Implementation Plan

状态：**本机可执行实施与聚焦验证已完成（2026-07-17）**。下方复选框保留为实施过程规范；Docker/真实 JM 网络与生产部署仍是外部验收门，不得据此标为已通过。

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Change retryable JM API requests from three consecutive attempts per domain to three health-ordered rounds across all domains, without changing the 15-attempt or 12-second global budgets.

**Architecture:** `JmApiClient::callJson()` and `fetchScrambleId()` freeze `DomainHealth::orderedDomains()` once, loop by retry round first and domain second, and apply the 300ms production delay only at round boundaries. HTTP 429 retains its own bounded wait; all existing fail-closed protocol handling remains inside the attempt body.

**Tech Stack:** PHP 8.3, PowerShell contracts, deterministic fake `UpstreamTransport`, Docker configuration text contracts.

**Workspace note:** Neither delivery directory contains `.git`; worktree and commit steps are not executable. The user explicitly approved inline execution in the current shared workspace.

---

### Task 1: Make retry-order tests fail

**Files:**
- Modify: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php:93-250`
- Test: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php`

- [ ] **Step 1: Change the two-attempt network expectations**

Update `assertNetworkFailover()` and the connect fixture so the expected hosts are:

```php
assertSameValue('primary.test', (string) parse_url($transport->seenUrls[0], PHP_URL_HOST), $category . ' starts on primary');
assertSameValue('secondary.test', (string) parse_url($transport->seenUrls[1], PHP_URL_HOST), $category . ' rotates within the first round');
```

- [ ] **Step 2: Change five-domain recovery expectations**

Use these exact host sequences:

```php
['primary.test', 'secondary.test', 'one.test', 'two.test', 'three.test', 'primary.test']
```

and:

```php
[
    'primary.test', 'secondary.test', 'one.test', 'two.test', 'three.test',
    'primary.test', 'secondary.test', 'one.test', 'two.test', 'three.test',
    'primary.test', 'secondary.test', 'one.test', 'two.test', 'three.test',
]
```

Apply the six-attempt sequence to both JSON and scramble tests.

- [ ] **Step 3: Change retryable 502 expectations**

For two domains and success on the third transport result, assert:

```php
['primary.test', 'secondary.test', 'primary.test']
```

- [ ] **Step 4: Run the focused runtime and observe RED**

Run:

```powershell
$php='D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe'
$ext='D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\ext'
& $php -n -d "extension_dir=$ext" -d extension=php_curl.dll -d extension=php_openssl.dll -d extension=php_mbstring.dll .\tests\upstream-policy-runtime.php
```

Expected: exit 1 with an expected `secondary.test` but actual `primary.test` host-order assertion. A syntax or fixture-construction error is not an acceptable RED.

### Task 2: Implement round-major scheduling

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php:2491-2679`
- Test: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php`

- [ ] **Step 1: Change `callJson()` loop nesting**

Replace the domain-major loop shell with:

```php
$orderedBaseUrls = $this->domainHealth->orderedDomains($this->baseUrls);
for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
    if ($retry > 0) $this->sleepBeforeTransientRetry();
    foreach ($orderedBaseUrls as $baseUrl) {
        $remainingMs = $this->beginUpstreamAttempt();
        if ($remainingMs === null) {
            $budgetDenied = true;
            break 2;
        }
        $ts = (string) $this->context->unixTime();
        $headers = array_merge([
            'Accept-Encoding: gzip, deflate',
            'User-Agent: ' . JmConfig::UA,
            'token: ' . md5($ts . JmConfig::TOKEN_SECRET),
            'tokenparam: ' . $ts . ',' . JmConfig::VERSION,
        ], $this->context->testHeaders());
        $url = rtrim($baseUrl, '/') . $urlPath;
        $result = $this->http->get($url, $headers, $remainingMs);
        $latencyMs = max(0, (int) ($result->timings['total_ms'] ?? 0));
        $this->requestCount++;
        $this->context->recordAttempt($result, $baseUrl);
    }
}
```

Keep the existing envelope decode, business-code check, decrypt, payload decode, domain-health success mark and return directly after this request setup; only their enclosing loop changes.

For transport failures, remove the same-domain sleep/continue branch and continue directly to the next inner-domain iteration:

```php
if (!$result->ok) {
    $category = CurlFailure::category($result->curlErrno);
    $lastFailure = ApiFailure::network($category . ': ' . ($result->curlError !== '' ? $result->curlError : 'network error'));
    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
    continue;
}
```

For retryable HTTP, preserve immediate failure for non-retryable statuses and wait only for 429:

```php
if ($httpFailure !== null) {
    $lastFailure = $httpFailure;
    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
    if (!$lastFailure->shouldRetry()) throw ApiFailure::publicException($lastFailure);
    if ($result->status === 429) {
        $delayMs = RetryAfter::delayMs(
            $result->headers['retry-after'] ?? null,
            $this->context->unixTime(),
            $this->context->budget()->remainingMs(),
        );
        if ($delayMs > 0) usleep($delayMs * 1000);
        else $this->sleepBeforeTransientRetry();
    }
    continue;
}
```

- [ ] **Step 2: Apply the same scheduler to scramble**

Use the identical `$orderedBaseUrls`, outer `$retry`, round-boundary delay and inner `$baseUrl` structure. Retryable transport/HTTP results continue to the next domain; non-retryable HTTP and malformed templates keep the current scramble fallback behavior.

- [ ] **Step 3: Run GREEN**

Run the Task 1 command.

Expected: `Upstream policy runtime checks passed.` and exit 0, including token regeneration, 429, wall/attempt budget and non-retryable one-request cases.

- [ ] **Step 4: Run PHP lint**

```powershell
& $php -n -l .\index.php
& $php -n -l .\tests\upstream-policy-runtime.php
```

Expected: two `No syntax errors detected` lines.

### Task 3: Update integration and static contracts

**Files:**
- Modify: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1:1486-1500`
- Modify: `D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1:80-140,1240-1260`
- Modify: `D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1:48-100`
- Test: the three PowerShell files above

- [ ] **Step 1: Replace old grouped-order wording and counts**

The 502 integration scenario must require one first-round request to the primary before secondary success, not three primary requests. Static contracts must require round-major loop structure and reject a domain-major outer loop.

- [ ] **Step 2: Add source-order assertions**

The performance contract must match:

```powershell
Assert-Matches $source 'orderedDomains[\s\S]*?for\s*\(\$retry[\s\S]*?foreach\s*\(\$orderedBaseUrls\s+as\s+\$baseUrl\)' 'API retries iterate rounds before health-ordered domains'
```

and retain the assertions for `DEFAULT_MAX_UPSTREAM_ATTEMPTS=15`, shared `UpstreamBudget`, token regeneration and non-retryable failure closure.

- [ ] **Step 3: Run focused contracts**

```powershell
& .\tests\performance-policy-contract.ps1 -Area RequestBudget
& .\tests\performance-policy-contract.ps1 -Area Verification
& .\tests\docker-runtime-contract.ps1
```

Expected: all three commands exit 0. Do not run Docker fault execution on this machine because no Docker runtime is available.

### Task 4: Version and documentation delivery

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php:26`
- Modify: `D:\jm\jmcomic-api-main\Dockerfile:4`
- Modify: `D:\jm\jmcomic-api-main\docker-compose.yml:10`
- Modify: `D:\jm\jmcomic-api-main\docker-entrypoint.sh:4`
- Modify: `D:\jm\jmcomic-api-main\.github\workflows\docker-build.yml:15`
- Modify: `D:\jm\jmcomic-api-main\README.md`
- Modify: `D:\jm\jmcomic-api-main\docs\performance-delivery-report.md`
- Modify: `D:\jm\jmcomic-api-main\docs\ai-delivery-prompt.md`
- Modify: current performance/retry design documents that call `.7` current
- Modify: `D:\jm\jmapi-extension\README.md`
- Modify: `D:\jm\jmapi-extension\docs\ai-delivery-prompt.md`
- Modify: version-reference assertions in API/extension contracts

- [ ] **Step 1: Set all current delivery references to `.8`**

Use exactly:

```php
public const APP_VERSION = '2026.07.17.8';
```

Historical `.1` through `.7` audit entries and their hashes must remain unchanged.

- [ ] **Step 2: Document the new retry order**

Current-state documentation must state:

```text
health-ordered A→B→C→D→E, up to three rounds; 300ms only between rounds; 429 retains bounded Retry-After; 15 attempts and 12 seconds unchanged
```

Do not claim a new performance percentage because `.8` has no production or identical-condition A/B run.

- [ ] **Step 3: Update the delivery hash table**

Recompute SHA-256 for every listed file after all edits. Add the new design and implementation plan rows. The report verifier must return zero mismatches.

### Task 5: Final verification

**Files:**
- Verify all files listed in Tasks 1-4

- [ ] **Step 1: Run relevant runtime and contracts**

```powershell
& $php -n -d "extension_dir=$ext" -d extension=php_curl.dll -d extension=php_openssl.dll -d extension=php_mbstring.dll .\tests\upstream-policy-runtime.php
& $php -n .\tests\prefetch-policy-runtime.php
& .\tests\performance-policy-contract.ps1 -Area RequestBudget
& .\tests\performance-policy-contract.ps1 -Area Domain
& .\tests\performance-policy-contract.ps1 -Area Verification
& .\tests\docker-runtime-contract.ps1
& .\tests\adoption-hardening-contract.ps1
```

Expected: every command exits 0.

- [ ] **Step 2: Run lightweight extension contract**

```powershell
Set-Location D:\jm\jmapi-extension
& .\tests\extension-contract.ps1
```

Expected: `JM API extension contract checks passed.` APK rebuild is not required because Kotlin source and manifest version do not change.

- [ ] **Step 3: Verify version, lint and hashes**

Expected final facts:

```text
API_VERSION=2026.07.17.8
PHP_LINT_ERRORS=0
DELIVERY_HASH_MISMATCHES=0
DOCKER_AVAILABLE=false
```

- [ ] **Step 4: Record the external production gate**

Deployment must verify `X-JM-API-Version: 2026.07.17.8`, container `index.php` SHA-256, Latest and the same chapter. A failure report must include one request-id log showing the round-major domain order.
