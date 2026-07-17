# Upstream Retry Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the old API's proven transient-network recovery window without losing the current API's 12-second shared deadline and strict failure classification.

**Architecture:** `JmApiClient` keeps the current transport, shared `RequestContext` budget, domain health ordering, token regeneration, and fail-closed payload handling. Only the retry schedule changes: each ordered API domain gets up to three retryable attempts, production retries wait 300ms, and the shared default attempt ceiling becomes 15.

**Tech Stack:** PHP 8.3, cURL, PowerShell contract tests, Kotlin extension contracts.

**Workspace note:** Neither project contains `.git`; execute in place and do not invent worktrees or commits.

---

### Task 1: Specify the compatibility behavior (RED)

**Files:**
- Modify: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php`

- [x] **Step 1: Change the production-default assertion**

```php
assertSameValue(15, JmConfig::DEFAULT_MAX_UPSTREAM_ATTEMPTS, 'production default preserves three bounded attempts per domain');
```

- [x] **Step 2: Change retry-order fixtures to the desired per-domain order**

For network recovery, assert two transient failures followed by success all use `primary.test`. For the full window, provide 14 transient TLS results followed by success, use a 15-attempt context, and assert this exact host list:

```php
[
    'primary.test', 'primary.test', 'primary.test',
    'secondary.test', 'secondary.test', 'secondary.test',
    'one.test', 'one.test', 'one.test',
    'two.test', 'two.test', 'two.test',
    'three.test', 'three.test', 'three.test',
]
```

Apply the same first-domain retry expectation to `fetchScrambleId()` and retryable HTTP 502 fixtures. Keep bad JSON, decrypt, payload-shape, business-error, wall-budget, and attempt-budget assertions unchanged.

- [x] **Step 3: Run the focused test and verify RED**

```powershell
& D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe `
  -n `
  -d extension_dir=D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\ext `
  -d extension=curl `
  -d extension=openssl `
  D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php
```

Expected: FAIL first on the default value (`expected 15, got 10`) or the first per-domain host-order assertion. This proves the current implementation lacks the compatibility behavior.

### Task 2: Implement the minimal retry-policy fix (GREEN)

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`

- [x] **Step 1: Raise the bounded attempt default and version**

```php
public const APP_VERSION = '2026.07.17.3';
public const DEFAULT_MAX_UPSTREAM_ATTEMPTS = 15;
```

- [x] **Step 2: Use three attempts per ordered domain in both API methods**

Replace the duplicated two-pass domain array with one ordered array. In both `callJson()` and `fetchScrambleId()` use:

```php
foreach ($this->domainHealth->orderedDomains($this->baseUrls) as $baseUrl) {
    for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
        if ($retry > 0 && !$this->context->isTestMode()) usleep(300_000);
        $remainingMs = $this->beginUpstreamAttempt();
        if ($remainingMs === null) {
            $budgetDenied = true;
            break 2;
        }
        // Existing request, classification, decoding, and success code remains unchanged.
    }
}
```

For retryable network and HTTP failures, `continue` while another attempt remains on this domain and `break` only after its third failure. For HTTP 429, sleep for valid bounded `Retry-After`; otherwise the standard production retry delay applies on the next iteration. Non-retryable response/content failures still throw or use the existing scramble fallback immediately.

- [x] **Step 3: Run the focused runtime test and verify GREEN**

Run the Task 1 command.

Expected: `Upstream policy runtime checks passed.`

### Task 3: Synchronize deployment and static contracts

**Files:**
- Modify: `D:\jm\jmcomic-api-main\Dockerfile`
- Modify: `D:\jm\jmcomic-api-main\docker-compose.yml`
- Modify: `D:\jm\jmcomic-api-main\docker-entrypoint.sh`
- Modify: `D:\jm\jmcomic-api-main\README.md`
- Modify: `D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1`
- Modify: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1`
- Modify matching version contracts under `D:\jm\jmapi-extension\tests`

- [x] **Step 1: Replace current production version `.2` with `.3` only where the text denotes the deployable current version**

Do not rewrite historical `.2` benchmark evidence or its hashes.

- [x] **Step 2: Change deployment default attempt cap from 10 to 15**

```yaml
JM_MAX_UPSTREAM_ATTEMPTS: "${JM_MAX_UPSTREAM_ATTEMPTS:-15}"
```

