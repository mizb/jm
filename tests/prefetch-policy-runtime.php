<?php
declare(strict_types=1);

define('JM_API_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/index.php';

function assertPrefetchSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assertPrefetchSame(
    [
        'scheduled' => false,
        'attempted' => 0,
        'cache_hits' => 0,
        'stored' => 0,
        'bytes' => 0,
        'wall_ms' => 0,
        'skip_reason' => null,
    ],
    PrefetchCoordinator::emptyStats(),
    'prefetch stats have the exact seven-field shape',
);

final class FakePrefetchCache implements PrefetchLeaseStore
{
    /** @var array<string,mixed> */
    public array $values = [];
    /** @var array<string,int> */
    public array $expiresAtMs = [];
    public int $nowMs = 0;
    public int $tryAddCalls = 0;
    /** @var array<string,int> */
    public array $tryAddTtls = [];
    /** @var list<array{0:string,1:int,2:int}> */
    public array $compareRefreshes = [];
    /** @var array<string,list<int>> */
    public array $refreshTtls = [];
    /** @var array<string,int> */
    public array $refreshStoreFailures = [];
    /** @var array<string,bool> */
    public array $authorityBusyKeys = [];
    /** @var array<string,int> */
    public array $authorityLocks = [];
    public ?\Closure $onRefreshOwned = null;
    /** @var list<array{0:string,1:int}> */
    public array $compareDeletes = [];

    public function __construct(private bool $available = true) {}

    public function isAvailable(): bool { return $this->available; }
    public function get(string $key): mixed
    {
        $this->expire($key);
        return $this->values[$key] ?? null;
    }
    public function tryAdd(string $key, mixed $value, int $ttl): bool
    {
        return is_int($value) && $this->tryAcquire($key, $value, $ttl);
    }
    public function tryAcquire(string $key, int $token, int $ttl): bool
    {
        $this->tryAddCalls++;
        $this->tryAddTtls[$key] = $ttl;
        if (!$this->available
            || $ttl <= 0
            || $token <= 0
            || ($this->authorityBusyKeys[$key] ?? false)
            || isset($this->authorityLocks[$key])
        ) return false;
        $this->expire($key);
        $this->authorityLocks[$key] = $token;
        $this->values[$key] = $token;
        $this->expiresAtMs[$key] = $this->nowMs + ($ttl * 1000);
        return true;
    }
    public function owns(string $key, int $expectedToken): bool
    {
        return $this->available
            && ($this->authorityLocks[$key] ?? null) === $expectedToken;
    }
    public function compareAndRefresh(string $key, int $expectedToken, int $ttl): bool
    {
        if (!$this->available
            || $ttl <= 0
            || $expectedToken <= 0
            || ($this->authorityLocks[$key] ?? null) !== $expectedToken
        ) return false;
        $this->expire($key);
        $this->compareRefreshes[] = [$key, $expectedToken, $ttl];
        if (isset($this->values[$key]) && $this->values[$key] !== $expectedToken) return false;
        if ($this->onRefreshOwned !== null) {
            ($this->onRefreshOwned)($this, $key, $expectedToken, $ttl);
        }
        if (isset($this->values[$key]) && $this->values[$key] !== $expectedToken) return false;
        if (($this->refreshStoreFailures[$key] ?? 0) > 0) {
            $this->refreshStoreFailures[$key]--;
            return false;
        }
        $this->values[$key] = $expectedToken;
        $this->expiresAtMs[$key] = $this->nowMs + ($ttl * 1000);
        $this->refreshTtls[$key][] = $ttl;
        return true;
    }
    public function compareAndDelete(string $key, int $expectedToken): bool
    {
        return $this->release($key, $expectedToken);
    }
    public function release(string $key, int $expectedToken): bool
    {
        $this->compareDeletes[] = [$key, $expectedToken];
        if (($this->authorityLocks[$key] ?? null) !== $expectedToken) return false;
        try {
            $this->expire($key);
            if (($this->values[$key] ?? null) === $expectedToken) {
                unset($this->values[$key], $this->expiresAtMs[$key]);
            }
            return true;
        } finally {
            unset($this->authorityLocks[$key]);
        }
    }

    public function advanceMs(int $milliseconds): void
    {
        $this->nowMs += max(0, $milliseconds);
    }

    private function expire(string $key): void
    {
        if (isset($this->expiresAtMs[$key])
            && intdiv($this->nowMs, 1000) > intdiv($this->expiresAtMs[$key], 1000)
        ) {
            unset($this->values[$key], $this->expiresAtMs[$key]);
        }
    }

}

if (function_exists('apcu_enabled') && apcu_enabled()) {
    $realPrefix = 'jmapi-prefetch-policy-' . bin2hex(random_bytes(6)) . ':';
    $realCache = new MemoryCache($realPrefix);
    $realLeaseKey = 'prefetch-page-lease:v1:' . hash('sha256', 'runtime-apcu');
    $realToken = 123456;

    $realRival = new MemoryCache($realPrefix);
    $realLockDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jmapi-prefetch-mutation-lock-v1';
    $realShard = hexdec(substr(hash('sha256', $realPrefix . $realLeaseKey), 0, 2));
    $realLockPath = $realLockDirectory . DIRECTORY_SEPARATOR . sprintf('lock-%03d.lck', $realShard);

    assertPrefetchSame(true, $realCache->tryAcquire($realLeaseKey, $realToken, 5), 'real APCu prefetch lease acquisition succeeds');
    assertPrefetchSame(true, $realCache->owns($realLeaseKey, $realToken), 'real authoritative ownership verification is token exact');
    assertPrefetchSame(true, is_dir($realLockDirectory), 'real prefetch flock uses one stable application lock directory');
    assertPrefetchSame(true, is_file($realLockPath), 'real prefetch flock maps the complete APCu key to one deterministic shard file');
    clearstatcache(true, $realLockPath);
    assertPrefetchSame(0, filesize($realLockPath), 'real prefetch flock shard stores zero business or image bytes');

    $authorityProbe = fopen($realLockPath, 'c+b');
    if ($authorityProbe === false) throw new RuntimeException('failed to probe the real prefetch authority flock');
    try {
        assertPrefetchSame(false, flock($authorityProbe, LOCK_EX | LOCK_NB), 'authority flock remains held for the complete lease lifetime');
    } finally {
        fclose($authorityProbe);
    }

    assertPrefetchSame(true, $realCache->delete($realLeaseKey), 'real APCu fixture simulates a full-expunge mirror loss');
    assertPrefetchSame(null, $realCache->get($realLeaseKey), 'real APCu lease mirror is absent after simulated expunge');
    assertPrefetchSame(false, $realRival->tryAcquire($realLeaseKey, 654321, 5), 'APCu expunge cannot transfer authoritative page ownership to a rival');
    assertPrefetchSame(true, $realCache->owns($realLeaseKey, $realToken), 'APCu expunge does not change authoritative ownership');
    assertPrefetchSame(true, $realCache->compareAndRefresh($realLeaseKey, $realToken, 10), 'authoritative owner recreates an expunged APCu mirror');
    assertPrefetchSame($realToken, $realCache->get($realLeaseKey), 'recreated APCu mirror retains the authoritative token');
    assertPrefetchSame(false, $realCache->release($realLeaseKey, 654321), 'real mismatched authoritative release fails closed');
    assertPrefetchSame(true, $realCache->release($realLeaseKey, $realToken), 'real matching authoritative release succeeds');
    assertPrefetchSame(false, $realCache->owns($realLeaseKey, $realToken), 'real released authority is no longer owned');
    assertPrefetchSame(true, $realRival->tryAcquire($realLeaseKey, 654321, 5), 'rival acquires only after authoritative page release');
    assertPrefetchSame(true, $realRival->release($realLeaseKey, 654321), 'post-release page rival cleans up normally');

    $realSlotKey = 'prefetch-slot:0';
    assertPrefetchSame(true, $realCache->tryAcquire($realSlotKey, $realToken, 5), 'real global slot acquires an authoritative flock');
    assertPrefetchSame(true, $realCache->delete($realSlotKey), 'real APCu fixture expunges the slot mirror');
    assertPrefetchSame(false, $realRival->tryAcquire($realSlotKey, 654321, 5), 'APCu expunge cannot transfer authoritative slot ownership to a rival');
    assertPrefetchSame(true, $realCache->compareAndRefresh($realSlotKey, $realToken, 10), 'authoritative slot owner recreates an expunged APCu mirror');
    assertPrefetchSame(true, $realCache->release($realSlotKey, $realToken), 'authoritative slot owner releases normally');
    assertPrefetchSame(true, $realRival->tryAcquire($realSlotKey, 654321, 5), 'slot rival acquires only after authoritative release');
    assertPrefetchSame(true, $realRival->release($realSlotKey, 654321), 'post-release slot rival cleans up normally');

    $collisionByShard = [];
    $collisionKeys = null;
    for ($index = 0; $index < 1024 && $collisionKeys === null; $index++) {
        $candidateKey = 'prefetch-page-lease:v1:' . hash('sha256', 'collision-' . $index);
        $candidateShard = hexdec(substr(hash('sha256', $realPrefix . $candidateKey), 0, 2));
        if (isset($collisionByShard[$candidateShard])) {
            $collisionKeys = [$collisionByShard[$candidateShard], $candidateKey];
            break;
        }
        $collisionByShard[$candidateShard] = $candidateKey;
    }
    if (!is_array($collisionKeys)) throw new RuntimeException('failed to find a deterministic prefetch flock shard collision');
    [$collisionKeyA, $collisionKeyB] = $collisionKeys;
    assertPrefetchSame(true, $realCache->tryAcquire($collisionKeyA, 200001, 5), 'same request acquires the first colliding lease');
    assertPrefetchSame(true, $realCache->tryAcquire($collisionKeyB, 200002, 5), 'same request shares a colliding authority handle by refcount');
    assertPrefetchSame(false, $realRival->tryAcquire($collisionKeyA, 300001, 5), 'cross-request shard collision fails closed');
    assertPrefetchSame(true, $realCache->release($collisionKeyA, 200001), 'first colliding lease releases its refcount');
    assertPrefetchSame(false, $realRival->tryAcquire($collisionKeyA, 300001, 5), 'remaining colliding lease keeps the shared authority handle locked');
    assertPrefetchSame(true, $realCache->release($collisionKeyB, 200002), 'last colliding lease releases the shared authority handle');
    assertPrefetchSame(true, $realRival->tryAcquire($collisionKeyA, 300001, 5), 'rival acquires the shard after its last refcount releases');
    assertPrefetchSame(true, $realRival->release($collisionKeyA, 300001), 'collision rival cleans up normally');

    $heldLock = fopen($realLockPath, 'c+b');
    if ($heldLock === false) throw new RuntimeException('failed to open the real prefetch flock shard');
    $held = false;
    try {
        $held = flock($heldLock, LOCK_EX | LOCK_NB);
        assertPrefetchSame(true, $held, 'real prefetch flock fixture acquires the target shard');
        usleep(5_100_000);
        assertPrefetchSame(false, $realRival->tryAcquire($realLeaseKey, $realToken, 5), 'a flock held beyond five seconds still blocks a rival owner');
        assertPrefetchSame(null, $realCache->get($realLeaseKey), 'flock-busy acquisition leaves no lease behind');
    } finally {
        if ($held) flock($heldLock, LOCK_UN);
        fclose($heldLock);
    }
    assertPrefetchSame(true, $realRival->tryAcquire($realLeaseKey, $realToken, 5), 'a rival can acquire immediately after flock unlock');
    assertPrefetchSame(true, $realRival->release($realLeaseKey, $realToken), 'post-unlock owner can release normally');

    $closedLock = fopen($realLockPath, 'c+b');
    if ($closedLock === false) throw new RuntimeException('failed to reopen the real prefetch flock shard');
    assertPrefetchSame(true, flock($closedLock, LOCK_EX | LOCK_NB), 'real prefetch flock fixture reacquires before close recovery');
    fclose($closedLock);
    assertPrefetchSame(true, $realRival->tryAcquire($realLeaseKey, $realToken, 5), 'closing a lock handle recovers ownership without a TTL');
    assertPrefetchSame(true, $realRival->release($realLeaseKey, $realToken), 'close-recovered owner releases normally');

    if (function_exists('proc_open')) {
        $readyPath = tempnam(sys_get_temp_dir(), 'jm-prefetch-flock-ready-');
        if ($readyPath === false) throw new RuntimeException('failed to allocate the authority crash-test marker');
        if (!unlink($readyPath) || file_exists($readyPath)) {
            throw new RuntimeException('failed to reset the authority crash-test marker');
        }
        $childCode = <<<'PHP'
$handle = fopen($argv[1], 'c+b');
if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) exit(2);
if (file_put_contents($argv[2], 'ready') === false) exit(3);
sleep(30);
PHP;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-n', '-r', $childCode, $realLockPath, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) throw new RuntimeException('failed to start the authority crash-test holder');
        foreach ($pipes as $pipe) fclose($pipe);
        try {
            $readyDeadline = microtime(true) + 5.0;
            while (!is_file($readyPath) && microtime(true) < $readyDeadline) usleep(20_000);
            assertPrefetchSame(true, is_file($readyPath), 'child process reports authority flock acquisition');
            assertPrefetchSame(false, $realRival->tryAcquire($realLeaseKey, $realToken, 5), 'cross-process authority holder blocks a rival');
            proc_terminate($process);
            proc_close($process);
            $process = null;

            $recovered = false;
            $recoveryDeadline = microtime(true) + 5.0;
            while (!$recovered && microtime(true) < $recoveryDeadline) {
                $recovered = $realRival->tryAcquire($realLeaseKey, $realToken, 5);
                if (!$recovered) usleep(20_000);
            }
            assertPrefetchSame(true, $recovered, 'terminating a lock-holder process releases authority without TTL recovery');
            assertPrefetchSame(true, $realRival->release($realLeaseKey, $realToken), 'crash-recovered owner releases normally');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (file_exists($readyPath) && !unlink($readyPath)) {
                throw new RuntimeException('failed to remove the authority crash-test marker');
            }
            if (file_exists($readyPath)) {
                throw new RuntimeException('authority crash-test marker remained after cleanup');
            }
        }
        assertPrefetchSame(false, file_exists($readyPath), 'crash-test marker cleanup leaves no residual file');
    }

    $realLockFiles = glob($realLockDirectory . DIRECTORY_SEPARATOR . 'lock-*.lck');
    assertPrefetchSame(true, is_array($realLockFiles) && count($realLockFiles) <= 256, 'real prefetch flock directory is bounded to 256 lazy shard files');
    foreach ($realLockFiles ?: [] as $lockFile) {
        clearstatcache(true, $lockFile);
        assertPrefetchSame(0, filesize($lockFile), 'every real prefetch flock shard remains zero bytes');
    }
}

