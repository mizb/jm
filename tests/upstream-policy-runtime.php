<?php
declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/index.php';
$sourceText = (string) file_get_contents($sourcePath);
if (!str_contains($sourceText, "defined('JM_API_LIBRARY_ONLY')") && !str_contains($sourceText, 'defined("JM_API_LIBRARY_ONLY")')) {
    throw new RuntimeException('index.php must guard its entry point with JM_API_LIBRARY_ONLY before policy tests can load the library.');
}
define('JM_API_LIBRARY_ONLY', true);
require $sourcePath;

putenv('JM_TEST_ALLOWED_HOSTS=primary.test,secondary.test,one.test,two.test,three.test,domain-config.test');

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function encryptedTestEnvelope(array $payload, array $requestHeaders): string
{
    $tokenParam = '';
    foreach ($requestHeaders as $header) {
        if (stripos($header, 'tokenparam:') === 0) {
            $tokenParam = trim(substr($header, strlen('tokenparam:')));
            break;
        }
    }
    $ts = explode(',', $tokenParam, 2)[0] ?? (string) time();
    $plain = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $pad = 16 - (strlen((string) $plain) % 16);
    $cipher = openssl_encrypt(
        (string) $plain . str_repeat(chr($pad), $pad),
        'AES-256-ECB',
        md5($ts . JmConfig::DATA_SECRET),
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    );
    return json_encode(['code' => 200, 'data' => base64_encode((string) $cipher)], JSON_UNESCAPED_SLASHES);
}

function encryptedDomainConfig(array $domains): string
{
    $plain = json_encode(['Server' => array_values($domains)], JSON_UNESCAPED_SLASHES);
    $pad = 16 - (strlen((string) $plain) % 16);
    $cipher = openssl_encrypt(
        (string) $plain . str_repeat(chr($pad), $pad),
        'AES-256-ECB',
        md5(JmConfig::DOMAIN_SECRET),
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    );
    return base64_encode((string) $cipher);
}

final class SequenceTransport implements UpstreamTransport
{
    /** @var list<callable(string,array,int):HttpResult> */
    private array $steps;
    public int $calls = 0;
    public array $seenHeaders = [];
    public array $seenTimeouts = [];
    public array $seenUrls = [];

    public function __construct(callable ...$steps) { $this->steps = $steps; }

    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult
    {
        $this->seenHeaders[] = $headers;
        $this->seenTimeouts[] = $timeoutMs;
        $this->seenUrls[] = $url;
        $step = $this->steps[min($this->calls, count($this->steps) - 1)];
        $this->calls++;
        return $step($url, $headers, $timeoutMs);
    }
}

function result(
    bool $ok,
    string $body = '',
    int $status = 0,
    array $headers = [],
    int $errno = 0,
    string $error = '',
): HttpResult {
    return new HttpResult($ok, $body, $status, $headers, $errno, $error, ['total_ms' => 1]);
}

function context(int $budgetMs = 1500, int $attempts = 6): RequestContext
{
    return RequestContext::forTest('policy-test', $budgetMs, $attempts);
}

assertSameValue(15, JmConfig::DEFAULT_MAX_UPSTREAM_ATTEMPTS, 'production default preserves three bounded attempts per domain');

function transientRequestId(): string
{
    $context = RequestContext::forTest('request-id-test', 1500, 1);
    return $context->requestId();
}

$firstTransientRequestId = transientRequestId();
gc_collect_cycles();
$secondTransientRequestId = transientRequestId();
if ($firstTransientRequestId === $secondTransientRequestId) {
    throw new RuntimeException('request_id must remain unique when PHP reuses an object id');
}

function assertThrows(callable $action, string $label): Throwable
{
    try { $action(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException($label . ': expected exception');
}

function assertNetworkFailover(int $errno, string $category): void
{
    $transport = new SequenceTransport(
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], $errno, $category . ' failed'),
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['category' => $category], $headers), 200),
    );
    $ctx = context();
    $client = JmApiClient::forTest($ctx, $transport, ['https://primary.test', 'https://secondary.test']);
    assertSameValue(['category' => $category], $client->callJson('/latest', ['page' => '0'])['data'], $category . ' failover payload');
    assertSameValue(2, $transport->calls, $category . ' failover attempts');
    assertSameValue('primary.test', (string) parse_url($transport->seenUrls[0], PHP_URL_HOST), $category . ' starts on primary');
    assertSameValue('primary.test', (string) parse_url($transport->seenUrls[1], PHP_URL_HOST), $category . ' retries the primary');
}