Update contract descriptions to “three bounded attempts per domain”.

- [x] **Step 3: Update the retryable-502 fixture contract**

The primary fixture now receives three 502 requests before one secondary success, so assert four total attempts, primary count three, and secondary count one. Keep non-retryable payload assertions at exactly one request.

- [x] **Step 4: Run only affected contracts**

```powershell
& D:\jm\jmcomic-api-main\tests\docker-runtime-contract.ps1
& D:\jm\jmcomic-api-main\tests\adoption-hardening-contract.ps1
& D:\jm\jmcomic-api-main\tests\performance-policy-contract.ps1 -Area Verification
Get-ChildItem D:\jm\jmapi-extension\tests\*.ps1 | ForEach-Object { & $_.FullName }
```

Expected: every command exits 0. Do not run Docker fault matrices because Docker is unavailable on this host.

### Task 4: Verify syntax and document the exact delivery boundary

**Files:**
- Modify: `D:\jm\jmcomic-api-main\docs\bug-hunt-2026-07-17.md`
- Modify: `D:\jm\jmcomic-api-main\docs\performance-delivery-report.md`
- Modify: `D:\jm\jmcomic-api-main\docs\ai-delivery-prompt.md`

- [x] **Step 1: Run PHP lint and PowerShell AST parsing**

```powershell
& D:\jm\.tools\php-8.3.32-nts-Win32-vs16-x64\php.exe -n -l D:\jm\jmcomic-api-main\index.php
$errors = @()
Get-ChildItem D:\jm\jmcomic-api-main,D:\jm\jmapi-extension -Recurse -Filter *.ps1 | ForEach-Object {
  $tokens = $null; $parseErrors = $null
  [void][System.Management.Automation.Language.Parser]::ParseFile($_.FullName,[ref]$tokens,[ref]$parseErrors)
  $errors += $parseErrors
}
if ($errors.Count -ne 0) { throw ($errors | Out-String) }
```

Expected: `No syntax errors detected` and zero PowerShell parse errors.

- [x] **Step 2: Record evidence and limits**

Document that `.3` restores old retry compatibility under the existing wall budget; `.2` performance evidence remains historical and must not be relabeled `.3`; this machine's direct PHP cURL path is reset by the network, so user-server deployment is still required for final live validation.

- [x] **Step 3: Compute final hashes**

```powershell
Get-FileHash -Algorithm SHA256 `
  D:\jm\jmcomic-api-main\index.php, `
  D:\jm\jmcomic-api-main\docs\superpowers\specs\2026-07-17-upstream-retry-compatibility-design.md, `
  D:\jm\jmcomic-api-main\docs\superpowers\plans\2026-07-17-upstream-retry-compatibility.md
```

Record the current `index.php` hash in the delivery report and AI prompt. Do not claim Suwayomi is fixed until `.4` is deployed and the user confirms the live route.

### Task 5: Repair real weekly type-ID compatibility

**Files:**
- Modify: `D:\jm\jmcomic-api-main\index.php`
- Modify: `D:\jm\jmcomic-api-main\tests\upstream-policy-runtime.php`
- Modify: `D:\jm\jmcomic-api-main\tests\fixtures\upstream-router.php`
- Modify: `D:\jm\jmcomic-api-main\tests\fault-injection-runtime.ps1`

- [x] **Step 1: Reproduce the real schema**

Decrypt a live `/week` response with the normal token flow and record identifiers only. Expected evidence: numeric category `249`; safe type slugs `hanman`, `another`, `manga`.

- [x] **Step 2: Verify RED**

Invoke `JmService::normalizeWeekDefaultsPayload()` with category `249` and type `hanman`. Expected before fix: `JmException: Weekly defaults unavailable`.

- [x] **Step 3: Implement the narrow validator**

Keep category IDs numeric. Permit type IDs matching `^(?:\d{1,20}|[A-Za-z][A-Za-z0-9_-]{0,31})$` in payload normalization, cached-entry validation, and the external type filter only. Increment the API version to `2026.07.17.4`.

- [x] **Step 4: Verify GREEN through the complete list path**

Use a fixture whose `/week` returns `type_id=hanman`, then request `list=weekly&page=1&format=min`. Expected: HTTP 200, `X-JM-API-Version: 2026.07.17.4`, two upstream calls, and item name `Fixture Week 11/hanman`.