/** @return array{schema:string,photo_id:string,page:int,priority:int,covered:bool} */
function prefetchCandidate(string $photoId, int $page, int $priority = 0, bool $covered = false): array
{
    return [
        'schema' => 'decoded-page:v1',
        'photo_id' => $photoId,
        'page' => $page,
        'priority' => $priority,
        'covered' => $covered,
    ];
}

/** @return array{coordinator:PrefetchCoordinator,cache:FakePrefetchCache,registered:array,stats:array,candidate_calls:int} */
function makePrefetchHarness(bool $available = true, bool $waterline = true): array
{
    $cache = new FakePrefetchCache($available);
    $registered = [];
    $stats = [];
    $candidateCalls = 0;
    $coordinator = new PrefetchCoordinator(
        $cache,
        static fn(): int => 0,
        static function (callable $callback) use (&$registered): void { $registered[] = $callback; },
        static fn(array $candidate, int $remainingMs): array => [],
        static function (array $event) use (&$stats): void { $stats[] = $event; },
        static fn(): bool => $waterline,
        static fn(): int => 101,
    );
    return [
        'coordinator' => $coordinator,
        'cache' => $cache,
        'registered' => &$registered,
        'stats' => &$stats,
        'candidate_calls' => &$candidateCalls,
    ];
}