$connectThenSuccess = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_COULDNT_CONNECT, 'connect failed'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['items' => []], $headers), 200),
);
$ctx = context();
$client = JmApiClient::forTest($ctx, $connectThenSuccess, ['https://primary.test', 'https://secondary.test']);
$decoded = $client->callJson('/latest', ['page' => '0']);
assertSameValue(['items' => []], $decoded['data'], 'connect failure retries the primary and succeeds');
assertSameValue(2, $connectThenSuccess->calls, 'connect failure attempt count');
assertSameValue(2, $ctx->diagnostics()['upstream_attempts'], 'context attempt count');
assertSameValue('primary.test', (string) parse_url($connectThenSuccess->seenUrls[0], PHP_URL_HOST), 'connect first domain');
assertSameValue('primary.test', (string) parse_url($connectThenSuccess->seenUrls[1], PHP_URL_HOST), 'connect retries the primary domain');
assertNetworkFailover(CURLE_COULDNT_RESOLVE_HOST, 'dns');
assertNetworkFailover(CURLE_SSL_CONNECT_ERROR, 'tls');
assertNetworkFailover(CURLE_OPERATION_TIMEDOUT, 'timeout');
assertNetworkFailover(999, 'network');

$allDomainsTransientThenPrimaryRecovers = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'primary transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'secondary transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'third transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'fourth transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'fifth transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['recovered' => true], $headers), 200),
);
$ctx = context(1500, 6);
$client = JmApiClient::forTest(
    $ctx,
    $allDomainsTransientThenPrimaryRecovers,
    ['https://primary.test', 'https://secondary.test', 'https://one.test', 'https://two.test', 'https://three.test'],
);
assertSameValue(
    ['recovered' => true],
    $client->callJson('/latest', ['page' => '0'])['data'],
    'per-domain transient failures use the sixth bounded attempt on the secondary domain',
);
assertSameValue(6, $allDomainsTransientThenPrimaryRecovers->calls, 'bounded all-domain recovery attempt count');
$allDomainRecoveryHosts = array_map(
    static fn(string $url): string => (string) parse_url($url, PHP_URL_HOST),
    $allDomainsTransientThenPrimaryRecovers->seenUrls,
);
assertSameValue(
    ['primary.test', 'primary.test', 'primary.test', 'secondary.test', 'secondary.test', 'secondary.test'],
    $allDomainRecoveryHosts,
    'network failures preserve three bounded attempts per domain',
);

$fifteenAttemptCompatibilityWindow = new SequenceTransport(
    ...array_merge(
        array_fill(0, 14, fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'transient TLS failure')),
        [fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['compatibility_window' => true], $headers), 200)],
    ),
);
$ctx = context(1500, 15);
$client = JmApiClient::forTest(
    $ctx,
    $fifteenAttemptCompatibilityWindow,
    ['https://primary.test', 'https://secondary.test', 'https://one.test', 'https://two.test', 'https://three.test'],
);
assertSameValue(
    ['compatibility_window' => true],
    $client->callJson('/latest', ['page' => '0'])['data'],
    'transient failures may recover on the fifteenth bounded compatibility attempt',
);
assertSameValue(15, $fifteenAttemptCompatibilityWindow->calls, 'compatibility recovery remains bounded to fifteen attempts');
$compatibilityHosts = array_map(
    static fn(string $url): string => (string) parse_url($url, PHP_URL_HOST),
    $fifteenAttemptCompatibilityWindow->seenUrls,
);
assertSameValue(
    [
        'primary.test', 'primary.test', 'primary.test',
        'secondary.test', 'secondary.test', 'secondary.test',
        'one.test', 'one.test', 'one.test',
        'two.test', 'two.test', 'two.test',
        'three.test', 'three.test', 'three.test',
    ],
    $compatibilityHosts,
    'fifteen-attempt compatibility window uses three attempts per ordered domain',
);

