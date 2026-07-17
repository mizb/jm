<?php
declare(strict_types=1);

const REDIS_GATE_BARRIER_TIMEOUT_MS = 30_000;

function gateFail(string $message): never
{
    throw new RuntimeException($message);
}

function gateAssert(bool $condition, string $message): void
{
    if (!$condition) gateFail($message);
}

/** @param array<string,mixed> $payload */
function gateJson(array $payload): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

function gateLoadApi(): void
{
    if (!class_exists('Redis', false)) gateFail('The phpredis extension is required for the real Redis gate.');
    if (!defined('JM_API_LIBRARY_ONLY')) define('JM_API_LIBRARY_ONLY', true);
    require_once dirname(__DIR__) . '/index.php';
}

function gatePositiveInt(mixed $value, string $name, int $maximum = 1_000_000): int
{
    $raw = is_string($value) ? $value : (string) $value;
    if (preg_match('/^[1-9]\d*$/D', $raw) !== 1) gateFail("{$name} must be a positive integer.");
    $parsed = (int) $raw;
    if ($parsed < 1 || $parsed > $maximum) gateFail("{$name} is outside the supported range.");
    return $parsed;
}

function gateSafeToken(mixed $value, string $name): string
{
    $token = is_string($value) ? $value : '';
    if (preg_match('/^[A-Za-z0-9:_-]{1,160}$/D', $token) !== 1) gateFail("{$name} is invalid.");
    return $token;
}

function gateRedis(string $host, int $port, float $timeoutSeconds = 1.0): Redis
{
    $redis = new Redis();
    if (!$redis->connect($host, $port, $timeoutSeconds, null, 0, $timeoutSeconds)) {
        gateFail("Unable to connect to real Redis at {$host}:{$port}.");
    }
    return $redis;
}

function gateCommandStats(Redis $redis): array
{
    $info = $redis->info('commandstats');
    gateAssert(is_array($info), 'Redis INFO commandstats returned a non-array response.');
    $raw = $info['cmdstat_eval'] ?? null;
    if ($raw === null) return ['eval_calls' => 0, 'raw' => null];
    gateAssert(is_string($raw), 'Redis cmdstat_eval is malformed.');
    gateAssert(preg_match('/(?:^|,)calls=(\d+)(?:,|$)/D', $raw, $match) === 1, 'Redis cmdstat_eval omitted calls.');
    return ['eval_calls' => (int) $match[1], 'raw' => $raw];
}

function gateSetRedisEnvironment(string $host, int $port): void
{
    putenv('REDIS_HOST=' . $host);
    putenv('REDIS_PORT=' . $port);
    putenv('REDIS_TIMEOUT_MS=150');
}

function gateWaitForBarrier(string $readyPath, string $releasePath, string $token): void
{
    gateAssert(!file_exists($readyPath), "Worker ready path already exists: {$readyPath}");
    $handle = @fopen($readyPath, 'x+b');
    gateAssert(is_resource($handle), "Unable to create worker ready path: {$readyPath}");
    try {
        gateAssert(fwrite($handle, $token) === strlen($token), 'Unable to write the complete worker ready token.');
        gateAssert(fflush($handle), 'Unable to flush the worker ready token.');
    } finally {
        fclose($handle);
    }

    $started = hrtime(true);
    while (!is_file($releasePath)) {
        if (((hrtime(true) - $started) / 1_000_000) >= REDIS_GATE_BARRIER_TIMEOUT_MS) {
            gateFail('Timed out waiting for the Redis concurrency barrier release.');
        }
        usleep(10_000);
    }
    $releaseToken = file_get_contents($releasePath);
    gateAssert($releaseToken === $token, 'Redis concurrency barrier token mismatch.');
}

function gateHandleHttpRequest(): never
{
    gateLoadApi();
    $action = $_GET['action'] ?? '';
    if ($action === 'health') gateJson(['ok' => true, 'apcu' => function_exists('apcu_fetch')]);
    gateAssert($action === 'check', 'Unknown Redis gate HTTP action.');

    $host = getenv('REDIS_HOST');
    $port = gatePositiveInt(getenv('REDIS_PORT'), 'REDIS_PORT', 65_535);
    $prefix = gateSafeToken(getenv('REDIS_TEST_PREFIX'), 'REDIS_TEST_PREFIX');
    $key = gateSafeToken($_GET['key'] ?? '', 'key');
    $window = gatePositiveInt($_GET['window'] ?? '', 'window', 3_600);
    $maximum = gatePositiveInt($_GET['max'] ?? '', 'max', 10_000);
    gateAssert(is_string($host) && $host !== '', 'REDIS_HOST is unavailable.');

    $started = hrtime(true);
    $result = (new RedisStore(prefix: $prefix))->checkRate($key, $window, $maximum);
    gateJson([
        'ok' => true,
        'result' => $result,
        'elapsed_ms' => (int) ceil((hrtime(true) - $started) / 1_000_000),
    ]);
}