$earlyCases = [
    'disabled' => [false, 10, 2, 5000, 16777216, true, true, 'disabled'],
    'pages-zero' => [true, 0, 2, 5000, 16777216, true, true, 'skipped-pages-zero'],
    'active-zero' => [true, 10, 0, 5000, 16777216, true, true, 'skipped-max-active-zero'],
    'wall-zero' => [true, 10, 2, 0, 16777216, true, true, 'skipped-wall-zero'],
    'byte-zero' => [true, 10, 2, 5000, 0, true, true, 'skipped-byte-zero'],
    'no-apcu' => [true, 10, 2, 5000, 16777216, false, true, 'skipped-no-apcu'],
    'low-memory' => [true, 10, 2, 5000, 16777216, true, false, 'skipped-low-memory'],
];
foreach ($earlyCases as $label => [$enabled, $pages, $active, $wall, $bytes, $available, $waterline, $reason]) {
    $harness = makePrefetchHarness($available, $waterline);
    $candidateCalls = 0;
    $stats = $harness['coordinator']->schedule(
        $enabled,
        $pages,
        $active,
        $wall,
        $bytes,
        1000,
        static function () use (&$candidateCalls): array {
            $candidateCalls++;
            return [['schema' => 'decoded-page:v1', 'photo_id' => '1', 'page' => 2, 'priority' => 0, 'covered' => false]];
        },
    );
    assertPrefetchSame($reason, $stats['skip_reason'], "{$label} reports a fixed skip reason");
    assertPrefetchSame(0, $candidateCalls, "{$label} exits before candidate generation");
    assertPrefetchSame(0, $harness['cache']->tryAddCalls, "{$label} exits before atomic claims");
    assertPrefetchSame(0, count($harness['registered']), "{$label} exits before callback registration");
    assertPrefetchSame($stats, $harness['stats'][0] ?? null, "{$label} sends the exact stats shape");
}

assertPrefetchSame(5, PrefetchCoordinator::leaseTtlSeconds(0, 0), 'lease TTL clamps to five seconds');
assertPrefetchSame(32, PrefetchCoordinator::leaseTtlSeconds(22000, 5000), 'lease TTL covers schedule delay, wall, and safety margin');
assertPrefetchSame(300, PrefetchCoordinator::leaseTtlSeconds(400000, 5000), 'lease TTL clamps to five minutes');

$covered = makePrefetchHarness();
$coveredStats = $covered['coordinator']->schedule(
    true, 10, 2, 5000, 16777216, 22000,
    static fn(): array => [prefetchCandidate('7', 2, covered: true)],
);
assertPrefetchSame('skipped-pages-covered', $coveredStats['skip_reason'], 'a fully cached window is skipped');
assertPrefetchSame(0, $covered['cache']->tryAddCalls, 'covered pages are filtered before atomic claims');
assertPrefetchSame(0, count($covered['registered']), 'a fully cached window registers no callback');

$sharedCache = new FakePrefetchCache();
$firstCallbacks = [];
$secondCallbacks = [];
$makeSharedCoordinator = static function (array &$callbacks) use ($sharedCache): PrefetchCoordinator {
    return new PrefetchCoordinator(
        $sharedCache,
        static fn(): int => 0,
        static function (callable $callback) use (&$callbacks): void { $callbacks[] = $callback; },
        static fn(array $candidate, int $remainingMs): array => [],
        static fn(array $stats): null => null,
        static fn(): bool => true,
        static fn(): int => 202,
    );
};
$firstCoordinator = $makeSharedCoordinator($firstCallbacks);
$secondCoordinator = $makeSharedCoordinator($secondCallbacks);
$firstStats = $firstCoordinator->schedule(
    true, 10, 2, 5000, 16777216, 22000,
    static fn(): array => [prefetchCandidate('8', 3)],
);
$secondStats = $secondCoordinator->schedule(
    true, 10, 2, 5000, 16777216, 22000,
    static fn(): array => [prefetchCandidate('8', 3)],
);
assertPrefetchSame(true, $firstStats['scheduled'], 'the first window owner is scheduled');
assertPrefetchSame(1, count($firstCallbacks), 'the first window owner registers one callback');
assertPrefetchSame('skipped-pages-covered', $secondStats['skip_reason'], 'an overlapping owner cannot reclaim the same page');
assertPrefetchSame(0, count($secondCallbacks), 'an overlapping window registers no callback');
$leaseKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('8', 3));
assertPrefetchSame(32, $sharedCache->tryAddTtls[$leaseKey] ?? null, 'page claims use the calculated lease TTL');
($firstCallbacks[0])();
assertPrefetchSame(null, $sharedCache->get($leaseKey), 'successful callback cleanup releases its page claim');
assertPrefetchSame(null, $sharedCache->get('prefetch-slot:0'), 'successful callback cleanup releases its global slot');

$expungeCache = new FakePrefetchCache();
$expungeOwnerCallbacks = [];
$expungeOwnerEvents = [];
$expungeExecutions = 0;
$expungeOwnerToken = 1200;
$expungeOwner = new PrefetchCoordinator(
    $expungeCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$expungeOwnerCallbacks): void { $expungeOwnerCallbacks[] = $callback; },
    static function () use (&$expungeExecutions): array { $expungeExecutions++; return []; },
    static function (array $stats) use (&$expungeOwnerEvents): void { $expungeOwnerEvents[] = $stats; },
    static fn(): bool => true,
    static function () use (&$expungeOwnerToken): int { return ++$expungeOwnerToken; },
);
$expungeOwner->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('36', 2)],
);
$expungePageKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('36', 2));
assertPrefetchSame(2, count($expungeCache->authorityLocks), 'page and slot authority remain held from schedule through callback');
$expungeCache->values = [];
$expungeCache->expiresAtMs = [];

$expungeSameCallbacks = [];
$expungeSame = new PrefetchCoordinator(
    $expungeCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$expungeSameCallbacks): void { $expungeSameCallbacks[] = $callback; },
    static fn(): array => [],
    static fn(array $stats): null => null,
    static fn(): bool => true,
    static fn(): int => 1301,
);
$expungeSameStats = $expungeSame->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('36', 2)],
);
assertPrefetchSame('skipped-pages-covered', $expungeSameStats['skip_reason'] ?? null, 'APCu expunge cannot transfer a page authority flock to a rival');
assertPrefetchSame(0, count($expungeSameCallbacks), 'APCu-expunge page rival registers no callback');