$allScrambleDomainsTransientThenPrimaryRecovers = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'primary transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'secondary transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'third transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'fourth transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_SSL_CONNECT_ERROR, 'fifth transient TLS failure'),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, '<script>var scramble_id = 220981;</script>', 200),
);
$ctx = context(1500, 6);
$client = JmApiClient::forTest(
    $ctx,
    $allScrambleDomainsTransientThenPrimaryRecovers,
    ['https://primary.test', 'https://secondary.test', 'https://one.test', 'https://two.test', 'https://three.test'],
);
assertSameValue(
    '220981',
    $client->fetchScrambleId('350234'),
    'scramble uses the sixth bounded attempt on the secondary domain',
);
assertSameValue(6, $allScrambleDomainsTransientThenPrimaryRecovers->calls, 'bounded scramble recovery attempt count');
$scrambleRecoveryHosts = array_map(
    static fn(string $url): string => (string) parse_url($url, PHP_URL_HOST),
    $allScrambleDomainsTransientThenPrimaryRecovers->seenUrls,
);
assertSameValue(
    ['primary.test', 'primary.test', 'primary.test', 'secondary.test', 'secondary.test', 'secondary.test'],
    $scrambleRecoveryHosts,
    'scramble network failures preserve three bounded attempts per domain',
);

$retry502 = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['ok' => true], $headers), 200),
);
$ctx = context();
$client = JmApiClient::forTest($ctx, $retry502, ['https://primary.test', 'https://secondary.test']);
assertSameValue(['ok' => true], $client->callJson('/latest', ['page' => '0'])['data'], '502 retry policy succeeds');
assertSameValue(3, $retry502->calls, 'primary retries once before secondary');
assertSameValue('primary.test', (string) parse_url($retry502->seenUrls[0], PHP_URL_HOST), '502 first primary attempt');
assertSameValue('primary.test', (string) parse_url($retry502->seenUrls[1], PHP_URL_HOST), '502 second primary attempt');
assertSameValue('primary.test', (string) parse_url($retry502->seenUrls[2], PHP_URL_HOST), '502 third attempt remains on primary');

foreach (['seconds', 'date', 'invalid'] as $label) {
    $retryAfter = match ($label) {
        'seconds' => '1',
        'date' => gmdate('D, d M Y H:i:s', time() + 2) . ' GMT',
        default => 'invalid',
    };
    $rateLimited = new SequenceTransport(
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'rate limited', 429, ['retry-after' => $retryAfter]),
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['rate' => $label], $headers), 200),
    );
    $ctx = context(2500, 3);
    $client = JmApiClient::forTest($ctx, $rateLimited, ['https://primary.test', 'https://secondary.test']);
    $started = microtime(true);
    assertSameValue(['rate' => $label], $client->callJson('/latest', ['page' => '0'])['data'], '429 ' . $label . ' recovers');
    $elapsedMs = (int) round((microtime(true) - $started) * 1000);
    assertSameValue(2, $rateLimited->calls, '429 ' . $label . ' attempts');
    if ($elapsedMs > 2600) throw new RuntimeException('429 ' . $label . ' exceeded request budget');
    if (in_array($label, ['seconds', 'date'], true) && $elapsedMs < 700) throw new RuntimeException('429 ' . $label . ' did not respect Retry-After');
    if ($label === 'invalid' && $elapsedMs > 500) throw new RuntimeException('429 invalid Retry-After caused a sleep');
}

$now = time();
assertSameValue(1000, RetryAfter::delayMs('1', $now, 5000), 'Retry-After seconds');
$dateDelay = RetryAfter::delayMs(gmdate('D, d M Y H:i:s', $now + 2) . ' GMT', $now, 5000);
if ($dateDelay < 1000 || $dateDelay > 2000) throw new RuntimeException('Retry-After HTTP-date was not parsed');
assertSameValue(0, RetryAfter::delayMs('invalid', $now, 5000), 'invalid Retry-After');
assertSameValue(0, RetryAfter::delayMs('tomorrow', $now, 5000), 'non-HTTP-date Retry-After');
assertSameValue(500, RetryAfter::delayMs('10', $now, 500), 'Retry-After clamps to remaining budget');
assertSameValue(500, RetryAfter::delayMs(str_repeat('9', 64), $now, 500), 'huge Retry-After clamps without integer overflow');

assertSameValue('dns', CurlFailure::category(CURLE_COULDNT_RESOLVE_HOST), 'DNS curl category');
assertSameValue('connect', CurlFailure::category(CURLE_COULDNT_CONNECT), 'connect curl category');
assertSameValue('tls', CurlFailure::category(CURLE_SSL_CONNECT_ERROR), 'TLS curl category');
assertSameValue('timeout', CurlFailure::category(CURLE_OPERATION_TIMEDOUT), 'timeout curl category');