if (PHP_SAPI === 'cli-server') gateHandleHttpRequest();

try {
    gateLoadApi();
    $mode = $argv[1] ?? '';

    if ($mode === 'worker') {
        gateAssert(count($argv) === 12, 'worker expects exactly ten arguments.');
        $host = $argv[2];
        $port = gatePositiveInt($argv[3], 'port', 65_535);
        $prefix = gateSafeToken($argv[4], 'prefix');
        $key = gateSafeToken($argv[5], 'key');
        $window = gatePositiveInt($argv[6], 'window', 3_600);
        $maximum = gatePositiveInt($argv[7], 'max', 10_000);
        $readyPath = $argv[8];
        $releasePath = $argv[9];
        $barrierToken = gateSafeToken($argv[10], 'barrier token');
        $workerId = gatePositiveInt($argv[11], 'worker id', 10_000);
        gateSetRedisEnvironment($host, $port);
        gateWaitForBarrier($readyPath, $releasePath, $barrierToken);

        $started = hrtime(true);
        $result = (new RedisStore(prefix: $prefix))->checkRate($key, $window, $maximum);
        gateJson([
            'ok' => true,
            'worker' => $workerId,
            'pid' => getmypid(),
            'result' => $result,
            'elapsed_ms' => (int) ceil((hrtime(true) - $started) / 1_000_000),
        ]);
    }

    gateAssert(in_array($mode, ['ping', 'reset', 'inspect', 'seed-future'], true), 'Unknown Redis gate CLI mode.');
    gateAssert(count($argv) >= 4, "{$mode} expects host and port.");
    $host = $argv[2];
    $port = gatePositiveInt($argv[3], 'port', 65_535);
    $redis = gateRedis($host, $port);
    try {
        if ($mode === 'ping') {
            $pong = $redis->ping();
            $server = $redis->info('server');
            gateJson([
                'ok' => $pong === true || $pong === '+PONG' || $pong === 'PONG',
                'redis_version' => is_array($server) ? ($server['redis_version'] ?? null) : null,
            ]);
        }
        if ($mode === 'reset') {
            gateAssert($redis->flushDB(), 'Redis FLUSHDB failed.');
            $reset = $redis->rawCommand('CONFIG', 'RESETSTAT');
            gateAssert($reset === true || $reset === 'OK' || $reset === '+OK', 'Redis CONFIG RESETSTAT failed.');
            gateJson(['ok' => true]);
        }
        if ($mode === 'seed-future') {
            gateAssert(count($argv) === 7, 'seed-future expects a physical key, count, and future offset.');
            $physicalKey = gateSafeToken($argv[4], 'physical key');
            $count = gatePositiveInt($argv[5], 'future member count', 10_000);
            $futureSeconds = gatePositiveInt($argv[6], 'future offset seconds', 3_600);
            $scoreMs = (int) floor(microtime(true) * 1000) + ($futureSeconds * 1000);
            for ($index = 1; $index <= $count; $index++) {
                gateAssert(
                    $redis->zAdd($physicalKey, (float) $scoreMs, "future:{$index}") === 1,
                    "Redis future-score seed {$index} failed.",
                );
            }
            gateJson([
                'ok' => true,
                'physical_count' => (int) $redis->zCard($physicalKey),
                'score_ms' => $scoreMs,
            ]);
        }

        gateAssert(count($argv) === 6, 'inspect expects physical and unprefixed keys.');
        $physicalKey = gateSafeToken($argv[4], 'physical key');
        $unprefixedKey = gateSafeToken($argv[5], 'unprefixed key');
        $stats = gateCommandStats($redis);
        gateJson([
            'ok' => true,
            'physical_exists' => (int) $redis->exists($physicalKey),
            'physical_count' => (int) $redis->zCard($physicalKey),
            'unprefixed_exists' => (int) $redis->exists($unprefixedKey),
            'eval_calls' => $stats['eval_calls'],
            'eval_raw' => $stats['raw'],
        ]);
    } finally {
        $redis->close();
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