$expungeOtherCallbacks = [];
$expungeOther = new PrefetchCoordinator(
    $expungeCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$expungeOtherCallbacks): void { $expungeOtherCallbacks[] = $callback; },
    static fn(): array => [],
    static fn(array $stats): null => null,
    static fn(): bool => true,
    static fn(): int => 1401,
);
$expungeOtherStats = $expungeOther->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('37', 2)],
);
assertPrefetchSame('skipped-busy', $expungeOtherStats['skip_reason'] ?? null, 'APCu expunge cannot transfer the global slot authority flock to a rival');
assertPrefetchSame(0, count($expungeOtherCallbacks), 'APCu-expunge slot rival registers no callback');
assertPrefetchSame(null, $expungeCache->get(PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('37', 2))), 'slot rival cleanup releases its temporary page mirror');

($expungeOwnerCallbacks[0])();
assertPrefetchSame(1, $expungeExecutions, 'authoritative owner recreates missing APCu mirrors and executes once');
assertPrefetchSame([10, 10], $expungeCache->refreshTtls[$expungePageKey] ?? null, 'authoritative owner refresh recreates an expunged page mirror');
assertPrefetchSame([], $expungeCache->authorityLocks, 'authoritative owner releases page and slot handles after callback');

$expungeAfterCallbacks = [];
$expungeAfter = new PrefetchCoordinator(
    $expungeCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$expungeAfterCallbacks): void { $expungeAfterCallbacks[] = $callback; },
    static fn(): array => [],
    static fn(array $stats): null => null,
    static fn(): bool => true,
    static fn(): int => 1501,
);
$expungeAfterStats = $expungeAfter->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('36', 2)],
);
assertPrefetchSame(true, $expungeAfterStats['scheduled'], 'rival can acquire only after the authoritative owner releases flock');
($expungeAfterCallbacks[0])();

$busy = makePrefetchHarness();
$busy['cache']->values['prefetch-slot:0'] = 901;
$busy['cache']->values['prefetch-slot:1'] = 902;
$busy['cache']->authorityBusyKeys['prefetch-slot:0'] = true;
$busy['cache']->authorityBusyKeys['prefetch-slot:1'] = true;
$busyStats = $busy['coordinator']->schedule(
    true, 10, 2, 5000, 16777216, 22000,
    static fn(): array => [prefetchCandidate('9', 2), prefetchCandidate('9', 3)],
);
assertPrefetchSame('skipped-busy', $busyStats['skip_reason'], 'a full global slot set skips immediately');
assertPrefetchSame(0, count($busy['registered']), 'busy scheduling registers no callback');
assertPrefetchSame(null, $busy['cache']->get(PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('9', 2))), 'busy cleanup releases the first page claim');
assertPrefetchSame(null, $busy['cache']->get(PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('9', 3))), 'busy cleanup releases the second page claim');
assertPrefetchSame(901, $busy['cache']->get('prefetch-slot:0'), 'busy cleanup does not delete another slot owner');
assertPrefetchSame(902, $busy['cache']->get('prefetch-slot:1'), 'busy cleanup leaves all foreign slots intact');

$successCache = new FakePrefetchCache();
$successCallbacks = [];
$successEvents = [];
$executedPages = [];
$nowNs = 0;
$token = 300;
$successCoordinator = new PrefetchCoordinator(
    $successCache,
    static function () use (&$nowNs): int { return $nowNs; },
    static function (callable $callback) use (&$successCallbacks): void { $successCallbacks[] = $callback; },
    static function (array $candidate, int $remainingMs) use (&$executedPages, &$nowNs): array {
        $executedPages[] = $candidate['page'];
        $nowNs += 2_000_000;
        return match ($candidate['page']) {
            2 => ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 100],
            3 => ['cache_hit' => true, 'cache_store' => 'hit', 'upstream_bytes' => 0],
            default => ['cache_hit' => false, 'cache_store' => 'disabled', 'upstream_bytes' => 50],
        };
    },
    static function (array $stats) use (&$successEvents): void { $successEvents[] = $stats; },
    static fn(): bool => true,
    static function () use (&$token): int { return ++$token; },
);
$successSchedule = $successCoordinator->schedule(
    true, 10, 2, 5000, 1000, 22000,
    static fn(): array => [
        prefetchCandidate('10', 4, priority: 1),
        prefetchCandidate('10', 2, priority: 0),
        prefetchCandidate('10', 3, priority: 0),
    ],
);
assertPrefetchSame(true, $successSchedule['scheduled'], 'a claimed window reports scheduled');
($successCallbacks[0])();
assertPrefetchSame([2, 3, 4], $executedPages, 'high-priority candidates execute before low-priority candidates');
$successStats = $successEvents[0] ?? null;
assertPrefetchSame([
    'scheduled' => true,
    'attempted' => 3,
    'cache_hits' => 1,
    'stored' => 1,
    'bytes' => 150,
    'wall_ms' => 6,
    'skip_reason' => null,
], $successStats, 'successful callback reports exact aggregate semantics');
assertPrefetchSame([], $successCache->values, 'successful callback releases every page and slot token');
$successPageLeaseKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('10', 2));
assertPrefetchSame([10, 10], $successCache->refreshTtls[$successPageLeaseKey] ?? null, 'callback renews page ownership at start and immediately before executor work');
assertPrefetchSame([10, 10, 10, 10], $successCache->refreshTtls['prefetch-slot:0'] ?? null, 'callback renews slot ownership at start and immediately before every executor');
assertPrefetchSame([], $successCache->authorityLocks, 'successful renewal and cleanup release every authority handle');

$boundaryCache = new FakePrefetchCache();
$boundaryCallbacks = [];
$boundaryEvents = [];
$boundaryToken = 1000;
$boundaryPageKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('30', 2));
$boundaryPhysicalExpiryObserved = false;
$boundaryRivalDuringRefresh = null;
$boundaryRivalDuringExecutor = null;
$boundaryCache->onRefreshOwned = static function (FakePrefetchCache $cache, string $key) use (
    $boundaryPageKey,
    &$boundaryPhysicalExpiryObserved,
    &$boundaryRivalDuringRefresh,
): void {
    if ($key !== $boundaryPageKey) return;
    $cache->onRefreshOwned = null;
    $cache->advanceMs(1001);
    $boundaryPhysicalExpiryObserved = $cache->get($key) === null;
    $boundaryRivalDuringRefresh = $cache->tryAcquire($key, 9001, 10);
};
$boundaryCoordinator = new PrefetchCoordinator(
    $boundaryCache,
    static fn(): int => $boundaryCache->nowMs * 1_000_000,
    static function (callable $callback) use (&$boundaryCallbacks): void { $boundaryCallbacks[] = $callback; },
    static function () use ($boundaryCache, $boundaryPageKey, &$boundaryRivalDuringExecutor): array {
        $boundaryCache->advanceMs(6000);
        $boundaryRivalDuringExecutor = $boundaryCache->tryAcquire($boundaryPageKey, 9002, 10);
        return ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 1];
    },
    static function (array $stats) use (&$boundaryEvents): void { $boundaryEvents[] = $stats; },
    static fn(): bool => true,
    static function () use (&$boundaryToken): int { return ++$boundaryToken; },
);
$boundaryCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('30', 2)],
);
$boundaryCache->advanceMs(9999);
($boundaryCallbacks[0])();
assertPrefetchSame(true, $boundaryPhysicalExpiryObserved, 'renewal mutex covers the exact old physical lease expiry boundary');
assertPrefetchSame(false, $boundaryRivalDuringRefresh, 'a rival cannot acquire a physically expired mirror while the owner holds its authority flock');
assertPrefetchSame(false, $boundaryRivalDuringExecutor, 'renewed ownership blocks a rival throughout bounded executor work');
assertPrefetchSame(1, $boundaryEvents[0]['attempted'] ?? null, 'a valid near-expiry owner executes after token-safe renewal');
assertPrefetchSame([], $boundaryCache->authorityLocks, 'expiry-boundary renewal releases every authority handle');