foreach (['bad-json', 'bad-encrypted', 'business-error'] as $failureKind) {
    $bodyFactory = match ($failureKind) {
        'bad-json' => fn(array $headers): string => '{bad-json',
        'bad-encrypted' => fn(array $headers): string => json_encode(['code' => 200, 'data' => 'not-base64!']),
        default => fn(array $headers): string => json_encode(['code' => 403, 'message' => 'business error', 'data' => '']),
    };
    $transport = new SequenceTransport(
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, $bodyFactory($headers), 200),
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['unexpected' => true], $headers), 200),
    );
    $ctx = context();
    $client = JmApiClient::forTest($ctx, $transport, ['https://primary.test', 'https://secondary.test']);
    assertThrows(fn() => $client->callJson('/latest', ['page' => '0']), $failureKind . ' must fail');
    assertSameValue(1, $transport->calls, $failureKind . ' must not rotate domains');
}

$slow = new SequenceTransport(
    function (string $url, array $headers, int $timeoutMs): HttpResult {
        usleep(($timeoutMs + 50) * 1000);
        return result(false, '', 0, [], CURLE_OPERATION_TIMEDOUT, 'timeout');
    },
);
$ctx = context(120, 6);
$client = JmApiClient::forTest($ctx, $slow, ['https://one.test', 'https://two.test', 'https://three.test']);
$started = microtime(true);
$deadlineFailure = assertThrows(fn() => $client->callJson('/latest', ['page' => '0']), 'deadline exhaustion');
$elapsedMs = (int) round((microtime(true) - $started) * 1000);
if ($elapsedMs > 300) throw new RuntimeException('shared deadline exceeded tolerance: ' . $elapsedMs . 'ms');
assertSameValue(true, $ctx->diagnostics()['deadline_exhausted'], 'deadline exhaustion diagnostics');
assertSameValue(
    JmException::class,
    $deadlineFailure::class,
    'deadline denial after a real transport failure preserves that failure',
);

$attemptBudgetTransport = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(
        true,
        encryptedTestEnvelope(['attempt_budget' => true], $headers),
        200,
    ),
);
$attemptBudgetContext = context(1500, 1);
$attemptBudgetClient = JmApiClient::forTest(
    $attemptBudgetContext,
    $attemptBudgetTransport,
    ['https://primary.test'],
);
assertSameValue(
    ['attempt_budget' => true],
    $attemptBudgetClient->callJson('/latest', ['page' => '0'])['data'],
    'first permitted upstream attempt succeeds',
);
$attemptBudgetError = assertThrows(
    fn() => $attemptBudgetClient->callJson('/latest', ['page' => '0']),
    'attempt budget denial raises a typed exception',
);
assertSameValue(
    UpstreamBudgetExhaustedException::class,
    $attemptBudgetError::class,
    'attempt budget denial raises a typed exception',
);
assertSameValue(true, $attemptBudgetError instanceof JmException, 'typed budget denial preserves public upstream error handling');
assertSameValue(502, $attemptBudgetError->getCode(), 'typed budget denial preserves the public 502 status');
assertSameValue('attempts', $attemptBudgetError->reason(), 'typed attempt budget denial retains its cause');
assertSameValue(1, $attemptBudgetTransport->calls, 'denied attempt never reaches the transport');

$attemptFailureTransport = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
);
$attemptFailureContext = context(1500, 1);
$attemptFailureClient = JmApiClient::forTest(
    $attemptFailureContext,
    $attemptFailureTransport,
    ['https://primary.test'],
);
$attemptFailureError = assertThrows(
    fn() => $attemptFailureClient->callJson('/latest', ['page' => '0']),
    'attempt denial after a real upstream failure preserves that failure',
);
assertSameValue(
    JmException::class,
    $attemptFailureError::class,
    'attempt denial after a real upstream failure is not reclassified as budget exhaustion',
);
assertSameValue(1, $attemptFailureTransport->calls, 'attempt failure fixture performs exactly one real request');