$remainingCache = new FakePrefetchCache();
$remainingCallbacks = [];
$remainingNowMs = 0;
$remainingToken = 1100;
$remainingCoordinator = new PrefetchCoordinator(
    $remainingCache,
    static function () use (&$remainingNowMs): int { return $remainingNowMs * 1_000_000; },
    static function (callable $callback) use (&$remainingCallbacks): void { $remainingCallbacks[] = $callback; },
    static function () use (&$remainingNowMs): array {
        $remainingNowMs += 3100;
        return ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 1];
    },
    static fn(array $stats): null => null,
    static fn(): bool => true,
    static function () use (&$remainingToken): int { return ++$remainingToken; },
);
$remainingCoordinator->schedule(
    true, 10, 1, 7000, 1000, 0,
    static fn(): array => [prefetchCandidate('31', 2), prefetchCandidate('31', 3)],
);
($remainingCallbacks[0])();
$remainingSecondKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('31', 3));
assertPrefetchSame([12, 9], $remainingCache->refreshTtls[$remainingSecondKey] ?? null, 'per-page renewal uses remaining wall budget plus safety with ceiling semantics');
assertPrefetchSame([12, 12, 9], $remainingCache->refreshTtls['prefetch-slot:0'] ?? null, 'slot renewal shrinks with the same remaining-wall TTL formula');

$ownerCache = new FakePrefetchCache();
$ownerCallbacks = [];
$ownerEvents = [];
$ownerCoordinator = new PrefetchCoordinator(
    $ownerCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$ownerCallbacks): void { $ownerCallbacks[] = $callback; },
    static fn(array $candidate, int $remainingMs): array => [],
    static fn(array $stats): null => null,
    static fn(): bool => true,
    static fn(): int => 351,
    static function (array $event) use (&$ownerEvents): void { $ownerEvents[] = $event; },
);
$ownerCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('19', 2), prefetchCandidate('19', 3)],
);
($ownerCallbacks[0])();
assertPrefetchSame([
    'page-owner-acquire',
    'page-owner-acquire',
    'slot-acquire',
    'callback-start',
    'slot-renew',
    'page-owner-renew',
    'page-owner-renew',
    'slot-renew',
    'page-owner-renew',
    'slot-renew',
    'page-owner-renew',
    'page-owner-release',
    'page-owner-release',
    'slot-release',
], array_column($ownerEvents, 'event'), 'ownership sink observes direct page and slot lifecycle');

$scheduleSinkCache = new FakePrefetchCache();
$scheduleSinkCallbacks = [];
$scheduleSinkStats = [];
$scheduleSinkExecutions = 0;
$scheduleSinkCoordinator = new PrefetchCoordinator(
    $scheduleSinkCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$scheduleSinkCallbacks): void { $scheduleSinkCallbacks[] = $callback; },
    static function () use (&$scheduleSinkExecutions): array { $scheduleSinkExecutions++; return []; },
    static function (array $stats) use (&$scheduleSinkStats): void { $scheduleSinkStats[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 361,
    static function (array $event): void {
        if (in_array($event['event'] ?? '', ['page-owner-acquire', 'slot-acquire'], true)) {
            throw new RuntimeException('fixture schedule ownership sink failure');
        }
    },
);
$scheduleSinkResult = $scheduleSinkCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('40', 2)],
);
assertPrefetchSame(true, $scheduleSinkResult['scheduled'], 'schedule-time ownership sink exceptions cannot block callback registration');
assertPrefetchSame(1, count($scheduleSinkCallbacks), 'schedule-time ownership sink exception still registers one callback');
($scheduleSinkCallbacks[0])();
assertPrefetchSame(1, $scheduleSinkExecutions, 'schedule-time ownership sink exception cannot block executor work');
assertPrefetchSame(1, $scheduleSinkStats[0]['attempted'] ?? null, 'schedule-time ownership sink exception preserves callback stats');
assertPrefetchSame([], $scheduleSinkCache->values, 'schedule-time ownership sink exception cannot leak APCu mirrors');
assertPrefetchSame([], $scheduleSinkCache->authorityLocks, 'schedule-time ownership sink exception cannot leak page or slot authority');

$callbackSinkCache = new FakePrefetchCache();
$callbackSinkCallbacks = [];
$callbackSinkStats = [];
$callbackSinkExecutions = 0;
$callbackSinkCoordinator = new PrefetchCoordinator(
    $callbackSinkCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$callbackSinkCallbacks): void { $callbackSinkCallbacks[] = $callback; },
    static function () use (&$callbackSinkExecutions): array { $callbackSinkExecutions++; return []; },
    static function (array $stats) use (&$callbackSinkStats): void { $callbackSinkStats[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 371,
    static function (array $event): void {
        if (!in_array($event['event'] ?? '', ['page-owner-acquire', 'slot-acquire'], true)) {
            throw new RuntimeException('fixture callback ownership sink failure');
        }
    },
);
$callbackSinkCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('41', 2)],
);
($callbackSinkCallbacks[0])();
assertPrefetchSame(1, $callbackSinkExecutions, 'callback-time ownership sink exceptions cannot block executor work or cleanup');
assertPrefetchSame(1, $callbackSinkStats[0]['attempted'] ?? null, 'callback-time ownership sink exception preserves callback stats');
assertPrefetchSame([], $callbackSinkCache->values, 'callback-time ownership sink exception cannot leak APCu mirrors');
assertPrefetchSame([], $callbackSinkCache->authorityLocks, 'callback-time ownership sink exception cannot leak page or slot authority');
$callbackSinkRivalCallbacks = [];
$callbackSinkRival = new PrefetchCoordinator(
    $callbackSinkCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$callbackSinkRivalCallbacks): void { $callbackSinkRivalCallbacks[] = $callback; },
    static fn(): array => [],
    static fn(array $stats): null => null,
    static fn(): bool => true,
    static fn(): int => 381,
);
$callbackSinkRivalResult = $callbackSinkRival->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('41', 2)],
);
assertPrefetchSame(true, $callbackSinkRivalResult['scheduled'], 'ownership sink exception cleanup permits a rival owner');
($callbackSinkRivalCallbacks[0])();

$wallCache = new FakePrefetchCache();
$wallCallbacks = [];
$wallEvents = [];
$wallNowNs = 0;
$wallCoordinator = new PrefetchCoordinator(
    $wallCache,
    static function () use (&$wallNowNs): int { return $wallNowNs; },
    static function (callable $callback) use (&$wallCallbacks): void { $wallCallbacks[] = $callback; },
    static function (array $candidate, int $remainingMs) use (&$wallNowNs): array {
        $wallNowNs += 6_000_000;
        return ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 10];
    },
    static function (array $stats) use (&$wallEvents): void { $wallEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 401,
);
$wallCoordinator->schedule(
    true, 10, 1, 5, 1000, 1000,
    static fn(): array => [prefetchCandidate('11', 2), prefetchCandidate('11', 3)],
);
($wallCallbacks[0])();
assertPrefetchSame(1, $wallEvents[0]['attempted'] ?? null, 'wall budget is checked between every page');
assertPrefetchSame('budget-wall', $wallEvents[0]['skip_reason'] ?? null, 'wall exhaustion has a fixed reason');

$byteCache = new FakePrefetchCache();
$byteCallbacks = [];
$byteEvents = [];
$byteLimits = [];
$byteCoordinator = new PrefetchCoordinator(
    $byteCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$byteCallbacks): void { $byteCallbacks[] = $callback; },
    static function (array $candidate, int $remainingMs, int $remainingBytes) use (&$byteLimits): array {
        $byteLimits[] = $remainingBytes;
        if (101 > $remainingBytes) {
            throw new UpstreamBudgetExhaustedException(UpstreamBudgetExhaustedException::REASON_BYTES);
        }
        return ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 101];
    },
    static function (array $stats) use (&$byteEvents): void { $byteEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 501,
);
$byteCoordinator->schedule(
    true, 10, 1, 5000, 100, 1000,
    static fn(): array => [prefetchCandidate('12', 2), prefetchCandidate('12', 3)],
);
($byteCallbacks[0])();
assertPrefetchSame(1, $byteEvents[0]['attempted'] ?? null, 'byte budget reaches the first page executor');
assertPrefetchSame([100], $byteLimits, 'executor receives the remaining byte budget before downloading');
assertPrefetchSame(0, $byteEvents[0]['bytes'] ?? null, 'over-budget input is aborted before bytes are accepted');
assertPrefetchSame('budget-bytes', $byteEvents[0]['skip_reason'] ?? null, 'in-page byte exhaustion has a fixed reason');

$waterlineCache = new FakePrefetchCache();
$waterlineCallbacks = [];
$waterlineEvents = [];
$waterlineState = true;
$waterlineCoordinator = new PrefetchCoordinator(
    $waterlineCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$waterlineCallbacks): void { $waterlineCallbacks[] = $callback; },
    static function (array $candidate, int $remainingMs) use (&$waterlineState): array {
        $waterlineState = false;
        return ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 1];
    },
    static function (array $stats) use (&$waterlineEvents): void { $waterlineEvents[] = $stats; },
    static function () use (&$waterlineState): bool { return $waterlineState; },
    static fn(): int => 551,
);
$waterlineCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('13', 2), prefetchCandidate('13', 3)],
);
($waterlineCallbacks[0])();
assertPrefetchSame(1, $waterlineEvents[0]['attempted'] ?? null, 'APCu waterline is checked before every priority class');
assertPrefetchSame('skipped-low-memory', $waterlineEvents[0]['skip_reason'] ?? null, 'mid-window low memory stops all later pages');

$attemptBudgetCache = new FakePrefetchCache();
$attemptBudgetCallbacks = [];
$attemptBudgetEvents = [];
$attemptBudgetCoordinator = new PrefetchCoordinator(
    $attemptBudgetCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$attemptBudgetCallbacks): void { $attemptBudgetCallbacks[] = $callback; },
    static function (): array { throw new UpstreamBudgetExhaustedException('attempts'); },
    static function (array $stats) use (&$attemptBudgetEvents): void { $attemptBudgetEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 581,
);
$attemptBudgetCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('131', 2), prefetchCandidate('131', 3)],
);
($attemptBudgetCallbacks[0])();
assertPrefetchSame(1, $attemptBudgetEvents[0]['attempted'] ?? null, 'attempt budget stop counts the executor invocation');
assertPrefetchSame('budget-attempts', $attemptBudgetEvents[0]['skip_reason'] ?? null, 'attempt budget exhaustion has a fixed reason');
assertPrefetchSame([], $attemptBudgetCache->values, 'attempt budget exhaustion releases every owned token');
assertPrefetchSame([], $attemptBudgetCache->authorityLocks, 'attempt budget exhaustion releases every authority handle');

$exceptionCache = new FakePrefetchCache();
$exceptionCallbacks = [];
$exceptionEvents = [];
$exceptionCoordinator = new PrefetchCoordinator(
    $exceptionCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$exceptionCallbacks): void { $exceptionCallbacks[] = $callback; },
    static function (array $candidate, int $remainingMs): array { throw new RuntimeException('fixture executor failure'); },
    static function (array $stats) use (&$exceptionEvents): void { $exceptionEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 601,
);
$exceptionCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('14', 2), prefetchCandidate('14', 3)],
);
($exceptionCallbacks[0])();
assertPrefetchSame(1, $exceptionEvents[0]['attempted'] ?? null, 'attempted increments immediately before an executor call');
assertPrefetchSame('executor-error', $exceptionEvents[0]['skip_reason'] ?? null, 'executor exceptions have a fixed reason');
assertPrefetchSame([], $exceptionCache->values, 'executor exceptions still release every owned token');

$abaCache = new FakePrefetchCache();
$abaCallbacks = [];
$abaEvents = [];
$abaOwnershipEvents = [];
$abaToken = 700;
$secondAbaKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('15', 3));
$abaCoordinator = new PrefetchCoordinator(
    $abaCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$abaCallbacks): void { $abaCallbacks[] = $callback; },
    static function (array $candidate, int $remainingMs) use ($abaCache, $secondAbaKey): array {
        $abaCache->values[$secondAbaKey] = 9999;
        return ['cache_hit' => false, 'cache_store' => 'stored', 'upstream_bytes' => 1];
    },
    static function (array $stats) use (&$abaEvents): void { $abaEvents[] = $stats; },
    static fn(): bool => true,
    static function () use (&$abaToken): int { return ++$abaToken; },
    static function (array $event) use (&$abaOwnershipEvents): void { $abaOwnershipEvents[] = $event; },
);
$abaCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('15', 2), prefetchCandidate('15', 3)],
);
($abaCallbacks[0])();
assertPrefetchSame(1, $abaEvents[0]['attempted'] ?? null, 'an old owner stops before a page whose token changed');
assertPrefetchSame('page-lease-lost', $abaEvents[0]['skip_reason'] ?? null, 'page ownership loss has a fixed reason');
assertPrefetchSame(9999, $abaCache->get($secondAbaKey), 'old-owner cleanup cannot delete a replacement page token');
$secondPageOwnershipEvents = array_values(array_filter(
    $abaOwnershipEvents,
    static fn(array $event): bool => ($event['photo_id'] ?? null) === '15' && ($event['page'] ?? null) === 3,
));
assertPrefetchSame(['page-owner-acquire', 'page-owner-renew', 'page-owner-release'], array_column($secondPageOwnershipEvents, 'event'), 'authoritative page cleanup releases ownership without deleting a foreign APCu mirror');

$initialPageLostCache = new FakePrefetchCache();
$initialPageLostCallbacks = [];
$initialPageLostEvents = [];
$initialPageLostExecutions = 0;
$initialPageLostCoordinator = new PrefetchCoordinator(
    $initialPageLostCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$initialPageLostCallbacks): void { $initialPageLostCallbacks[] = $callback; },
    static function () use (&$initialPageLostExecutions): array { $initialPageLostExecutions++; return []; },
    static function (array $stats) use (&$initialPageLostEvents): void { $initialPageLostEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 750,
);
$initialPageLostCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('32', 2)],
);
$initialPageLostKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('32', 2));
$initialPageLostCache->values[$initialPageLostKey] = 9998;
($initialPageLostCallbacks[0])();
assertPrefetchSame(0, $initialPageLostExecutions, 'callback-start page token mismatch prevents every executor call');
assertPrefetchSame('page-lease-lost', $initialPageLostEvents[0]['skip_reason'] ?? null, 'callback-start page token mismatch has a fixed reason');
assertPrefetchSame(9998, $initialPageLostCache->get($initialPageLostKey), 'failed renewal and cleanup preserve a replacement page owner');