$clockValues = [1700000000, 1700000001, 1700000002];
$clock = function () use (&$clockValues): int { return array_shift($clockValues) ?? 1700000002; };
$tokenTransport = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, encryptedTestEnvelope(['token' => true], $headers), 200),
);
$ctx = RequestContext::forTest('token-test', 1500, 3, $clock);
$client = JmApiClient::forTest($ctx, $tokenTransport, ['https://primary.test', 'https://secondary.test']);
$client->callJson('/latest', ['page' => '0']);
$tokenParams = [];
foreach ($tokenTransport->seenHeaders as $headers) {
    foreach ($headers as $header) if (stripos($header, 'tokenparam:') === 0) $tokenParams[] = trim(substr($header, strlen('tokenparam:')));
}
if (count(array_unique($tokenParams)) !== 2) throw new RuntimeException('tokenparam must be regenerated for every attempt');

$scrambleMissing = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, '<html>missing</html>', 200),
);
$ctx = context();
$client = JmApiClient::forTest($ctx, $scrambleMissing, ['https://primary.test']);
assertSameValue((string) JmConfig::SCRAMBLE_220980, $client->fetchScrambleId('350234'), 'scramble template fallback');
assertSameValue(1, $scrambleMissing->calls, 'non-retryable scramble template does not rotate domains');

$scrambleClockValues = [1700000100, 1700000101];
$scrambleClock = function () use (&$scrambleClockValues): int { return array_shift($scrambleClockValues) ?? 1700000101; };
$scrambleTokenTransport = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, '<script>var scramble_id = 220981;</script>', 200),
);
$ctx = RequestContext::forTest('scramble-token-test', 1500, 3, $scrambleClock);
$client = JmApiClient::forTest($ctx, $scrambleTokenTransport, ['https://primary.test', 'https://secondary.test']);
assertSameValue('220981', $client->fetchScrambleId('350234'), 'scramble retry succeeds');
$scrambleTokenParams = [];
foreach ($scrambleTokenTransport->seenHeaders as $headers) {
    foreach ($headers as $header) if (stripos($header, 'tokenparam:') === 0) $scrambleTokenParams[] = trim(substr($header, strlen('tokenparam:')));
}
if (count(array_unique($scrambleTokenParams)) !== 2) throw new RuntimeException('scramble tokenparam must be regenerated for every attempt');

assertThrows(
    fn() => JmApiClient::forTest(context(), new SequenceTransport(fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, '', 200)), ['http://not-allowed.test']),
    'test base URL host must be allowlisted',
);

putenv('JM_TEST_CDN_BASE_URLS=http://primary.test,http://secondary.test');
$imageBudgetFailure = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
);
$imageBudgetFailureContext = context(1500, 1);
$imageBudgetFailureClient = JmApiClient::forTest(
    $imageBudgetFailureContext,
    $imageBudgetFailure,
    ['https://primary.test'],
);
$imageBudgetFailureError = assertThrows(
    fn() => $imageBudgetFailureClient->downloadImage('/media/photos/350234/00001.png'),
    'image retry denial after a real upstream failure preserves that failure',
);
assertSameValue(
    JmException::class,
    $imageBudgetFailureError::class,
    'image retry denial after a real upstream failure is not reclassified as budget exhaustion',
);
assertSameValue(1, $imageBudgetFailure->calls, 'image budget failure fixture performs exactly one real request');

$imageFailover = new SequenceTransport(
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'bad gateway', 502),
    fn(string $url, array $headers, int $timeoutMs): HttpResult => result(true, 'image-bytes', 200),
);
$ctx = context();
$client = JmApiClient::forTest($ctx, $imageFailover, ['https://primary.test']);
assertSameValue('image-bytes', $client->downloadImage('/media/photos/350234/00001.png'), 'test CDN failover payload');
assertSameValue(2, $imageFailover->calls, 'test CDN failover attempts');
assertSameValue('primary.test', (string) parse_url($imageFailover->seenUrls[0], PHP_URL_HOST), 'test CDN first base URL');
assertSameValue('secondary.test', (string) parse_url($imageFailover->seenUrls[1], PHP_URL_HOST), 'test CDN fallback base URL');
putenv('JM_TEST_CDN_BASE_URLS');