$refreshStoreFailureCache = new FakePrefetchCache();
$refreshStoreFailureCallbacks = [];
$refreshStoreFailureEvents = [];
$refreshStoreFailureExecutions = 0;
$refreshStoreFailureCoordinator = new PrefetchCoordinator(
    $refreshStoreFailureCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$refreshStoreFailureCallbacks): void { $refreshStoreFailureCallbacks[] = $callback; },
    static function () use (&$refreshStoreFailureExecutions): array { $refreshStoreFailureExecutions++; return []; },
    static function (array $stats) use (&$refreshStoreFailureEvents): void { $refreshStoreFailureEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 760,
);
$refreshStoreFailureCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('33', 2)],
);
$refreshStoreFailureKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('33', 2));
$refreshStoreFailureCache->refreshStoreFailures[$refreshStoreFailureKey] = 1;
($refreshStoreFailureCallbacks[0])();
assertPrefetchSame(0, $refreshStoreFailureExecutions, 'refresh store failure prevents every executor call');
assertPrefetchSame('page-lease-lost', $refreshStoreFailureEvents[0]['skip_reason'] ?? null, 'refresh store failure uses the page ownership loss reason');
assertPrefetchSame([], $refreshStoreFailureCache->values, 'refresh store failure still permits token-safe cleanup of the old lease');
assertPrefetchSame([], $refreshStoreFailureCache->authorityLocks, 'refresh store failure releases its authority handle');

$mutexBusyCache = new FakePrefetchCache();
$mutexBusyCallbacks = [];
$mutexBusyEvents = [];
$mutexBusyExecutions = 0;
$mutexBusyCoordinator = new PrefetchCoordinator(
    $mutexBusyCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$mutexBusyCallbacks): void { $mutexBusyCallbacks[] = $callback; },
    static function () use (&$mutexBusyExecutions): array { $mutexBusyExecutions++; return []; },
    static function (array $stats) use (&$mutexBusyEvents): void { $mutexBusyEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 770,
);
$mutexBusyKey = PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('34', 2));
$mutexBusyCache->authorityBusyKeys[$mutexBusyKey] = true;
$mutexBusySchedule = $mutexBusyCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('34', 2)],
);
assertPrefetchSame('skipped-pages-covered', $mutexBusySchedule['skip_reason'] ?? null, 'a busy page authority flock skips the unowned candidate');
assertPrefetchSame(0, count($mutexBusyCallbacks), 'a busy page authority flock registers no callback');
assertPrefetchSame(0, $mutexBusyExecutions, 'a busy page authority flock prevents every executor call');
assertPrefetchSame(null, $mutexBusyCache->get($mutexBusyKey), 'a busy page authority flock creates no APCu mirror');

$slotMutexBusyCache = new FakePrefetchCache();
$slotMutexBusyCallbacks = [];
$slotMutexBusyEvents = [];
$slotMutexBusyExecutions = 0;
$slotMutexBusyCoordinator = new PrefetchCoordinator(
    $slotMutexBusyCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$slotMutexBusyCallbacks): void { $slotMutexBusyCallbacks[] = $callback; },
    static function () use (&$slotMutexBusyExecutions): array { $slotMutexBusyExecutions++; return []; },
    static function (array $stats) use (&$slotMutexBusyEvents): void { $slotMutexBusyEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 780,
);
$slotMutexBusyCache->values['prefetch-slot:0'] = 8880;
$slotMutexBusyCache->authorityBusyKeys['prefetch-slot:0'] = true;
$slotMutexBusySchedule = $slotMutexBusyCoordinator->schedule(
    true, 10, 1, 5000, 1000, 0,
    static fn(): array => [prefetchCandidate('35', 2)],
);
assertPrefetchSame('skipped-busy', $slotMutexBusySchedule['skip_reason'] ?? null, 'a busy slot authority flock fails closed before callback registration');
assertPrefetchSame(0, count($slotMutexBusyCallbacks), 'a busy slot authority flock registers no callback');
assertPrefetchSame(0, $slotMutexBusyExecutions, 'a busy slot authority flock prevents every executor call');
assertPrefetchSame(null, $slotMutexBusyCache->get(PrefetchCoordinator::candidateLeaseKey(prefetchCandidate('35', 2))), 'slot authority failure releases every page APCu mirror');
assertPrefetchSame(8880, $slotMutexBusyCache->get('prefetch-slot:0'), 'slot authority failure preserves a foreign APCu mirror');
assertPrefetchSame([], $slotMutexBusyCache->authorityLocks, 'slot authority failure releases every page authority');

$slotAbaCache = new FakePrefetchCache();
$slotAbaCallbacks = [];
$slotAbaEvents = [];
$slotAbaOwnershipEvents = [];
$slotAbaCoordinator = new PrefetchCoordinator(
    $slotAbaCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$slotAbaCallbacks): void { $slotAbaCallbacks[] = $callback; },
    static fn(array $candidate, int $remainingMs): array => [],
    static function (array $stats) use (&$slotAbaEvents): void { $slotAbaEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 801,
    static function (array $event) use (&$slotAbaOwnershipEvents): void { $slotAbaOwnershipEvents[] = $event; },
);
$slotAbaCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('16', 2)],
);
$slotAbaCache->values['prefetch-slot:0'] = 8888;
($slotAbaCallbacks[0])();
assertPrefetchSame(0, $slotAbaEvents[0]['attempted'] ?? null, 'a callback delayed beyond lease ownership validates its token and executes zero pages');
assertPrefetchSame('slot-lost', $slotAbaEvents[0]['skip_reason'] ?? null, 'slot ownership loss has a fixed reason');
assertPrefetchSame(8888, $slotAbaCache->get('prefetch-slot:0'), 'old-owner cleanup cannot delete a replacement slot token');
assertPrefetchSame(['slot-acquire', 'callback-start', 'slot-release'], array_values(array_filter(
    array_column($slotAbaOwnershipEvents, 'event'),
    static fn(string $event): bool => str_starts_with($event, 'slot-') || $event === 'callback-start',
)), 'authoritative slot cleanup releases ownership without deleting a foreign APCu mirror');

$sinkCache = new FakePrefetchCache();
$sinkCallbacks = [];
$sinkCoordinator = new PrefetchCoordinator(
    $sinkCache,
    static fn(): int => 0,
    static function (callable $callback) use (&$sinkCallbacks): void { $sinkCallbacks[] = $callback; },
    static fn(array $candidate, int $remainingMs): array => ['cache_hit' => true, 'cache_store' => 'hit', 'upstream_bytes' => 0],
    static function (array $stats): void { throw new RuntimeException('fixture stats sink failure'); },
    static fn(): bool => true,
    static fn(): int => 901,
);
$sinkCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('17', 2)],
);
($sinkCallbacks[0])();
assertPrefetchSame([], $sinkCache->values, 'stats sink exceptions cannot prevent callback cleanup');

$registrationCache = new FakePrefetchCache();
$registrationEvents = [];
$registrationCoordinator = new PrefetchCoordinator(
    $registrationCache,
    static fn(): int => 0,
    static function (callable $callback): void { throw new RuntimeException('fixture registrar failure'); },
    static fn(array $candidate, int $remainingMs): array => [],
    static function (array $stats) use (&$registrationEvents): void { $registrationEvents[] = $stats; },
    static fn(): bool => true,
    static fn(): int => 1001,
);
$registrationStats = $registrationCoordinator->schedule(
    true, 10, 1, 5000, 1000, 1000,
    static fn(): array => [prefetchCandidate('18', 2)],
);
assertPrefetchSame('skipped-registration', $registrationStats['skip_reason'], 'registration failures have a fixed reason');
assertPrefetchSame([], $registrationCache->values, 'registration failures release page and slot ownership');