$weekDefaultsNormalizer = new ReflectionMethod(JmService::class, 'normalizeWeekDefaultsPayload');
$weekDefaultsNormalizer->setAccessible(true);
$realWeekDefaults = ['category_id' => '249', 'type_id' => 'hanman'];
assertSameValue(
    $realWeekDefaults,
    $weekDefaultsNormalizer->invoke(null, [
        'categories' => [['id' => 249, 'title' => 'category']],
        'type' => [['id' => 'hanman', 'title' => 'type']],
    ]),
    'real upstream integer category and alphanumeric weekly type ids are accepted',
);
$albumNormalizer = new ReflectionMethod(JmService::class, 'normalizeAlbumPayload');
$albumNormalizer->setAccessible(true);
assertSameValue(
    ['id' => '350234', 'name' => 'Fixture', 'author' => [], 'description' => '', 'image' => '',
        'total_views' => '0', 'likes' => '0', 'comment_total' => '0', 'tags' => [], 'works' => [],
        'actors' => [], 'related_list' => [], 'series' => [['id' => '350235', 'name' => '', 'sort' => '1']]],
    $albumNormalizer->invoke(null, [
        'id' => 350234,
        'name' => 'Fixture',
        'series' => [['id' => 350235]],
    ], '350234'),
    'integer album and series ids are normalized as canonical strings',
);
$listItemNormalizer = new ReflectionMethod(JmService::class, 'normalizeListItemPayload');
$listItemNormalizer->setAccessible(true);
assertSameValue(
    '350234',
    $listItemNormalizer->invoke(null, ['id' => 350234, 'name' => 'Fixture'])['id'],
    'integer list item id is normalized as a canonical string',
);
$pagedListNormalizer = new ReflectionMethod(JmService::class, 'normalizePagedListPayload');
$pagedListNormalizer->setAccessible(true);
assertSameValue(
    '350234',
    $pagedListNormalizer->invoke(null, ['content' => [], 'total' => 1, 'redirect_aid' => 350234], 'content', true)['redirect_aid'],
    'integer search redirect id is normalized as a canonical string',
);
assertSameValue(
    PHP_INT_MAX,
    $pagedListNormalizer->invoke(null, ['content' => [], 'total' => (string) PHP_INT_MAX], 'content')['total'],
    'largest representable non-negative list total remains valid',
);
foreach ([1.0, 1.9, -1, '-1', NAN, INF, (string) PHP_INT_MAX . '0'] as $invalidTotal) {
    assertThrows(
        fn() => $pagedListNormalizer->invoke(null, ['content' => [], 'total' => $invalidTotal], 'content'),
        'list total rejects fractions, negatives, non-finite numbers, and integer overflow',
    );
}
foreach ([true, 1.0] as $invalidId) {
    assertThrows(
        fn() => $albumNormalizer->invoke(null, ['id' => $invalidId, 'name' => 'Fixture'], '1'),
        'boolean and float album ids are rejected',
    );
    assertThrows(
        fn() => $listItemNormalizer->invoke(null, ['id' => $invalidId, 'name' => 'Fixture']),
        'boolean and float list item ids are rejected',
    );
    assertThrows(
        fn() => $pagedListNormalizer->invoke(null, ['content' => [], 'total' => 1, 'redirect_aid' => $invalidId], 'content', true),
        'boolean and float search redirect ids are rejected',
    );
    assertThrows(
        fn() => $weekDefaultsNormalizer->invoke(null, [
            'categories' => [['id' => $invalidId]],
            'type' => [['id' => 'hanman']],
        ]),
        'boolean and float weekly category ids are rejected',
    );
    assertThrows(
        fn() => $weekDefaultsNormalizer->invoke(null, [
            'categories' => [['id' => 249]],
            'type' => [['id' => $invalidId]],
        ]),
        'boolean and float weekly type ids are rejected',
    );
}
$weekEntryValidator = new ReflectionMethod(JmService::class, 'validatedWeekDefaultsEntry');
$weekEntryValidator->setAccessible(true);
$realWeekEntry = [
    'value' => $realWeekDefaults,
    'fetched_at' => 1_700_000_000,
    'fresh_until' => 1_700_000_060,
    'stale_until' => 1_700_000_120,
];
assertSameValue(
    $realWeekEntry,
    $weekEntryValidator->invoke(null, $realWeekEntry, 60, 60),
    'cached alphanumeric weekly type id remains valid',
);
assertSameValue('hanman', normalizeOptionalWeeklyId('hanman', true), 'weekly type slug query is accepted');
assertThrows(fn() => normalizeOptionalWeeklyId('hanman'), 'weekly category slug is rejected');
assertThrows(fn() => normalizeOptionalWeeklyId('bad/type', true), 'weekly type path separator is rejected');
assertThrows(fn() => normalizeOptionalWeeklyId(str_repeat('a', 33), true), 'overlong weekly type slug is rejected');

if (function_exists('apcu_enabled') && apcu_enabled()) {
    $leaseCache = new MemoryCache();
    $leaseKey = 'policy-cas-lease:' . bin2hex(random_bytes(6));
    assertSameValue(true, $leaseCache->tryAdd($leaseKey, 101, 30), 'integer lease can be acquired');
    assertSameValue(false, $leaseCache->compareAndDelete($leaseKey, 202), 'wrong lease owner cannot release');
    assertSameValue(101, $leaseCache->get($leaseKey), 'wrong owner leaves lease intact');
    assertSameValue(true, $leaseCache->compareAndDelete($leaseKey, 101), 'lease owner releases through CAS tombstone');
    assertSameValue(null, $leaseCache->get($leaseKey), 'released lease is absent');

    putenv('JM_DOMAIN_FRESH_TTL=60');
    putenv('JM_DOMAIN_STALE_TTL=60');
    putenv('JM_DOMAIN_REFRESH_FAILURE_TTL=60');
    putenv('JM_TEST_DOMAIN_SOURCE_URLS=https://domain-config.test/config');

    $domainNow = 1700010000;
    $domainClock = static function () use (&$domainNow): int { return $domainNow; };
    $domainContext = RequestContext::forTest('domain-policy', 1500, 3, $domainClock);
    $domainTransport = new SequenceTransport(
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(
            true,
            encryptedDomainConfig(['api-a.test', 'api-b.test']),
            200,
        ),
    );
    $domainResolver = new DomainResolver($domainContext, new MemoryCache(), $domainTransport);
    assertSameValue(JmConfig::API_DOMAINS, $domainResolver->resolveForRequest(), 'domain resolve uses immediate fallback before refresh');
    assertSameValue(0, $domainTransport->calls, 'domain resolve performs no remote I/O');

    $refreshMethod = new ReflectionMethod(DomainResolver::class, 'refreshWithinBudget');
    $refreshMethod->setAccessible(true);
    assertSameValue(true, $refreshMethod->invoke($domainResolver, 500), 'domain refresh stores valid dynamic domains');
    assertSameValue(1, $domainTransport->calls, 'domain refresh calls one source');
    assertSameValue(['api-a.test', 'api-b.test'], $domainResolver->resolveForRequest(), 'fresh dynamic domains are returned');
    assertSameValue('fresh', $domainResolver->diagnostics()['source'], 'fresh domain diagnostics');

    $domainNow += 61;
    assertSameValue(['api-a.test', 'api-b.test'], $domainResolver->resolveForRequest(), 'stale domains remain boundedly usable');
    assertSameValue('stale', $domainResolver->diagnostics()['source'], 'stale domain diagnostics');
    assertSameValue(1, $domainTransport->calls, 'stale resolve performs no remote I/O');

    $domainNow += 60;
    assertSameValue(JmConfig::API_DOMAINS, $domainResolver->resolveForRequest(), 'expired stale domains fall back to built-ins');
    assertSameValue('fallback', $domainResolver->diagnostics()['source'], 'expired domain diagnostics use fallback');

    $failedContext = RequestContext::forTest('domain-failure', 1500, 3, static fn(): int => 1700020000);
    $failedTransport = new SequenceTransport(
        fn(string $url, array $headers, int $timeoutMs): HttpResult => result(false, '', 0, [], CURLE_OPERATION_TIMEDOUT, 'timeout'),
    );
    $failedResolver = new DomainResolver($failedContext, new MemoryCache(), $failedTransport);
    assertSameValue(false, $refreshMethod->invoke($failedResolver, 500), 'failed domain refresh returns false');
    assertSameValue(false, $refreshMethod->invoke($failedResolver, 500), 'negative cache suppresses repeated refresh');
    assertSameValue(1, $failedTransport->calls, 'negative cache prevents repeated source calls');
    assertSameValue(true, $failedResolver->diagnostics()['refresh_suppressed'], 'negative cache is visible in diagnostics');

    putenv('JM_DOMAIN_FRESH_TTL');
    putenv('JM_DOMAIN_STALE_TTL');
    putenv('JM_DOMAIN_REFRESH_FAILURE_TTL');
    putenv('JM_TEST_DOMAIN_SOURCE_URLS');
}

echo "Upstream policy runtime checks passed.\n";