$sharedBudget = new UpstreamBudget(5000, 5);
$outerRemaining = $sharedBudget->withSecondaryCap(100, static function (UpstreamBudget $budget): int {
    $remaining = $budget->remainingMs();
    if ($remaining < 1 || $remaining > 100) {
        throw new RuntimeException('secondary cap did not shorten the existing budget');
    }
    $nestedRemaining = $budget->withSecondaryCap(1000, static fn(UpstreamBudget $nested): int => $nested->remainingMs());
    if ($nestedRemaining > $remaining) {
        throw new RuntimeException('nested secondary cap extended the outer cap');
    }
    if (!$budget->beginAttempt()) {
        throw new RuntimeException('secondary cap unexpectedly replaced the original attempt allowance');
    }
    return $remaining;
});
if ($outerRemaining > 100) throw new RuntimeException('secondary cap returned an invalid remaining budget');
assertPrefetchSame(1, $sharedBudget->attempts(), 'secondary work continues the original attempt counter');
if ($sharedBudget->remainingMs() <= 1000) {
    throw new RuntimeException('leaving a secondary cap did not restore the longer original deadline');
}

$prefetchContext = RequestContext::forTest('image', 5000, 5);
assertPrefetchSame(false, in_array('X-JM-Test-Prefetch: 1', $prefetchContext->testHeaders(), true), 'ordinary test requests are not labeled as prefetch work');
$scopeHeaders = $prefetchContext->withPrefetchScope(static fn(RequestContext $context): array => $context->testHeaders());
assertPrefetchSame(true, in_array('X-JM-Test-Prefetch: 1', $scopeHeaders, true), 'prefetch executor work is labeled only inside its test scope');
assertPrefetchSame(false, in_array('X-JM-Test-Prefetch: 1', $prefetchContext->testHeaders(), true), 'prefetch test scope is restored after executor work');

$coverageCalls = 0;
$planned = PrefetchCoordinator::planCandidates(
    '20',
    8,
    10,
    10,
    2,
    '21',
    true,
    2,
    static function (string $photoId, int $page) use (&$coverageCalls): bool {
        $coverageCalls++;
        return ($photoId === '20' && $page === 9) || ($photoId === '21' && $page === 1);
    },
);
assertPrefetchSame([
    prefetchCandidate('20', 9, priority: 0, covered: true),
    prefetchCandidate('20', 10, priority: 0, covered: false),
    prefetchCandidate('21', 1, priority: 1, covered: true),
    prefetchCandidate('21', 2, priority: 1, covered: false),
], $planned, 'candidate planning clips the known current manifest and appends eligible next-chapter pages');
assertPrefetchSame(4, $coverageCalls, 'candidate planning checks every current and next-chapter candidate for known cache coverage');

$zeroPlannerCalls = 0;
$zeroPlan = PrefetchCoordinator::planCandidates(
    '20', 1, 20, 0, 2, '21', true, 2,
    static function () use (&$zeroPlannerCalls): bool { $zeroPlannerCalls++; return false; },
);
assertPrefetchSame([], $zeroPlan, 'pages zero disables current and next-chapter candidate generation');
assertPrefetchSame(0, $zeroPlannerCalls, 'pages zero exits before any coverage lookup');
assertPrefetchSame(true, PrefetchCoordinator::isNearEnd(8, 10, 6, 80), 'near-end accepts the configured progress boundary');
assertPrefetchSame(false, PrefetchCoordinator::isNearEnd(1, 10, 6, 80), 'near-end rejects an early page');

$observerDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jm-prefetch-policy-' . bin2hex(random_bytes(6));
if (!mkdir($observerDirectory, 0700, true) && !is_dir($observerDirectory)) {
    throw new RuntimeException('failed to create prefetch observer test directory');
}
$observerPath = $observerDirectory . DIRECTORY_SEPARATOR . '12345678-abcd.json';
try {
    $observerRunId = '12345678-abcd';
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'page-owner-acquire', 'photo_id' => '20', 'page' => 2]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'page-owner-renew', 'photo_id' => '20', 'page' => 2]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'page-owner-release-lost', 'photo_id' => '20', 'page' => 2]);
    $observerAfterLost = json_decode((string) file_get_contents($observerDirectory . DIRECTORY_SEPARATOR . $observerRunId . '.json'), true);
    assertPrefetchSame(1, $observerAfterLost['prefetch-page-owner-current|20|2'] ?? null, 'renew and release-lost do not falsely change the observed owner count');
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'page-owner-release', 'photo_id' => '20', 'page' => 2]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'slot-acquire', 'slot' => 0]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'slot-acquire', 'slot' => 1]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'slot-renew', 'slot' => 0]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'slot-release', 'slot' => 0]);
    PrefetchTestObserver::record($observerDirectory, $observerRunId, ['event' => 'slot-release', 'slot' => 1]);
    $observerData = json_decode((string) file_get_contents($observerDirectory . DIRECTORY_SEPARATOR . $observerRunId . '.json'), true);
    assertPrefetchSame(1, $observerData['prefetch-page-owner-acquire|20|2'] ?? null, 'test observer records direct page-owner acquisition');
    assertPrefetchSame(1, $observerData['prefetch-page-owner-renew|20|2'] ?? null, 'test observer records page-owner renewal without changing current ownership');
    assertPrefetchSame(1, $observerData['prefetch-page-owner-release|20|2'] ?? null, 'test observer records direct page-owner release');
    assertPrefetchSame(1, $observerData['prefetch-page-owner-release-lost|20|2'] ?? null, 'test observer records token-lost page cleanup separately');
    assertPrefetchSame(0, $observerData['prefetch-page-owner-current|20|2'] ?? null, 'test observer page held count returns to zero');
    assertPrefetchSame(1, $observerData['prefetch-page-owner-peak|20|2'] ?? null, 'test observer records page-owner peak');
    assertPrefetchSame(2, $observerData['prefetch-slot-acquire'] ?? null, 'test observer records slot acquisitions');
    assertPrefetchSame(1, $observerData['prefetch-slot-renew'] ?? null, 'test observer records slot renewal without changing current ownership');
    assertPrefetchSame(2, $observerData['prefetch-slot-release'] ?? null, 'test observer records slot releases');
    assertPrefetchSame(0, $observerData['prefetch-slot-current'] ?? null, 'test observer slot held count returns to zero');
    assertPrefetchSame(2, $observerData['prefetch-slot-peak'] ?? null, 'test observer records direct slot peak');
} finally {
    if (is_file($observerPath) && !unlink($observerPath)) {
        throw new RuntimeException('failed to remove prefetch observer test file');
    }
    if (file_exists($observerPath)) {
        throw new RuntimeException('prefetch observer test file remained after cleanup');
    }
    if (is_dir($observerDirectory) && !rmdir($observerDirectory)) {
        throw new RuntimeException('failed to remove prefetch observer test directory');
    }
    if (file_exists($observerDirectory)) {
        throw new RuntimeException('prefetch observer test directory remained after cleanup');
    }
}
assertPrefetchSame(false, file_exists($observerDirectory), 'observer cleanup leaves no residual directory');

echo "Prefetch policy runtime tests passed.\n";
