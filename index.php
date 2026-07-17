<?php
/**
 * JM Comic Viewer — PHP 8
 * 禁漫 API 客户端
 *
 * Usage:
 *   ?jmid=<id>                     → 专辑信息 + 章节目录
 *   ?jmid=<id>&chapter=<pid>       → 单章详情 + 图片 URL
 *   ?jmid=<id>&chapter=all         → 全部章节
 *   ?jmid=<id>&chapter=<id1,id2>   → 批量
 *   ?jmid=<id>&chapter=@3          → 按序号
 *   ?jmid=<id>&format=min          → 紧凑 JSON
 *
 * Based on jmcomic https://github.com/hect0x7/JMComic-Crawler-Python
 */

declare(strict_types=1);


// ═════════════════════════════════════════════════════════════════════════════
// Config
// ═════════════════════════════════════════════════════════════════════════════

final class JmConfig
{
    public const APP_VERSION    = '2026.07.17.7';
    public const VERSION        = '2.0.26';
    public const TOKEN_SECRET   = '185Hcomic3PAPP7R';
    public const TOKEN_SECRET2  = '18comicAPPContent';
    public const DATA_SECRET    = '185Hcomic3PAPP7R';
    public const DOMAIN_SECRET  = 'diosfjckwpqpdfjkvnqQjsik';

    public const ENDPOINT_ALBUM    = '/album';
    public const ENDPOINT_CHAPTER  = '/chapter';
    public const ENDPOINT_SCRAMBLE = '/chapter_view_template';
    public const ENDPOINT_LATEST   = '/latest';
    public const ENDPOINT_SEARCH   = '/search';
    public const ENDPOINT_CATEGORY_FILTER = '/categories/filter';
    public const ENDPOINT_PROMOTE = '/promote';
    public const ENDPOINT_PROMOTE_LIST = '/promote_list';
    public const ENDPOINT_WEEK = '/week';
    public const ENDPOINT_WEEK_FILTER = '/week/filter';

    public const SCRAMBLE_220980 = 220980;
    public const SCRAMBLE_268850 = 268850;
    public const SCRAMBLE_421926 = 421926;

    public const API_DOMAINS = [
        'www.cdnhjk.net',
        'www.cdngwc.cc',
        'www.cdngwc.net',
        'www.cdngwc.club',
        'www.cdnutc.me',
    ];

    public const DOMAIN_SERVER_URLS = [
        'https://rup4a04-c01.tos-ap-southeast-1.bytepluses.com/newsvr-2025.txt',
        'https://rup4a04-c02.tos-cn-hongkong.bytepluses.com/newsvr-2025.txt',
        'https://rup4a04-c03.tos-cn-beijing.bytepluses.com.cn/newsvr-2025.txt',
    ];

    public const CDN_DOMAINS = [
        'cdn-msp.jmapiproxy1.cc',
        'cdn-msp.jmapiproxy2.cc',
        'cdn-msp2.jmapiproxy2.cc',
        'cdn-msp3.jmapiproxy2.cc',
        'cdn-msp.jmapinodeudzn.net',
        'cdn-msp3.jmapinodeudzn.net',
    ];

    public const UA = 'Mozilla/5.0 (Linux; Android 9; V1938CT Build/PQ3A.190705.11211812; wv) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/91.0.4472.114 Safari/537.36';

    public const HTTP_TIMEOUT   = 8;
    public const CONNECT_TIMEOUT = 5;
    public const MAX_RETRIES     = 2;
    public const DEFAULT_REQUEST_BUDGET_MS = 12000;
    public const DEFAULT_MAX_UPSTREAM_ATTEMPTS = 15;

    // ── Security limits ──
    public const RATE_WINDOW       = 60;    // sliding window (seconds)
    public const RATE_MAX_REQUESTS = 30;    // max requests per window per IP
    public const RATE_PENALTY      = 300;   // ban duration after exceeding limit
    public const MAX_CHAPTERS      = 50;    // max chapters per request (anti-abuse)
    public const JMID_MAX_LENGTH   = 20;    // jmid max characters

    public const DEFAULT_PAGE_CACHE_TTL    = 3600;
    public const DEFAULT_CHAPTER_CACHE_TTL = 21600;
    public const DEFAULT_PREFETCH_PAGES = 10;
    public const DEFAULT_PREFETCH_HIGH_PRIORITY_PAGES = 2;
    public const DEFAULT_PREFETCH_WALL_BUDGET_MS = 5000;
    public const DEFAULT_PREFETCH_BYTE_BUDGET = 16777216;
    public const DEFAULT_PREFETCH_MAX_ACTIVE = 2;
    public const DEFAULT_PAGE_CACHE_MAX_ITEM_BYTES = 104857600;
    public const DEFAULT_PAGE_CACHE_MAX_BYTES = 104857600;
    public const DEFAULT_IMAGE_MAX_COMPRESSED_BYTES = 33554432;
    public const DEFAULT_IMAGE_MAX_PIXELS = 80000000;
    public const DEFAULT_SINGLEFLIGHT_LOCK_TTL = 30;
    public const DEFAULT_SINGLEFLIGHT_WAIT_MS = 5000;
    public const DEFAULT_PREFETCH_MIN_FREE_BYTES = 33554432;
    public const DEFAULT_PREFETCH_MIN_FREE_RATIO = 15;
    public const DEFAULT_PAGE_CACHE_MIN_FREE_BYTES = 16777216;
    public const DEFAULT_PAGE_CACHE_MIN_FREE_RATIO = 8;
    public const DEFAULT_NEXT_CHAPTER_PREFETCH_PAGES = 2;
    public const DEFAULT_NEXT_CHAPTER_PREFETCH_PROGRESS = 80;
    public const DEFAULT_NEXT_CHAPTER_PREFETCH_REMAINING = 6;
    public const DEFAULT_DOMAIN_COOLDOWN_SECONDS = 120;
    public const DEFAULT_DOMAIN_STATS_TTL = 21600;
    public const DEFAULT_LIST_CACHE_TTL = 60;
    public const DEFAULT_SEARCH_CACHE_TTL = 30;
    public const DEFAULT_WEEKLY_LIST_CACHE_TTL = 60;
    public const DEFAULT_ALBUM_CACHE_TTL = 45;
    public const DEFAULT_WEEK_DEFAULTS_CACHE_TTL = 600;
    public const DEFAULT_WEEK_DEFAULTS_STALE_TTL = 3600;
    public const DEFAULT_CACHE_FILL_WAIT_MS = 750;
    public const DEFAULT_CACHE_FILL_LOCK_TTL = 15;
}


// ═════════════════════════════════════════════════════════════════════════════
// HTTP Client
// ═════════════════════════════════════════════════════════════════════════════

final readonly class HttpResult
{
    /** @param array<string,string> $headers @param array<string,int|float> $timings */
    public function __construct(
        public bool $ok,
        public string $body,
        public int $status,
        public array $headers,
        public int $curlErrno,
        public string $curlError,
        public array $timings,
        public bool $bodyLimitExceeded = false,
        public int $receivedBytes = 0,
    ) {}
}

interface UpstreamTransport
{
    /** @param list<string> $headers */
    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult;
}

final class ResponseBodyCollector
{
    private string $body = '';
    private bool $limitExceeded = false;
    private int $receivedBytes = 0;

    public function __construct(private int $limitBytes = 0)
    {
        $this->limitBytes = max(0, $this->limitBytes);
    }

    public function consumeHeaderLine(string $line): int
    {
        $length = strlen($line);
        if ($this->limitExceeded) return 0;
        if ($this->limitBytes <= 0) return $length;

        $parts = explode(':', $line, 2);
        if (count($parts) !== 2 || strtolower(trim($parts[0])) !== 'content-length') return $length;
        $value = trim($parts[1]);
        if (preg_match('/^\d+$/', $value) !== 1) return $length;
        $normalized = ltrim($value, '0');
        if ($normalized === '') $normalized = '0';
        $limit = (string) $this->limitBytes;
        if (strlen($normalized) > strlen($limit)
            || (strlen($normalized) === strlen($limit) && strcmp($normalized, $limit) > 0)
        ) {
            $this->limitExceeded = true;
            return 0;
        }
        return $length;
    }

    public function consumeChunk(string $chunk): int
    {
        if ($this->limitExceeded) return 0;
        $chunkBytes = strlen($chunk);
        $this->receivedBytes = $chunkBytes > PHP_INT_MAX - $this->receivedBytes
            ? PHP_INT_MAX
            : $this->receivedBytes + $chunkBytes;
        if ($this->limitBytes > 0 && $chunkBytes > $this->limitBytes - strlen($this->body)) {
            $this->limitExceeded = true;
            return 0;
        }
        $this->body .= $chunk;
        return $chunkBytes;
    }

    public function body(): string { return $this->body; }
    public function limitExceeded(): bool { return $this->limitExceeded; }
    public function receivedBytes(): int { return $this->receivedBytes; }
}

final class CurlFailure
{
    public static function category(int $errno): string
    {
        // libcurl error numbers are stable even when PHP builds expose different TLS constant aliases.
        return match ($errno) {
            5, 6 => 'dns',
            7 => 'connect',
            35, 51, 53, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => 'tls',
            28 => 'timeout',
            default => 'network',
        };
    }
}

final class RetryAfter
{
    public static function delayMs(?string $value, int $now, int $remainingMs): int
    {
        $value = trim((string) $value);
        if ($value === '' || $remainingMs <= 0) return 0;
        if (preg_match('/^\d+$/', $value) === 1) {
            return min($remainingMs, max(0, (int) $value * 1000));
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new \DateTimeZone('GMT'));
        if ($date === false || $date->format(DATE_RFC7231) !== $value) return 0;
        return min($remainingMs, max(0, ($date->getTimestamp() - $now) * 1000));
    }
}

final class UpstreamBudgetExhaustedException extends JmException
{
    public const REASON_ATTEMPTS = 'attempts';
    public const REASON_WALL = 'wall';
    public const REASON_BYTES = 'bytes';

    public function __construct(private string $reason)
    {
        if (!in_array($reason, [self::REASON_ATTEMPTS, self::REASON_WALL, self::REASON_BYTES], true)) {
            throw new \InvalidArgumentException('Invalid upstream budget exhaustion reason');
        }
        parent::__construct('Upstream request budget exhausted: ' . $reason, 502);
    }

    public function reason(): string { return $this->reason; }

    public function prefetchSkipReason(): string
    {
        return match ($this->reason) {
            self::REASON_ATTEMPTS => 'budget-attempts',
            self::REASON_WALL => 'budget-wall',
            self::REASON_BYTES => 'budget-bytes',
        };
    }
}

final class UpstreamBudget
{
    private int $startedNs;
    private int $attempts = 0;
    private bool $deadlineExhausted = false;
    private ?int $secondaryDeadlineNs = null;
    private ?string $denialReason = null;

    public function __construct(private int $budgetMs, private int $maxAttempts)
    {
        $this->startedNs = hrtime(true);
    }

    public function remainingMs(): int
    {
        $nowNs = hrtime(true);
        $deadlineNs = $this->startedNs + ($this->budgetMs * 1_000_000);
        if ($this->secondaryDeadlineNs !== null) {
            $deadlineNs = min($deadlineNs, $this->secondaryDeadlineNs);
        }
        return max(0, (int) floor(($deadlineNs - $nowNs) / 1_000_000));
    }

    public function withSecondaryCap(int $capMs, callable $operation): mixed
    {
        $previousDeadlineNs = $this->secondaryDeadlineNs;
        $candidateDeadlineNs = hrtime(true) + (max(0, $capMs) * 1_000_000);
        $this->secondaryDeadlineNs = $previousDeadlineNs === null
            ? $candidateDeadlineNs
            : min($previousDeadlineNs, $candidateDeadlineNs);
        try {
            return $operation($this);
        } finally {
            $this->secondaryDeadlineNs = $previousDeadlineNs;
        }
    }

    public function beginAttempt(): bool
    {
        if ($this->remainingMs() <= 0) {
            $this->deadlineExhausted = true;
            $this->denialReason = UpstreamBudgetExhaustedException::REASON_WALL;
            return false;
        }
        if ($this->attempts >= $this->maxAttempts) {
            $this->denialReason = UpstreamBudgetExhaustedException::REASON_ATTEMPTS;
            return false;
        }
        $this->denialReason = null;
        $this->attempts++;
        return true;
    }

    public function attempts(): int { return $this->attempts; }
    public function denialReason(): ?string { return $this->denialReason; }
    public function deadlineExhausted(): bool { return $this->deadlineExhausted || $this->remainingMs() <= 0; }
}

final class RequestContext
{
    private UpstreamBudget $budget;
    private string $requestId;
    private int $upstreamMs = 0;
    private array $domainsTried = [];
    private int $sourceCacheHits = 0;
    private int $sourceCacheMisses = 0;
    private int $sourceCacheDisabled = 0;
    private bool $testMode;
    private string $testScenario;
    private string $testRunId;
    private int $prefetchScopeDepth = 0;
    /** @var null|callable():int */
    private $clock;
    private static ?self $current = null;

    private function __construct(
        private string $route,
        int $budgetMs,
        int $maxAttempts,
        bool $testMode = false,
        string $testScenario = '',
        string $testRunId = '',
        ?callable $clock = null,
    ) {
        $this->budget = new UpstreamBudget($budgetMs, $maxAttempts);
        $this->requestId = bin2hex(random_bytes(8));
        $this->testMode = $testMode;
        $this->testScenario = $testScenario;
        $this->testRunId = $testRunId;
        $this->clock = $clock;
    }

    public static function fromGlobals(string $route): self
    {
        $testMode = self::testModeEnabled();
        $scenario = $testMode ? self::safeTestToken($_GET['test_scenario'] ?? '') : '';
        $runId = $testMode ? self::safeTestToken($_GET['test_run_id'] ?? '') : '';
        $context = new self(
            $route,
            self::envInt('JM_REQUEST_BUDGET_MS', JmConfig::DEFAULT_REQUEST_BUDGET_MS, 1000, 60000),
            self::envInt('JM_MAX_UPSTREAM_ATTEMPTS', JmConfig::DEFAULT_MAX_UPSTREAM_ATTEMPTS, 1, 20),
            $testMode,
            $scenario,
            $runId,
        );
        self::$current = $context;
        return $context;
    }

    public static function forTest(string $route, int $budgetMs, int $maxAttempts, ?callable $clock = null): self
    {
        return new self($route, $budgetMs, $maxAttempts, true, 'policy-test', bin2hex(random_bytes(8)), $clock);
    }

    public static function current(): ?self { return self::$current; }
    public static function testModeEnabled(): bool { return trim((string) getenv('JM_TEST_MODE')) === '1'; }
    public function budget(): UpstreamBudget { return $this->budget; }
    public function unixTime(): int { return $this->clock !== null ? (int) ($this->clock)() : time(); }
    public function isTestMode(): bool { return $this->testMode; }
    public function testScenario(): string { return $this->testScenario; }
    public function testRunId(): string { return $this->testRunId; }
    public function testCacheNamespace(): string
    {
        if (!$this->testMode) return '';
        return $this->testRunId !== ''
            ? 'test:' . hash('sha256', $this->testRunId) . ':'
            : 'test:unscoped:';
    }

    /** @return list<string> */
    public function testHeaders(): array
    {
        if (!$this->testMode) return [];
        $headers = [];
        if ($this->testScenario !== '') $headers[] = 'X-JM-Test-Scenario: ' . $this->testScenario;
        if ($this->testRunId !== '') $headers[] = 'X-JM-Test-Run-Id: ' . $this->testRunId;
        if ($this->prefetchScopeDepth > 0) $headers[] = 'X-JM-Test-Prefetch: 1';
        return $headers;
    }

    public function withPrefetchScope(callable $operation): mixed
    {
        $this->prefetchScopeDepth++;
        try {
            return $operation($this);
        } finally {
            $this->prefetchScopeDepth--;
        }
    }

    public function recordAttempt(HttpResult $result, string $baseUrl): void
    {
        $this->upstreamMs += max(0, (int) ($result->timings['total_ms'] ?? 0));
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'unknown'));
        $this->domainsTried[$host] = true;
    }

    public function recordSourceCache(string $status): void
    {
        match ($status) {
            'hit' => $this->sourceCacheHits++,
            'miss' => $this->sourceCacheMisses++,
            'disabled' => $this->sourceCacheDisabled++,
            default => null,
        };
    }

    public function sourceCacheStatus(): ?string
    {
        if ($this->sourceCacheHits > 0 && $this->sourceCacheMisses > 0) return 'mixed';
        if ($this->sourceCacheMisses > 0) return 'miss';
        if ($this->sourceCacheHits > 0) return 'hit';
        if ($this->sourceCacheDisabled > 0) return 'disabled';
        return null;
    }

    public function diagnostics(): array
    {
        return [
            'request_id' => $this->requestId(),
            'route' => $this->route,
            'upstream_attempts' => $this->budget->attempts(),
            'upstream_ms' => $this->upstreamMs,
            'domains_tried_count' => count($this->domainsTried),
            'deadline_exhausted' => $this->budget->deadlineExhausted(),
            'source_cache_hits' => $this->sourceCacheHits,
            'source_cache_misses' => $this->sourceCacheMisses,
            'source_cache_status' => $this->sourceCacheStatus(),
        ];
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function emitResponseHeaders(): void
    {
        if (headers_sent()) return;
        $diag = $this->diagnostics();
        header('X-JM-Request-Id: ' . $diag['request_id']);
        header('X-JM-Upstream-Attempts: ' . $diag['upstream_attempts']);
        header('X-JM-Upstream-Ms: ' . $diag['upstream_ms']);
        header('X-JM-Deadline-Exhausted: ' . ($diag['deadline_exhausted'] ? '1' : '0'));
        if ($diag['source_cache_status'] !== null) {
            header('X-JM-Source-Cache: ' . $diag['source_cache_status']);
        }
    }

    private static function safeTestToken(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $value) === 1 ? $value : '';
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || preg_match('/^\d+$/', trim((string) $raw)) !== 1) return $default;
        return min($max, max($min, (int) $raw));
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }
}

final class JmHttpClient implements UpstreamTransport
{
    private \CurlHandle $ch;

    public function __construct()
    {
        $ch = curl_init();
        if ($ch === false) throw new \RuntimeException('curl_init failed');
        $this->ch = $ch;

        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
    }

    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult
    {
        $responseHeaders = [];
        $bodyCollector = new ResponseBodyCollector(max(0, (int) ($bodyLimitBytes ?? 0)));
        $timeoutMs = max(1, $timeoutMs);
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        $protocol = $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS;
        curl_setopt_array($this->ch, [
            CURLOPT_URL        => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPGET    => true,
            CURLOPT_NOBODY     => false,
            CURLOPT_HEADER     => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS  => $protocol,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, JmConfig::CONNECT_TIMEOUT * 1000),
            CURLOPT_HEADERFUNCTION => static function (
                \CurlHandle $handle,
                string $line,
            ) use (&$responseHeaders, $bodyCollector): int {
                $length = strlen($line);
                if (str_starts_with(strtoupper($line), 'HTTP/')) {
                    $responseHeaders = [];
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $bodyCollector->consumeHeaderLine($line);
            },
            CURLOPT_WRITEFUNCTION => static function (
                \CurlHandle $handle,
                string $chunk,
            ) use ($bodyCollector): int {
                return $bodyCollector->consumeChunk($chunk);
            },
        ]);

        $executed = curl_exec($this->ch);
        $err  = curl_errno($this->ch);
        $statusCode = (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $error = $err !== 0 ? curl_error($this->ch) : '';
        $timings = [
            'dns_ms' => (int) round((float) curl_getinfo($this->ch, CURLINFO_NAMELOOKUP_TIME) * 1000),
            'connect_ms' => (int) round((float) curl_getinfo($this->ch, CURLINFO_CONNECT_TIME) * 1000),
            'tls_ms' => defined('CURLINFO_APPCONNECT_TIME') ? (int) round((float) curl_getinfo($this->ch, CURLINFO_APPCONNECT_TIME) * 1000) : 0,
            'ttfb_ms' => (int) round((float) curl_getinfo($this->ch, CURLINFO_STARTTRANSFER_TIME) * 1000),
            'total_ms' => (int) round((float) curl_getinfo($this->ch, CURLINFO_TOTAL_TIME) * 1000),
        ];
        $body = $bodyCollector->body();
        $limitExceeded = $bodyCollector->limitExceeded();
        $ok = !$limitExceeded && $err === 0 && $executed !== false && !($body === '' && $statusCode === 0);
        return new HttpResult(
            $ok,
            $body,
            $statusCode,
            $responseHeaders,
            $err,
            $error,
            $timings,
            $limitExceeded,
            $bodyCollector->receivedBytes(),
        );
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Redis wrapper
// ═════════════════════════════════════════════════════════════════════════════

interface RedisAdapter
{
    public function connect(
        string $host,
        int $port,
        float $connectTimeoutSeconds,
        float $readTimeoutSeconds,
    ): bool;
    public function setPrefix(string $prefix): void;
    public function eval(string $script, array $arguments, int $numberOfKeys): mixed;
    public function setEx(string $key, int $seconds, string $value): bool;
    public function exists(string $key): int;
    public function increment(string $key): int;
    public function expire(string $key, int $seconds): bool;
    public function zRemRangeByScore(string $key, string $minimum, string $maximum): int;
    public function zAdd(string $key, float $score, string $member): int|false;
    public function zCard(string $key): int;
}

interface RedisAdapterFactory
{
    public function create(): RedisAdapter;
}

final class PhpRedisAdapter implements RedisAdapter
{
    private object $client;

    public function __construct()
    {
        if (!class_exists('Redis')) throw new \RuntimeException('Redis extension unavailable');
        $this->client = new \Redis();
    }

    public function connect(
        string $host,
        int $port,
        float $connectTimeoutSeconds,
        float $readTimeoutSeconds,
    ): bool {
        return (bool) $this->client->connect(
            $host,
            $port,
            $connectTimeoutSeconds,
            null,
            0,
            $readTimeoutSeconds,
        );
    }
    public function setPrefix(string $prefix): void { $this->client->setOption(\Redis::OPT_PREFIX, $prefix); }
    public function eval(string $script, array $arguments, int $numberOfKeys): mixed { return $this->client->eval($script, $arguments, $numberOfKeys); }
    public function setEx(string $key, int $seconds, string $value): bool { return (bool) $this->client->setex($key, $seconds, $value); }
    public function exists(string $key): int { return (int) $this->client->exists($key); }
    public function increment(string $key): int { return (int) $this->client->incr($key); }
    public function expire(string $key, int $seconds): bool { return (bool) $this->client->expire($key, $seconds); }
    public function zRemRangeByScore(string $key, string $minimum, string $maximum): int { return (int) $this->client->zRemRangeByScore($key, $minimum, $maximum); }
    public function zAdd(string $key, float $score, string $member): int|false { return $this->client->zAdd($key, $score, $member); }
    public function zCard(string $key): int { return (int) $this->client->zCard($key); }
}

final class PhpRedisAdapterFactory implements RedisAdapterFactory
{
    public function create(): RedisAdapter { return new PhpRedisAdapter(); }
}

final class RedisFailureCircuit
{
    /** @var array<string,int> */
    private static array $fallbackOpenUntilMs = [];
    private MemoryCache $cache;
    private \Closure $clockMs;

    public function __construct(?MemoryCache $cache = null, ?callable $clockMs = null)
    {
        $this->cache = $cache ?? new MemoryCache();
        $this->clockMs = $clockMs === null
            ? static fn(): int => (int) floor(microtime(true) * 1000)
            : \Closure::fromCallable($clockMs);
    }

    public function isOpen(string $identity): bool
    {
        $nowMs = (int) ($this->clockMs)();
        $openUntil = self::$fallbackOpenUntilMs[$identity] ?? 0;
        if ($openUntil > $nowMs) return true;
        if ($openUntil > 0) unset(self::$fallbackOpenUntilMs[$identity]);
        $cacheKey = $this->cacheKey($identity);
        $cachedOpenUntil = $this->cache->get($cacheKey);
        if (is_int($cachedOpenUntil) && $cachedOpenUntil > $nowMs) return true;
        if ($cachedOpenUntil !== null) $this->cache->delete($cacheKey);
        return false;
    }

    public function trip(string $identity, int $ttlSeconds): void
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $openUntilMs = (int) ($this->clockMs)() + ($ttlSeconds * 1000);
        self::$fallbackOpenUntilMs[$identity] = $openUntilMs;
        $this->cache->set($this->cacheKey($identity), $openUntilMs, $ttlSeconds);
    }

    private function cacheKey(string $identity): string
    {
        return 'redis-breaker:v1:' . hash('sha256', $identity);
    }
}

final class RedisStore
{
    private const RATE_LIMIT_LUA = <<<'LUA'
local key = KEYS[1]
local nowMs = tonumber(ARGV[1])
local windowMs = tonumber(ARGV[2])
local windowSeconds = tonumber(ARGV[3])
local maxRequests = tonumber(ARGV[4])
local member = ARGV[5]
local newest = redis.call('ZREVRANGE', key, 0, 0, 'WITHSCORES')
local effectiveNow = nowMs
if newest[2] then
    effectiveNow = math.max(effectiveNow, tonumber(newest[2]))
end
local cutoffMs = effectiveNow - windowMs
redis.call('ZREMRANGEBYSCORE', key, '-inf', cutoffMs)
local count = redis.call('ZCARD', key)
if count >= maxRequests then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local retryAfter = windowSeconds
    if oldest[2] then
        retryAfter = math.max(1, math.ceil((tonumber(oldest[2]) + windowMs - effectiveNow) / 1000))
    end
    redis.call('EXPIRE', key, windowSeconds + 10)
    return {0, 0, retryAfter}
end
redis.call('ZADD', key, effectiveNow, member)
redis.call('EXPIRE', key, windowSeconds + 10)
return {1, maxRequests - count - 1, 0}
LUA;

    private ?RedisAdapter $redis = null;
    private RedisAdapterFactory $factory;
    private RedisFailureCircuit $failureCircuit;
    private string $host;
    private int $port;
    private float $timeoutSeconds;
    private string $identity;

    public function __construct(
        private string $prefix = 'jm:',
        ?RedisAdapterFactory $factory = null,
        ?RedisFailureCircuit $failureCircuit = null,
    ) {
        $this->factory = $factory ?? new PhpRedisAdapterFactory();
        $this->failureCircuit = $failureCircuit ?? new RedisFailureCircuit();
        $this->host = self::envString('REDIS_HOST', '127.0.0.1');
        $this->port = self::envInt('REDIS_PORT', 6379, 1, 65535);
        $this->timeoutSeconds = self::envInt('REDIS_TIMEOUT_MS', 500, 1, 30000) / 1000;
        $this->identity = strtolower($this->host) . ':' . $this->port;
    }

    public function isAvailable(): bool { return $this->client() !== null; }

    /** @return array{0:bool,1:int,2:int} */
    public function checkRate(string $key, int $window, int $max): array
    {
        $window = max(1, $window);
        $max = max(1, $max);
        $redis = $this->client();
        if ($redis === null) return [true, $max, 0];

        $nowMs = (int) floor(microtime(true) * 1000);
        try {
            $raw = $redis->eval(self::RATE_LIMIT_LUA, [
                'rate:' . $key,
                $nowMs,
                $window * 1000,
                $window,
                $max,
                self::redisRateMember(),
            ], 1);
            return self::parseRateResult($raw, $max, $window);
        } catch (\Throwable) {
            $this->tripFailure();
            return [true, $max, 0];
        }
    }

    public function ban(string $key, int $seconds): void
    {
        $this->command(static fn(RedisAdapter $redis): bool => $redis->setEx('ban:' . $key, $seconds, '1'), null);
    }

    public function isBanned(string $key): bool
    {
        return (bool) $this->command(static fn(RedisAdapter $redis): bool => $redis->exists('ban:' . $key) > 0, false);
    }

    public function incr(string $key, int $ttl): int
    {
        return (int) $this->command(static function (RedisAdapter $redis) use ($key, $ttl): int {
            $count = $redis->increment($key);
            if ($count === 1) $redis->expire($key, $ttl);
            return $count;
        }, 1);
    }

    public function addToExpiringSetAndCount(string $key, string $member, int $ttl): int
    {
        return (int) $this->command(static function (RedisAdapter $redis) use ($key, $member, $ttl): int {
            $now = time();
            $redis->zRemRangeByScore($key, '-inf', (string) ($now - $ttl));
            $redis->zAdd($key, (float) $now, $member);
            $redis->expire($key, $ttl + 10);
            return $redis->zCard($key);
        }, 1);
    }

    private function client(): ?RedisAdapter
    {
        if ($this->redis !== null) return $this->redis;
        if ($this->failureCircuit->isOpen($this->identity)) return null;
        try {
            $redis = $this->factory->create();
            if (!$redis->connect(
                $this->host,
                $this->port,
                $this->timeoutSeconds,
                $this->timeoutSeconds,
            )) {
                $this->tripFailure();
                return null;
            }
            $redis->setPrefix($this->prefix);
            $this->redis = $redis;
            return $this->redis;
        } catch (\Throwable) {
            $this->tripFailure();
            return null;
        }
    }

    private function command(callable $operation, mixed $fallback): mixed
    {
        $redis = $this->client();
        if ($redis === null) return $fallback;
        try {
            return $operation($redis);
        } catch (\Throwable) {
            $this->tripFailure();
            return $fallback;
        }
    }

    private function tripFailure(): void
    {
        $this->redis = null;
        $this->failureCircuit->trip(
            $this->identity,
            self::envInt('REDIS_BREAKER_TTL_SECONDS', 5, 1, 60),
        );
    }

    /** @return array{0:bool,1:int,2:int} */
    private static function parseRateResult(mixed $value, int $max, int $window): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3) {
            throw new \UnexpectedValueException('Malformed Redis rate result');
        }
        $allowed = self::redisNonNegativeInt($value[0]);
        $remaining = self::redisNonNegativeInt($value[1]);
        $retryAfter = self::redisNonNegativeInt($value[2]);
        if (!in_array($allowed, [0, 1], true)) {
            throw new \UnexpectedValueException('Malformed Redis rate result');
        }
        if ($allowed === 1) {
            if ($remaining > $max - 1 || $retryAfter !== 0) {
                throw new \UnexpectedValueException('Malformed Redis rate result');
            }
            return [true, $remaining, 0];
        }
        if ($remaining !== 0 || $retryAfter < 1 || $retryAfter > $window) {
            throw new \UnexpectedValueException('Malformed Redis rate result');
        }
        return [false, 0, $retryAfter];
    }

    private static function redisNonNegativeInt(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) throw new \UnexpectedValueException('Malformed Redis rate result');
            return $value;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/D', $value) !== 1) {
            throw new \UnexpectedValueException('Malformed Redis rate result');
        }
        $limit = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)
        ) {
            throw new \UnexpectedValueException('Malformed Redis rate result');
        }
        return (int) $value;
    }

    private static function envString(string $name, string $default): string
    {
        $raw = getenv($name);
        return $raw === false || trim((string) $raw) === '' ? $default : trim((string) $raw);
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '' || preg_match('/^\d+$/', trim((string) $raw)) !== 1) return $default;
        return min($max, max($min, (int) $raw));
    }

    private static function redisRateMember(): string
    {
        return sprintf('%.6F:%s', microtime(true), bin2hex(random_bytes(8)));
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// API Client — auth, domain rotation, retry, decryption
// ═════════════════════════════════════════════════════════════════════════════

interface PrefetchLeaseStore
{
    public function isAvailable(): bool;
    public function tryAcquire(string $key, int $token, int $ttl): bool;
    public function owns(string $key, int $expectedToken): bool;
    public function compareAndRefresh(string $key, int $expectedToken, int $ttl): bool;
    public function release(string $key, int $expectedToken): bool;
}

final class MemoryCache implements PrefetchLeaseStore
{
    private const PREFIX = 'jmapi:';
    private const PREFETCH_AUTHORITY_LOCK_DIRECTORY = 'jmapi-prefetch-mutation-lock-v1';
    private const PREFETCH_AUTHORITY_LOCK_SHARDS = 256;
    private const FRAGMENTATION_CACHE_KEY = 'diagnostics:apcu-fragmentation:v1';
    private const FRAGMENTATION_LEASE_KEY = 'diagnostics:apcu-fragmentation-lease:v1';
    private const FRAGMENTATION_CACHE_TTL = 5;
    private const FRAGMENTATION_LEASE_TTL = 1;
    private bool $enabled;
    /** @var array<int,array{handle:resource,keys:array<string,int>}> */
    private array $prefetchAuthorityShards = [];
    /** @var array<string,int> */
    private array $prefetchAuthorityByKey = [];

    public function __construct(private string $prefix = self::PREFIX)
    {
        $this->enabled = function_exists('apcu_enabled') && apcu_enabled();
    }

    public function __destruct()
    {
        $this->releaseAllPrefetchAuthorities();
    }

    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    public function get(string $key): mixed
    {
        if (!$this->enabled) return null;

        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);
        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        if (!$this->enabled || $ttl <= 0) return false;
        return apcu_store($this->prefix . $key, $value, $ttl);
    }

    public function tryAdd(string $key, mixed $value, int $ttl): bool
    {
        if (!$this->enabled || $ttl <= 0) return false;
        return apcu_add($this->prefix . $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        if (!$this->enabled) return false;
        return apcu_delete($this->prefix . $key);
    }

    public function compareAndDelete(string $key, int $expectedToken): bool
    {
        if (!$this->enabled || !function_exists('apcu_cas')) return false;

        $fullKey = $this->prefix . $key;
        $tombstone = -random_int(1, PHP_INT_MAX);
        if (!apcu_cas($fullKey, $expectedToken, $tombstone)) return false;

        return apcu_delete($fullKey);
    }

    public function tryAcquire(string $key, int $token, int $ttl): bool
    {
        if (!$this->enabled || $token <= 0 || $ttl <= 0 || isset($this->prefetchAuthorityByKey[$key])) return false;

        $shard = $this->prefetchAuthorityShard($key);
        $openedShard = !isset($this->prefetchAuthorityShards[$shard]);
        if ($this->acquirePrefetchAuthority($key) === null) return false;

        $fullKey = $this->prefix . $key;
        $mirrorWritten = false;
        $owned = false;
        try {
            $mirrorWritten = apcu_add($fullKey, $token, $ttl);
            if (!$mirrorWritten) {
                // Holding the authority flock proves any surviving APCu value is a stale mirror.
                $mirrorWritten = apcu_store($fullKey, $token, $ttl);
            }
            if (!$mirrorWritten) return false;

            $this->prefetchAuthorityShards[$shard]['keys'][$key] = $token;
            $this->prefetchAuthorityByKey[$key] = $shard;
            $owned = true;
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            if (!$owned) {
                if ($mirrorWritten) {
                    try {
                        $success = false;
                        $value = apcu_fetch($fullKey, $success);
                        if ($success && $value === $token) apcu_delete($fullKey);
                    } catch (\Throwable) {
                        // A stale mirror is bounded by TTL; the authority handle must still close.
                    }
                }
                if ($openedShard) $this->releasePrefetchAuthorityShard($shard);
            }
        }
    }

    public function owns(string $key, int $expectedToken): bool
    {
        if ($expectedToken <= 0) return false;
        $shard = $this->prefetchAuthorityByKey[$key] ?? null;
        return is_int($shard)
            && ($this->prefetchAuthorityShards[$shard]['keys'][$key] ?? null) === $expectedToken
            && is_resource($this->prefetchAuthorityShards[$shard]['handle'] ?? null);
    }

    public function compareAndRefresh(string $key, int $expectedToken, int $ttl): bool
    {
        if ($expectedToken <= 0 || $ttl <= 0 || !$this->owns($key, $expectedToken)) return false;
        $fullKey = $this->prefix . $key;
        try {
            $success = false;
            $value = apcu_fetch($fullKey, $success);
            if ($success) {
                if ($value !== $expectedToken) return false;
                return apcu_store($fullKey, $expectedToken, $ttl);
            }

            if (apcu_add($fullKey, $expectedToken, $ttl)) return true;
            $value = apcu_fetch($fullKey, $success);
            if (!$success || $value !== $expectedToken) return false;
            return apcu_store($fullKey, $expectedToken, $ttl);
        } catch (\Throwable) {
            return false;
        }
    }

    public function release(string $key, int $expectedToken): bool
    {
        if ($expectedToken <= 0 || !$this->owns($key, $expectedToken)) return false;
        try {
            $fullKey = $this->prefix . $key;
            $success = false;
            $value = apcu_fetch($fullKey, $success);
            if ($success && $value === $expectedToken) apcu_delete($fullKey);
        } catch (\Throwable) {
            // The APCu token is only a mirror; releasing the authority handle is mandatory.
        } finally {
            $this->releasePrefetchAuthority($key, $expectedToken);
        }
        return true;
    }

    private function acquirePrefetchAuthority(string $key): ?int
    {
        $shard = $this->prefetchAuthorityShard($key);
        if (isset($this->prefetchAuthorityShards[$shard])) {
            return is_resource($this->prefetchAuthorityShards[$shard]['handle'] ?? null) ? $shard : null;
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::PREFETCH_AUTHORITY_LOCK_DIRECTORY;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
        $path = $directory . DIRECTORY_SEPARATOR . sprintf('lock-%03d.lck', $shard);
        $handle = @fopen($path, 'c+b');
        if ($handle === false) return null;

        $locked = false;
        try {
            $locked = @flock($handle, LOCK_EX | LOCK_NB);
            if (!$locked) return null;
            $stat = fstat($handle);
            if (!is_array($stat) || (int) ($stat['size'] ?? -1) !== 0) return null;

            $this->prefetchAuthorityShards[$shard] = ['handle' => $handle, 'keys' => []];
            return $shard;
        } finally {
            if (!isset($this->prefetchAuthorityShards[$shard])) {
                if ($locked) @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    private function releasePrefetchAuthority(string $key, int $expectedToken): bool
    {
        $shard = $this->prefetchAuthorityByKey[$key] ?? null;
        if (!is_int($shard)
            || ($this->prefetchAuthorityShards[$shard]['keys'][$key] ?? null) !== $expectedToken
        ) return false;

        unset($this->prefetchAuthorityByKey[$key], $this->prefetchAuthorityShards[$shard]['keys'][$key]);
        if ($this->prefetchAuthorityShards[$shard]['keys'] === []) {
            $this->releasePrefetchAuthorityShard($shard);
        }
        return true;
    }

    private function releasePrefetchAuthorityShard(int $shard): void
    {
        $handle = $this->prefetchAuthorityShards[$shard]['handle'] ?? null;
        unset($this->prefetchAuthorityShards[$shard]);
        if (!is_resource($handle)) return;
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function releaseAllPrefetchAuthorities(): void
    {
        $shards = $this->prefetchAuthorityShards;
        $this->prefetchAuthorityShards = [];
        $this->prefetchAuthorityByKey = [];
        foreach ($shards as $state) {
            $handle = $state['handle'] ?? null;
            if (!is_resource($handle)) continue;
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function prefetchAuthorityShard(string $key): int
    {
        $digest = hash('sha256', $this->prefix . $key, true);
        return ord($digest[0]) % self::PREFETCH_AUTHORITY_LOCK_SHARDS;
    }

    public function increment(string $key, int $ttl, int $step = 1): ?int
    {
        if (!$this->enabled || $ttl <= 0 || $step <= 0 || !function_exists('apcu_inc')) return null;

        $fullKey = $this->prefix . $key;
        $success = false;
        $value = apcu_inc($fullKey, $step, $success, $ttl);
        if ($success) return (int) $value;
        if (apcu_add($fullKey, $step, $ttl)) return $step;

        $value = apcu_inc($fullKey, $step, $success, $ttl);
        return $success ? (int) $value : null;
    }

    public function memoryState(): array
    {
        $state = [
            'total_memory_bytes' => null,
            'free_memory_bytes'  => null,
            'used_memory_bytes'  => null,
            'free_ratio'         => null,
        ];

        if (!$this->enabled || !function_exists('apcu_sma_info')) {
            return $state;
        }

        $sma = @apcu_sma_info(true);
        if (!is_array($sma)) {
            return $state;
        }

        $total = null;
        if (isset($sma['num_seg'], $sma['seg_size'])) {
            $total = (int) $sma['num_seg'] * (int) $sma['seg_size'];
        }
        $free = isset($sma['avail_mem']) ? (int) $sma['avail_mem'] : null;

        $state['total_memory_bytes'] = $total;
        $state['free_memory_bytes'] = $free;
        $state['used_memory_bytes'] = ($total !== null && $free !== null) ? max(0, $total - $free) : null;
        $state['free_ratio'] = ($total !== null && $total > 0 && $free !== null)
            ? (int) floor(($free * 100) / $total)
            : null;
        return $state;
    }

    private function fragmentationState(): array
    {
        $empty = [
            'largest_free_block_bytes' => null,
            'fragmentation_ratio' => null,
            'fragmentation_sampled_at' => null,
            'fragmentation_cached' => null,
        ];
        if (!$this->enabled || !function_exists('apcu_sma_info')) return $empty;

        $readCached = function (): ?array {
            $success = false;
            $cached = apcu_fetch($this->prefix . self::FRAGMENTATION_CACHE_KEY, $success);
            if (!$success || !is_array($cached)) return null;
            foreach ([
                'total_memory_bytes',
                'free_memory_bytes',
                'used_memory_bytes',
                'free_ratio',
                'largest_free_block_bytes',
                'fragmentation_ratio',
                'fragmentation_sampled_at',
            ] as $field) {
                if (!array_key_exists($field, $cached) || !is_int($cached[$field])) return null;
            }
            if ($cached['total_memory_bytes'] < 0 || $cached['free_memory_bytes'] < 0 ||
                $cached['used_memory_bytes'] < 0 || $cached['free_ratio'] < 0 || $cached['free_ratio'] > 100 ||
                $cached['largest_free_block_bytes'] < 0 ||
                $cached['largest_free_block_bytes'] > $cached['free_memory_bytes'] ||
                $cached['fragmentation_ratio'] < 0 || $cached['fragmentation_ratio'] > 100 ||
                $cached['fragmentation_sampled_at'] <= 0
            ) return null;
            if ($cached['free_memory_bytes'] > $cached['total_memory_bytes'] ||
                $cached['used_memory_bytes'] !== max(0, $cached['total_memory_bytes'] - $cached['free_memory_bytes']) ||
                $cached['free_ratio'] !== ($cached['total_memory_bytes'] > 0
                    ? (int) floor(($cached['free_memory_bytes'] * 100) / $cached['total_memory_bytes'])
                    : 0) ||
                $cached['fragmentation_ratio'] !== ($cached['free_memory_bytes'] > 0
                    ? (int) floor((max(0, $cached['free_memory_bytes'] - $cached['largest_free_block_bytes']) * 100) / $cached['free_memory_bytes'])
                    : 0)
            ) return null;
            return $cached;
        };

        $cached = $readCached();
        if ($cached !== null) return $cached + ['fragmentation_cached' => true];

        $token = random_int(1, PHP_INT_MAX);
        if (!apcu_add($this->prefix . self::FRAGMENTATION_LEASE_KEY, $token, self::FRAGMENTATION_LEASE_TTL)) {
            $cached = $readCached();
            return $cached !== null ? $cached + ['fragmentation_cached' => true] : $empty;
        }

        try {
            $sma = @apcu_sma_info(false);
            if (!is_array($sma)) return $empty;

            $total = isset($sma['num_seg'], $sma['seg_size'])
                ? max(0, (int) $sma['num_seg'] * (int) $sma['seg_size'])
                : 0;
            $free = isset($sma['avail_mem']) ? max(0, (int) $sma['avail_mem']) : 0;
            $largestFreeBlock = 0;
            if (isset($sma['block_lists']) && is_array($sma['block_lists'])) {
                foreach ($sma['block_lists'] as $blocks) {
                    if (!is_array($blocks)) continue;
                    foreach ($blocks as $block) {
                        if (!is_array($block) || !isset($block['size']) || !is_numeric($block['size'])) continue;
                        $largestFreeBlock = max($largestFreeBlock, max(0, (int) $block['size']));
                    }
                }
            }
            $largestFreeBlock = min($free, $largestFreeBlock);
            $payload = [
                'total_memory_bytes' => $total,
                'free_memory_bytes' => $free,
                'used_memory_bytes' => max(0, $total - $free),
                'free_ratio' => $total > 0 ? (int) floor(($free * 100) / $total) : 0,
                'largest_free_block_bytes' => $largestFreeBlock,
                'fragmentation_ratio' => $free === 0
                    ? 0
                    : (int) floor((max(0, $free - $largestFreeBlock) * 100) / $free),
                'fragmentation_sampled_at' => time(),
            ];
            apcu_store($this->prefix . self::FRAGMENTATION_CACHE_KEY, $payload, self::FRAGMENTATION_CACHE_TTL);
            return $payload + ['fragmentation_cached' => false];
        } finally {
            $this->compareAndDelete(self::FRAGMENTATION_LEASE_KEY, $token);
        }
    }

    public function diagnostics(): array
    {
        $details = [
            'enabled'            => $this->enabled,
            'total_memory_bytes' => null,
            'free_memory_bytes'  => null,
            'used_memory_bytes'  => null,
            'free_ratio'         => null,
            'largest_free_block_bytes' => null,
            'fragmentation_ratio' => null,
            'fragmentation_sampled_at' => null,
            'fragmentation_cached' => null,
            'entries'            => null,
            'hits'               => null,
            'misses'             => null,
            'inserts'            => null,
            'expunges'           => null,
            'cleanups'           => null,
            'defragmentations'   => null,
        ];

        if (!$this->enabled) return $details;

        $details = array_merge($details, $this->memoryState(), $this->fragmentationState());

        if (function_exists('apcu_cache_info')) {
            $info = @apcu_cache_info(true);
            if (is_array($info)) {
                $details['entries'] = isset($info['num_entries']) ? (int) $info['num_entries'] : null;
                $details['hits'] = isset($info['num_hits']) ? (int) $info['num_hits'] : null;
                $details['misses'] = isset($info['num_misses']) ? (int) $info['num_misses'] : null;
                $details['inserts'] = isset($info['num_inserts']) ? (int) $info['num_inserts'] : null;
                $details['expunges'] = isset($info['expunges']) ? (int) $info['expunges'] : null;
                $details['cleanups'] = isset($info['cleanups']) ? (int) $info['cleanups'] : null;
                $details['defragmentations'] = isset($info['defragmentations']) ? (int) $info['defragmentations'] : null;
            }
        }

        return $details;
    }
}

final class PrefetchCoordinator
{
    private const LEASE_SAFETY_MARGIN_MS = 5000;
    public const CANDIDATE_SCHEMA = 'decoded-page:v1';
    public const SKIP_REASONS = [
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
        'executor-error',
    ];
    private \Closure $clockNs;
    private \Closure $registrar;
    private \Closure $executor;
    private \Closure $statsSink;
    private \Closure $waterlineOk;
    private \Closure $tokenFactory;
    private \Closure $ownershipSink;

    public function __construct(
        private PrefetchLeaseStore $cache,
        callable $clockNs,
        callable $registrar,
        callable $executor,
        callable $statsSink,
        callable $waterlineOk,
        ?callable $tokenFactory = null,
        ?callable $ownershipSink = null,
    ) {
        $this->clockNs = \Closure::fromCallable($clockNs);
        $this->registrar = \Closure::fromCallable($registrar);
        $this->executor = \Closure::fromCallable($executor);
        $this->statsSink = \Closure::fromCallable($statsSink);
        $this->waterlineOk = \Closure::fromCallable($waterlineOk);
        $this->tokenFactory = \Closure::fromCallable($tokenFactory ?? static fn(): int => random_int(1, PHP_INT_MAX));
        $this->ownershipSink = \Closure::fromCallable($ownershipSink ?? static function (array $event): void {});
    }

    /** @return array{scheduled:bool,attempted:int,cache_hits:int,stored:int,bytes:int,wall_ms:int,skip_reason:?string} */
    public static function emptyStats(): array
    {
        return [
            'scheduled' => false,
            'attempted' => 0,
            'cache_hits' => 0,
            'stored' => 0,
            'bytes' => 0,
            'wall_ms' => 0,
            'skip_reason' => null,
        ];
    }

    public static function leaseTtlSeconds(int $scheduleDelayMs, int $wallBudgetMs): int
    {
        $ttl = (int) ceil((max(0, $scheduleDelayMs) + max(0, $wallBudgetMs) + self::LEASE_SAFETY_MARGIN_MS) / 1000);
        return min(300, max(5, $ttl));
    }

    /** @param array{schema:string,photo_id:string,page:int} $candidate */
    public static function candidateLeaseKey(array $candidate): string
    {
        $identity = [
            'schema' => (string) ($candidate['schema'] ?? ''),
            'photo_id' => (string) ($candidate['photo_id'] ?? ''),
            'page' => (int) ($candidate['page'] ?? 0),
        ];
        return 'prefetch-page-lease:v1:' . hash('sha256', json_encode(
            $identity,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param callable(string,int):bool $covered
     * @return list<array{schema:string,photo_id:string,page:int,priority:int,covered:bool}>
     */
    public static function planCandidates(
        string $photoId,
        int $currentPage,
        int $currentPageCount,
        int $pages,
        int $highPriorityPages,
        ?string $nextChapterId,
        bool $nextChapterEligible,
        int $nextChapterPages,
        callable $covered,
    ): array {
        if ($pages <= 0) return [];

        $candidates = [];
        $lastPage = min($currentPageCount, $currentPage + $pages);
        for ($page = $currentPage + 1; $page <= $lastPage; $page++) {
            $candidates[] = [
                'schema' => self::CANDIDATE_SCHEMA,
                'photo_id' => $photoId,
                'page' => $page,
                'priority' => ($page - $currentPage) <= max(0, $highPriorityPages) ? 0 : 1,
                'covered' => (bool) $covered($photoId, $page),
            ];
        }

        if ($nextChapterEligible
            && $nextChapterPages > 0
            && $nextChapterId !== null
            && $nextChapterId !== $photoId
            && preg_match('/^\d{1,20}$/', $nextChapterId) === 1
        ) {
            for ($page = 1; $page <= $nextChapterPages; $page++) {
                $candidates[] = [
                    'schema' => self::CANDIDATE_SCHEMA,
                    'photo_id' => $nextChapterId,
                    'page' => $page,
                    'priority' => 1,
                    'covered' => (bool) $covered($nextChapterId, $page),
                ];
            }
        }
        return $candidates;
    }

    public static function isNearEnd(int $page, int $pageCount, int $remainingThreshold, int $progressThreshold): bool
    {
        if ($pageCount <= 0 || $page < 1 || $page > $pageCount) return false;
        $remaining = $pageCount - $page;
        $progress = (int) floor(($page * 100) / $pageCount);
        return $remaining <= max(0, $remainingThreshold)
            || $progress >= min(100, max(1, $progressThreshold));
    }

    /**
     * @param callable():list<array{schema:string,photo_id:string,page:int,priority:int,covered:bool}> $candidateFactory
     * @return array{scheduled:bool,attempted:int,cache_hits:int,stored:int,bytes:int,wall_ms:int,skip_reason:?string}
     */
    public function schedule(
        bool $enabled,
        int $pages,
        int $maxActive,
        int $wallBudgetMs,
        int $byteBudget,
        int $scheduleDelayMs,
        callable $candidateFactory,
    ): array {
        $reason = match (true) {
            !$enabled => 'disabled',
            $pages <= 0 => 'skipped-pages-zero',
            $maxActive <= 0 => 'skipped-max-active-zero',
            $wallBudgetMs <= 0 => 'skipped-wall-zero',
            $byteBudget <= 0 => 'skipped-byte-zero',
            !$this->cache->isAvailable() => 'skipped-no-apcu',
            !($this->waterlineOk)() => 'skipped-low-memory',
            default => null,
        };
        if ($reason !== null) {
            $stats = self::emptyStats();
            $stats['skip_reason'] = $reason;
            $this->emitStats($stats);
            return $stats;
        }

        $leaseTtl = self::leaseTtlSeconds($scheduleDelayMs, $wallBudgetMs);
        $claims = [];
        foreach ($candidateFactory() as $candidate) {
            if (!is_array($candidate) || ($candidate['covered'] ?? false) === true) continue;
            if (!self::validCandidate($candidate)) continue;

            $key = self::candidateLeaseKey($candidate);
            $token = $this->nextToken();
            if (!$this->cache->tryAcquire($key, $token, $leaseTtl)) continue;
            $claims[] = [
                'key' => $key,
                'token' => $token,
                'candidate' => $candidate,
                'priority' => max(0, (int) ($candidate['priority'] ?? 1)),
                'order' => count($claims),
            ];
            $this->emitOwnership('page-owner-acquire', $candidate);
        }
        if ($claims === []) {
            $stats = self::emptyStats();
            $stats['skip_reason'] = 'skipped-pages-covered';
            $this->emitStats($stats);
            return $stats;
        }

        $slot = null;
        for ($index = 0; $index < $maxActive; $index++) {
            $key = 'prefetch-slot:' . $index;
            $token = $this->nextToken();
            if ($this->cache->tryAcquire($key, $token, $leaseTtl)) {
                $slot = ['key' => $key, 'token' => $token, 'index' => $index];
                $this->emitOwnership('slot-acquire', ['slot' => $index]);
                break;
            }
        }
        if ($slot === null) {
            $this->releaseClaims($claims);
            $stats = self::emptyStats();
            $stats['skip_reason'] = 'skipped-busy';
            $this->emitStats($stats);
            return $stats;
        }

        usort($claims, static fn(array $left, array $right): int => [$left['priority'], $left['order']] <=> [$right['priority'], $right['order']]);

        $callback = function () use ($claims, $slot, $wallBudgetMs, $byteBudget): void {
            $stats = self::emptyStats();
            $stats['scheduled'] = true;
            $startedNs = ($this->clockNs)();
            try {
                $this->emitOwnership('callback-start', []);
                $callbackLeaseTtl = self::leaseTtlSeconds(0, $wallBudgetMs);
                if (!$this->cache->compareAndRefresh($slot['key'], $slot['token'], $callbackLeaseTtl)) {
                    $stats['skip_reason'] = 'slot-lost';
                    return;
                }
                $this->emitOwnership('slot-renew', ['slot' => $slot['index']]);
                foreach ($claims as $claim) {
                    if (!$this->cache->compareAndRefresh($claim['key'], $claim['token'], $callbackLeaseTtl)) {
                        $stats['skip_reason'] = 'page-lease-lost';
                        return;
                    }
                    $this->emitOwnership('page-owner-renew', $claim['candidate']);
                }

                foreach ($claims as $claim) {
                    $elapsedMs = self::elapsedMs($startedNs, ($this->clockNs)());
                    if ($elapsedMs >= $wallBudgetMs) {
                        $stats['skip_reason'] = 'budget-wall';
                        break;
                    }
                    if ($stats['bytes'] >= $byteBudget) {
                        $stats['skip_reason'] = 'budget-bytes';
                        break;
                    }
                    if (!$this->cache->isAvailable()) {
                        $stats['skip_reason'] = 'skipped-no-apcu';
                        break;
                    }
                    if (!($this->waterlineOk)()) {
                        $stats['skip_reason'] = 'skipped-low-memory';
                        break;
                    }
                    $remainingWallMs = max(0, $wallBudgetMs - $elapsedMs);
                    $executorLeaseTtl = self::leaseTtlSeconds(0, $remainingWallMs);
                    if (!$this->cache->compareAndRefresh($slot['key'], $slot['token'], $executorLeaseTtl)) {
                        $stats['skip_reason'] = 'slot-lost';
                        break;
                    }
                    $this->emitOwnership('slot-renew', ['slot' => $slot['index']]);
                    if (!$this->cache->compareAndRefresh($claim['key'], $claim['token'], $executorLeaseTtl)) {
                        $stats['skip_reason'] = 'page-lease-lost';
                        break;
                    }
                    $this->emitOwnership('page-owner-renew', $claim['candidate']);

                    $remainingBytes = max(0, $byteBudget - $stats['bytes']);
                    $stats['attempted']++;
                    try {
                        $result = ($this->executor)(
                            $claim['candidate'],
                            max(0, $wallBudgetMs - $elapsedMs),
                            $remainingBytes,
                        );
                    } catch (UpstreamBudgetExhaustedException $error) {
                        $stats['skip_reason'] = $error->prefetchSkipReason();
                        break;
                    } catch (\Throwable) {
                        $stats['skip_reason'] = 'executor-error';
                        break;
                    }
                    if (($result['cache_hit'] ?? false) === true) $stats['cache_hits']++;
                    if (($result['cache_store'] ?? null) === 'stored') $stats['stored']++;
                    $stats['bytes'] += max(0, (int) ($result['upstream_bytes'] ?? 0));
                }
            } finally {
                $stats['wall_ms'] = self::elapsedMs($startedNs, ($this->clockNs)());
                $this->releaseClaims($claims);
                $this->releaseSlot($slot);
                $this->emitStats($stats);
            }
        };
        try {
            ($this->registrar)($callback);
        } catch (\Throwable) {
            $this->releaseClaims($claims);
            $this->releaseSlot($slot);
            $stats = self::emptyStats();
            $stats['skip_reason'] = 'skipped-registration';
            $this->emitStats($stats);
            return $stats;
        }

        $stats = self::emptyStats();
        $stats['scheduled'] = true;
        return $stats;
    }

    private static function validCandidate(array $candidate): bool
    {
        return trim((string) ($candidate['schema'] ?? '')) !== ''
            && preg_match('/^\d{1,20}$/', (string) ($candidate['photo_id'] ?? '')) === 1
            && (int) ($candidate['page'] ?? 0) >= 1;
    }

    private static function elapsedMs(int $startedNs, int $nowNs): int
    {
        return max(0, (int) floor(($nowNs - $startedNs) / 1_000_000));
    }

    private function nextToken(): int
    {
        $token = (int) ($this->tokenFactory)();
        return $token > 0 ? $token : random_int(1, PHP_INT_MAX);
    }

    /** @param list<array{key:string,token:int,candidate:array}> $claims */
    private function releaseClaims(array $claims): void
    {
        foreach ($claims as $claim) {
            $released = $this->cache->release($claim['key'], $claim['token']);
            $this->emitOwnership($released ? 'page-owner-release' : 'page-owner-release-lost', $claim['candidate']);
        }
    }

    /** @param array{key:string,token:int,index:int} $slot */
    private function releaseSlot(array $slot): void
    {
        $released = $this->cache->release($slot['key'], $slot['token']);
        $this->emitOwnership($released ? 'slot-release' : 'slot-release-lost', ['slot' => $slot['index']]);
    }

    private function emitOwnership(string $event, array $details): void
    {
        try {
            ($this->ownershipSink)(['event' => $event] + $details);
        } catch (\Throwable) {
            // Test-only ownership observation and diagnostics must be non-fatal.
        }
    }

    private function emitStats(array $stats): void
    {
        try {
            ($this->statsSink)($stats);
        } catch (\Throwable) {
            // Observability must never change current-page or cleanup behavior.
        }
    }
}

final class PrefetchTestObserver
{
    public static function record(string $directory, string $runId, array $event): void
    {
        if ($directory === '' || preg_match('/^[a-f0-9-]{8,64}$/i', $runId) !== 1) return;
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create prefetch test stats directory.');
        }

        $path = rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . strtolower($runId) . '.json';
        $fp = fopen($path, 'c+');
        if ($fp === false) throw new RuntimeException('Unable to open prefetch test stats.');
        $locked = false;
        try {
            $locked = flock($fp, LOCK_EX);
            if (!$locked) throw new RuntimeException('Unable to lock prefetch test stats.');
            $raw = stream_get_contents($fp);
            if ($raw === false) throw new RuntimeException('Unable to read prefetch test stats.');
            $data = $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) $data = [];
            self::applyEvent($data, $event);

            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!ftruncate($fp, 0) || !rewind($fp)) {
                throw new RuntimeException('Unable to reset prefetch test stats.');
            }
            $written = fwrite($fp, $encoded);
            if ($written === false || $written !== strlen($encoded) || !fflush($fp)) {
                throw new RuntimeException('Unable to write prefetch test stats.');
            }
        } finally {
            if ($locked && !flock($fp, LOCK_UN)) {
                fclose($fp);
                throw new RuntimeException('Unable to unlock prefetch test stats.');
            }
            fclose($fp);
        }
    }

    private static function applyEvent(array &$data, array $event): void
    {
        $name = (string) ($event['event'] ?? '');
        if (in_array($name, ['page-owner-acquire', 'page-owner-renew', 'page-owner-release', 'page-owner-release-lost'], true)) {
            $photoId = (string) ($event['photo_id'] ?? '');
            $page = (int) ($event['page'] ?? 0);
            if (preg_match('/^\d{1,20}$/', $photoId) !== 1 || $page < 1) return;
            $suffix = '|' . $photoId . '|' . $page;
            self::increment($data, 'prefetch-' . $name . $suffix);
            if (in_array($name, ['page-owner-renew', 'page-owner-release-lost'], true)) return;
            $currentKey = 'prefetch-page-owner-current' . $suffix;
            $peakKey = 'prefetch-page-owner-peak' . $suffix;
            $current = (int) ($data[$currentKey] ?? 0);
            $current = $name === 'page-owner-acquire' ? $current + 1 : max(0, $current - 1);
            $data[$currentKey] = $current;
            $data[$peakKey] = max((int) ($data[$peakKey] ?? 0), $current);
            return;
        }
        if (in_array($name, ['slot-acquire', 'slot-renew', 'slot-release', 'slot-release-lost'], true)) {
            self::increment($data, 'prefetch-' . $name);
            if (in_array($name, ['slot-renew', 'slot-release-lost'], true)) return;
            $current = (int) ($data['prefetch-slot-current'] ?? 0);
            $current = $name === 'slot-acquire' ? $current + 1 : max(0, $current - 1);
            $data['prefetch-slot-current'] = $current;
            $data['prefetch-slot-peak'] = max((int) ($data['prefetch-slot-peak'] ?? 0), $current);
            return;
        }
        if ($name === 'callback-start') self::increment($data, 'prefetch-callback-start');
    }

    private static function increment(array &$data, string $key): void
    {
        $data[$key] = (int) ($data[$key] ?? 0) + 1;
    }
}

final class DomainResolver
{
    private const STATE_KEY = 'domain-state:v1';
    private const LEASE_KEY = 'domain-refresh-lease:v1';
    private const FAILED_KEY = 'domain-refresh-failed:v1';

    private MemoryCache $cache;
    private ?UpstreamTransport $http;
    private string $cacheNamespace;
    private array $lastDiagnostics;

    public function __construct(
        private RequestContext $context,
        ?MemoryCache $cache = null,
        ?UpstreamTransport $http = null,
    ) {
        $this->cache = $cache ?? new MemoryCache();
        $this->http = $http;
        $this->cacheNamespace = $context->testCacheNamespace();
        $this->lastDiagnostics = $this->baseDiagnostics();
    }

    /** @return list<string> */
    public function resolveForRequest(): array
    {
        $now = $this->context->unixTime();
        $state = $this->validatedState($this->cache->get($this->stateKey()));
        $diagnostics = $this->baseDiagnostics();

        if ($state !== null) {
            $diagnostics['age'] = max(0, $now - $state['fetched_at']);
            $diagnostics['fetched_at'] = $state['fetched_at'];
            $diagnostics['fresh_until'] = $state['fresh_until'];
            $diagnostics['stale_until'] = $state['stale_until'];

            if ($now <= $state['fresh_until']) {
                $diagnostics['source'] = 'fresh';
                $this->lastDiagnostics = $diagnostics;
                return $state['domains'];
            }

            if ($this->staleTtl() > 0 && $now <= $state['stale_until']) {
                $diagnostics['source'] = 'stale';
                $diagnostics['expired'] = true;
                $this->lastDiagnostics = $diagnostics;
                return $state['domains'];
            }

            $diagnostics['expired'] = true;
        }

        $diagnostics['source'] = 'fallback';
        if (!$this->cache->isAvailable()) {
            $diagnostics['refresh_suppressed'] = true;
            $diagnostics['refresh_suppressed_reason'] = 'cache-unavailable';
        } elseif ($this->cache->get($this->failedKey()) !== null) {
            $diagnostics['refresh_suppressed'] = true;
            $diagnostics['refresh_suppressed_reason'] = 'negative-cache';
        }
        $this->lastDiagnostics = $diagnostics;
        return JmConfig::API_DOMAINS;
    }

    public function scheduleRefreshIfNeeded(): void
    {
        if (($this->lastDiagnostics['source'] ?? 'fallback') === 'fresh') return;
        if (!$this->deferredRefreshEnabled()) {
            $this->suppressRefresh('disabled');
            return;
        }
        if (!$this->cache->isAvailable()) {
            $this->suppressRefresh('cache-unavailable');
            return;
        }
        if ($this->cache->get($this->failedKey()) !== null) {
            $this->suppressRefresh('negative-cache');
            return;
        }

        $token = random_int(1, PHP_INT_MAX);
        $leaseTtl = self::envInt('JM_DOMAIN_REFRESH_LEASE_TTL', 15, 5, 60);
        if (!$this->cache->tryAdd($this->leaseKey(), $token, $leaseTtl)) {
            $this->suppressRefresh('lease-held');
            return;
        }

        $budgetMs = self::envInt('JM_DOMAIN_REFRESH_BUDGET_MS', 3000, 100, 10000);
        register_shutdown_function(function () use ($token, $budgetMs): void {
            try {
                $this->refreshWithinBudget($budgetMs);
            } catch (\Throwable $e) {
                $this->recordRefreshFailure('exception');
                error_log('[jm-api] deferred domain refresh failed request_id=' . $this->context->requestId() . ' type=' . $e::class);
            } finally {
                $this->cache->compareAndDelete($this->leaseKey(), $token);
            }
        });
    }

    public function diagnostics(): array
    {
        $diagnostics = $this->lastDiagnostics;
        if (!$this->cache->isAvailable()) {
            $diagnostics['refresh_suppressed'] = true;
            $diagnostics['refresh_suppressed_reason'] = 'cache-unavailable';
        } elseif (!$this->deferredRefreshEnabled()) {
            $diagnostics['refresh_suppressed'] = true;
            $diagnostics['refresh_suppressed_reason'] = 'disabled';
        } elseif ($this->cache->get($this->failedKey()) !== null) {
            $diagnostics['refresh_suppressed'] = true;
            $diagnostics['refresh_suppressed_reason'] = 'negative-cache';
        } elseif ($this->cache->get($this->leaseKey()) !== null) {
            $diagnostics['refresh_suppressed'] = true;
            $diagnostics['refresh_suppressed_reason'] = 'lease-held';
        }
        return $diagnostics;
    }

    private function refreshWithinBudget(int $budgetMs): bool
    {
        if (!$this->cache->isAvailable()) {
            $this->suppressRefresh('cache-unavailable');
            return false;
        }
        if ($this->cache->get($this->failedKey()) !== null) {
            $this->suppressRefresh('negative-cache');
            return false;
        }

        $budgetMs = max(100, min(10000, $budgetMs));
        $sourceTimeoutMs = self::envInt('JM_DOMAIN_SOURCE_TIMEOUT_MS', 1500, 1000, 2000);
        $startedNs = hrtime(true);
        $sources = $this->sourceUrls();
        if ($sources === []) {
            $this->recordRefreshFailure('no-sources');
            return false;
        }
        $transport = $this->http ??= new JmHttpClient();

        foreach ($sources as $sourceIndex => $url) {
            $elapsedMs = (int) floor((hrtime(true) - $startedNs) / 1_000_000);
            $remainingMs = $budgetMs - $elapsedMs;
            if ($remainingMs <= 0) break;

            $headers = array_merge([
                'Accept: text/plain,application/octet-stream;q=0.9,*/*;q=0.1',
                'User-Agent: ' . JmConfig::UA,
            ], $this->context->testHeaders());
            $result = $transport->get($url, $headers, min($sourceTimeoutMs, $remainingMs));
            if (!$result->ok || $result->status < 200 || $result->status >= 300) {
                error_log(sprintf(
                    '[jm-api] domain source failed request_id=%s source_index=%d status=%d errno=%d',
                    $this->context->requestId(),
                    $sourceIndex,
                    $result->status,
                    $result->curlErrno,
                ));
                continue;
            }

            $domains = $this->decodeDomainConfig($result->body);
            if ($domains === []) continue;

            $now = $this->context->unixTime();
            $freshTtl = $this->freshTtl();
            $staleTtl = $this->staleTtl();
            $state = [
                'domains' => $domains,
                'fetched_at' => $now,
                'fresh_until' => $now + $freshTtl,
                'stale_until' => $now + $freshTtl + $staleTtl,
            ];
            if (!$this->cache->set($this->stateKey(), $state, $freshTtl + $staleTtl)) {
                $this->recordRefreshFailure('cache-store');
                return false;
            }

            $this->cache->delete($this->failedKey());
            $this->lastDiagnostics = array_merge($this->baseDiagnostics(), [
                'source' => 'fresh',
                'age' => 0,
                'fetched_at' => $state['fetched_at'],
                'fresh_until' => $state['fresh_until'],
                'stale_until' => $state['stale_until'],
            ]);
            return true;
        }

        $this->recordRefreshFailure('all-sources-failed');
        return false;
    }

    private function recordRefreshFailure(string $reason): void
    {
        if ($this->cache->isAvailable()) {
            $this->cache->set($this->failedKey(), [
                'failed_at' => $this->context->unixTime(),
                'reason' => $reason,
            ], self::envInt('JM_DOMAIN_REFRESH_FAILURE_TTL', 60, 30, 300));
        }
        $this->suppressRefresh('negative-cache');
    }

    private function suppressRefresh(string $reason): void
    {
        $this->lastDiagnostics['refresh_suppressed'] = true;
        $this->lastDiagnostics['refresh_suppressed_reason'] = $reason;
    }

    private function validatedState(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $domains = JmApiClient::normalizeApiDomains($value['domains'] ?? null);
        $fetchedAt = self::safeTimestamp($value['fetched_at'] ?? null);
        $freshUntil = self::safeTimestamp($value['fresh_until'] ?? null);
        $staleUntil = self::safeTimestamp($value['stale_until'] ?? null);
        if ($domains === [] || $fetchedAt === null || $freshUntil === null || $staleUntil === null) return null;
        if ($freshUntil < $fetchedAt || $staleUntil < $freshUntil) return null;
        return [
            'domains' => $domains,
            'fetched_at' => $fetchedAt,
            'fresh_until' => $freshUntil,
            'stale_until' => $staleUntil,
        ];
    }

    /** @return list<string> */
    private function decodeDomainConfig(string $body): array
    {
        while ($body !== '' && ord($body[0]) > 127) $body = substr($body, 1);
        $cipher = base64_decode(trim($body), true);
        if ($cipher === false) return [];

        $plain = openssl_decrypt(
            $cipher,
            'AES-256-ECB',
            md5(JmConfig::DOMAIN_SECRET),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        if ($plain === false || $plain === '') return [];

        $plain = self::pkcs7Unpad($plain);
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) return [];
        return JmApiClient::normalizeApiDomains($decoded['Server'] ?? null);
    }

    /** @return list<string> */
    private function sourceUrls(): array
    {
        if ($this->context->isTestMode()) {
            return $this->normalizeSourceUrls(self::environmentList('JM_TEST_DOMAIN_SOURCE_URLS'), true);
        }
        return $this->normalizeSourceUrls(JmConfig::DOMAIN_SERVER_URLS, false);
    }

    /** @param list<mixed> $urls @return list<string> */
    private function normalizeSourceUrls(array $urls, bool $testMode): array
    {
        $allowedHosts = $testMode ? self::testAllowedHosts() : [];
        $normalized = [];
        foreach ($urls as $candidate) {
            if (!is_scalar($candidate)) continue;
            $candidate = trim((string) $candidate);
            $parts = $candidate !== '' ? parse_url($candidate) : false;
            if (!is_array($parts)) continue;
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) continue;
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!self::validHost($host)) continue;
            if ($testMode) {
                if (!in_array($scheme, ['http', 'https'], true) || !isset($allowedHosts[$host])) continue;
            } elseif ($scheme !== 'https') {
                continue;
            }
            $port = isset($parts['port']) ? (int) $parts['port'] : null;
            if ($port !== null && ($port < 1 || $port > 65535)) continue;
            $path = (string) ($parts['path'] ?? '/');
            if ($path === '' || !str_starts_with($path, '/')) $path = '/';
            $url = $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path;
            $normalized[$url] = $url;
        }
        return array_values($normalized);
    }

    private function baseDiagnostics(): array
    {
        return [
            'source' => 'fallback',
            'age' => null,
            'fetched_at' => null,
            'fresh_until' => null,
            'stale_until' => null,
            'fresh_ttl' => $this->freshTtl(),
            'stale_ttl' => $this->staleTtl(),
            'expired' => false,
            'refresh_deferred' => $this->deferredRefreshEnabled(),
            'refresh_suppressed' => false,
            'refresh_suppressed_reason' => null,
        ];
    }

    private function freshTtl(): int
    {
        return self::envInt('JM_DOMAIN_FRESH_TTL', 86400, 60, 604800);
    }

    private function staleTtl(): int
    {
        return self::envInt('JM_DOMAIN_STALE_TTL', 86400, 0, 604800);
    }

    private function deferredRefreshEnabled(): bool
    {
        return self::envBool('JM_DOMAIN_REFRESH_DEFERRED', true);
    }

    private function stateKey(): string { return $this->cacheNamespace . self::STATE_KEY; }
    private function leaseKey(): string { return $this->cacheNamespace . self::LEASE_KEY; }
    private function failedKey(): string { return $this->cacheNamespace . self::FAILED_KEY; }

    private static function safeTimestamp(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) return (int) $value;
        return null;
    }

    /** @return list<string> */
    private static function environmentList(string $name): array
    {
        $raw = trim((string) getenv($name));
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $value): bool => $value !== ''));
    }

    /** @return array<string,true> */
    private static function testAllowedHosts(): array
    {
        $allowed = [];
        foreach (self::environmentList('JM_TEST_ALLOWED_HOSTS') as $host) {
            $host = strtolower($host);
            if (self::validHost($host)) $allowed[$host] = true;
        }
        return $allowed;
    }

    private static function validHost(string $host): bool
    {
        if ($host === '') return false;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return true;
        return preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) === 1;
    }

    private static function pkcs7Unpad(string $data): string
    {
        $length = strlen($data);
        if ($length === 0) return '';
        $padding = ord($data[$length - 1]);
        if ($padding < 1 || $padding > 16 || $padding > $length) return $data;
        for ($index = $length - $padding; $index < $length; $index++) {
            if (ord($data[$index]) !== $padding) return $data;
        }
        return substr($data, 0, $length - $padding);
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || preg_match('/^\d+$/', trim((string) $raw)) !== 1) return $default;
        return min($max, max($min, (int) $raw));
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }
}

final class ApiFailure
{
    public const KIND_NETWORK = 'network';
    public const KIND_HTTP_RETRYABLE = 'http_retryable';
    public const KIND_HTTP_CLIENT = 'http_client';
    public const KIND_BUSINESS = 'business';
    public const KIND_ENVELOPE_JSON = 'envelope_json';
    public const KIND_ENVELOPE_SHAPE = 'envelope_shape';
    public const KIND_DECRYPT = 'decrypt';
    public const KIND_PAYLOAD_JSON = 'payload_json';
    public const KIND_PAYLOAD_SHAPE = 'payload_shape';
    public const KIND_SCRAMBLE_TEMPLATE = 'scramble_template';

    private function __construct(
        private string $kind,
        private string $message,
        private bool $hardDomainFailure,
        private int $httpStatus = 0,
    ) {}

    public static function network(string $message): self
    {
        return new self(self::KIND_NETWORK, $message !== '' ? $message : 'network error', true);
    }

    public static function http(int $statusCode): ?self
    {
        if ($statusCode >= 500 || $statusCode === 0 || $statusCode === 408 || $statusCode === 429) {
            return new self(self::KIND_HTTP_RETRYABLE, "HTTP {$statusCode}", $statusCode !== 429, $statusCode);
        }
        if ($statusCode >= 400) {
            return new self(self::KIND_HTTP_CLIENT, "HTTP {$statusCode}", false, $statusCode);
        }
        return null;
    }

    public static function business(mixed $code, string $message = ''): self
    {
        $codeText = is_scalar($code) ? (string) $code : 'unknown';
        $detail = $message !== '' ? $message : "JM business code {$codeText}";
        return new self(self::KIND_BUSINESS, $detail, false);
    }

    public static function envelopeJson(string $message): self
    {
        return new self(self::KIND_ENVELOPE_JSON, $message, false);
    }

    public static function envelopeShape(string $message): self
    {
        return new self(self::KIND_ENVELOPE_SHAPE, $message, false);
    }

    public static function decrypt(string $message): self
    {
        return new self(self::KIND_DECRYPT, $message, false);
    }

    public static function payloadJson(string $message): self
    {
        return new self(self::KIND_PAYLOAD_JSON, $message, false);
    }

    public static function payloadShape(string $message): self
    {
        return new self(self::KIND_PAYLOAD_SHAPE, $message, false);
    }

    public static function scrambleTemplate(string $message): self
    {
        return new self(self::KIND_SCRAMBLE_TEMPLATE, $message, false);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function hardDomainFailure(): bool
    {
        return $this->hardDomainFailure;
    }

    public function shouldRetry(): bool
    {
        return in_array($this->kind, [self::KIND_NETWORK, self::KIND_HTTP_RETRYABLE], true);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public static function publicException(?self $failure): JmException
    {
        if ($failure === null) {
            return new JmException('API 域名全部不可用', 502);
        }
        return new JmException('API 请求失败: ' . $failure->kind() . ' - ' . $failure->message(), 502);
    }
}

final readonly class JsonObjectContainer
{
    /** @param array<int|string,mixed> $members */
    public function __construct(public array $members) {}
}

final class PayloadNormalizer
{
    public static function scalarString(mixed $value, string $default = ''): string
    {
        if (is_scalar($value)) return (string) $value;
        return $default;
    }

    public static function identifierString(mixed $value, string $default = ''): string
    {
        if (is_string($value) || is_int($value)) return (string) $value;
        return $default;
    }

    public static function scalarInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) return $value;
        if (is_float($value)) return (int) $value;
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) return (int) trim($value);
        return $default;
    }

    public static function listArray(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values($value);
    }

    public static function assocArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            $value = is_scalar($value) ? [$value] : [];
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) continue;
            $text = trim((string) $item);
            if ($text === '' || in_array($text, $result, true)) continue;
            $result[] = $text;
        }
        return $result;
    }
}

final class DomainHealth
{
    private const KEY_PREFIX = 'domain-health:';

    private MemoryCache $cache;
    private array $domainsInOriginalOrder;
    private string $cacheNamespace;

    public function __construct(array $domainsInOriginalOrder, string $cacheNamespace = '')
    {
        $this->cache = new MemoryCache();
        $this->domainsInOriginalOrder = array_values($domainsInOriginalOrder);
        $this->cacheNamespace = $cacheNamespace;
    }

    public function orderedDomains(array $domains): array
    {
        $domainsInOriginalOrder = array_values($domains);
        if (!$this->cache->isAvailable() || empty($domainsInOriginalOrder)) {
            return $domainsInOriginalOrder;
        }

        $now = time();
        $ranked = [];
        $available = 0;
        foreach ($domainsInOriginalOrder as $index => $domain) {
            $stats = $this->stats($domain);
            $cooldownUntil = (int) ($stats['cooldown_until'] ?? 0);
            $isAvailable = $cooldownUntil <= $now;
            if ($isAvailable) $available++;
            $ranked[] = [
                'domain' => $domain,
                'index' => $index,
                'available' => $isAvailable,
                'failure_streak' => (int) ($stats['failure_streak'] ?? 0),
                'ewma_latency_ms' => ((int) ($stats['ewma_latency_ms'] ?? 0)) > 0
                    ? (int) $stats['ewma_latency_ms']
                    : PHP_INT_MAX,
            ];
        }

        if ($available === 0) {
            return $domainsInOriginalOrder;
        }

        usort($ranked, function (array $left, array $right): int {
            if ($left['available'] !== $right['available']) {
                return $left['available'] ? -1 : 1;
            }
            $failureCompare = $left['failure_streak'] <=> $right['failure_streak'];
            if ($failureCompare !== 0) return $failureCompare;
            $latencyCompare = $left['ewma_latency_ms'] <=> $right['ewma_latency_ms'];
            if ($latencyCompare !== 0) return $latencyCompare;
            return $left['index'] <=> $right['index'];
        });

        return array_map(fn(array $item) => $item['domain'], $ranked);
    }

    public function markSuccess(string $domain, int $latencyMs): void
    {
        if (!$this->cache->isAvailable()) return;

        $stats = $this->stats($domain);
        $oldLatency = (int) ($stats['ewma_latency_ms'] ?? 0);
        $stats['success_count'] = (int) ($stats['success_count'] ?? 0) + 1;
        $stats['failure_streak'] = 0;
        $stats['cooldown_until'] = 0;
        $stats['ewma_latency_ms'] = $oldLatency > 0
            ? (int) round(($oldLatency * 0.7) + ($latencyMs * 0.3))
            : max(1, $latencyMs);
        $this->saveStats($domain, $stats);
    }

    public function markFailure(string $domain, bool $hardFailure = true, string $kind = 'unknown', string $message = ''): void
    {
        if (!$this->cache->isAvailable()) return;

        $stats = $this->stats($domain);
        $stats['failure_count'] = (int) ($stats['failure_count'] ?? 0) + 1;
        $stats['last_failure_at'] = time();
        $stats['last_failure_kind'] = $kind;
        $stats['last_failure_message'] = self::safeDiagnosticMessage($message);
        if ($hardFailure) {
            $streak = (int) ($stats['failure_streak'] ?? 0) + 1;
            $cooldown = min(
                600,
                self::envInt('JM_DOMAIN_COOLDOWN_SECONDS', JmConfig::DEFAULT_DOMAIN_COOLDOWN_SECONDS, 0, 3600) * $streak
            );
            $stats['failure_streak'] = $streak;
            $stats['cooldown_until'] = time() + $cooldown;
        }
        $this->saveStats($domain, $stats);
    }

    public static function diagnostics(array $domains): array
    {
        $health = new self($domains);
        return [
            'stats_available' => $health->cache->isAvailable(),
            'order' => $health->orderedDomains($domains),
            'cooldown_seconds' => self::envInt('JM_DOMAIN_COOLDOWN_SECONDS', JmConfig::DEFAULT_DOMAIN_COOLDOWN_SECONDS, 0, 3600),
            'stats_ttl_seconds' => self::envInt('JM_DOMAIN_STATS_TTL', JmConfig::DEFAULT_DOMAIN_STATS_TTL, 0, 86400),
            'stats' => array_map(fn(string $domain): array => $health->stats($domain), array_values($domains)),
        ];
    }

    private function stats(string $domain): array
    {
        $cached = $this->cache->get($this->statsKey($domain));
        if (!is_array($cached)) {
            return [
                'domain' => $domain,
                'success_count' => 0,
                'failure_count' => 0,
                'failure_streak' => 0,
                'last_failure_at' => 0,
                'last_failure_kind' => null,
                'last_failure_message' => null,
                'cooldown_until' => 0,
                'ewma_latency_ms' => 0,
            ];
        }
        return $cached;
    }

    private function saveStats(string $domain, array $stats): void
    {
        $stats['domain'] = $domain;
        $this->cache->set(
            $this->statsKey($domain),
            $stats,
            self::envInt('JM_DOMAIN_STATS_TTL', JmConfig::DEFAULT_DOMAIN_STATS_TTL, 0, 86400)
        );
    }

    private function statsKey(string $domain): string
    {
        return $this->cacheNamespace . self::KEY_PREFIX . md5($domain);
    }

    private static function safeDiagnosticMessage(string $message): string
    {
        $message = trim(str_replace(["\r", "\n"], ' ', $message));
        if (strlen($message) > 240) {
            return substr($message, 0, 240);
        }
        return $message;
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        if (!preg_match('/^\d+$/', trim((string) $raw))) return $default;
        return min($max, max($min, (int) $raw));
    }
}

final class JmApiClient
{
    private UpstreamTransport $http;
    private int $requestCount = 0;
    private array $baseUrls;
    private DomainHealth $domainHealth;
    private RequestContext $context;

    public function __construct(RequestContext $context, ?UpstreamTransport $http = null, ?array $baseUrls = null)
    {
        $this->context = $context;
        $this->http = $http ?? new JmHttpClient();
        $this->baseUrls = $baseUrls === null
            ? $this->initialBaseUrls()
            : self::normalizeBaseUrls($baseUrls, $context->isTestMode());
        if ($this->baseUrls === []) {
            throw new \InvalidArgumentException('No allowed upstream base URLs');
        }
        $this->domainHealth = new DomainHealth($this->baseUrls, $context->testCacheNamespace());
    }

    public static function forTest(RequestContext $context, UpstreamTransport $http, array $baseUrls): self
    {
        $normalized = self::normalizeBaseUrls($baseUrls, true);
        if ($normalized === []) {
            throw new \InvalidArgumentException('No allowed test base URLs');
        }
        return new self($context, $http, $normalized);
    }

    public function requestCount(): int { return $this->requestCount; }

    /** @return array{ts:string, data:mixed} */
    public function callJson(string $path, array $params): array
    {
        $urlPath    = $path . '?' . http_build_query($params);
        $lastFailure = null;
        $budgetDenied = false;
        foreach ($this->domainHealth->orderedDomains($this->baseUrls) as $baseUrl) {
            for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
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

                if (!$result->ok) {
                    $category = CurlFailure::category($result->curlErrno);
                    $lastFailure = ApiFailure::network($category . ': ' . ($result->curlError !== '' ? $result->curlError : 'network error'));
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    if ($retry < JmConfig::MAX_RETRIES) {
                        $this->sleepBeforeTransientRetry();
                        continue;
                    }
                    break;
                }

                $httpFailure = ApiFailure::http($result->status);
                if ($httpFailure !== null) {
                    $lastFailure = $httpFailure;
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    if (!$lastFailure->shouldRetry()) {
                        throw ApiFailure::publicException($lastFailure);
                    }
                    if ($retry < JmConfig::MAX_RETRIES) {
                        $delayMs = $result->status === 429
                            ? RetryAfter::delayMs($result->headers['retry-after'] ?? null, $this->context->unixTime(), $this->context->budget()->remainingMs())
                            : 0;
                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        } else {
                            $this->sleepBeforeTransientRetry();
                        }
                        continue;
                    }
                    break;
                }

                try {
                    $json = self::decodeJsonObject($result->body, 'api envelope');
                } catch (\UnexpectedValueException $e) {
                    $lastFailure = ApiFailure::envelopeShape($e->getMessage());
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                } catch (\Throwable $e) {
                    $lastFailure = ApiFailure::envelopeJson($e->getMessage());
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                }

                $code = PayloadNormalizer::scalarInt($json['code'] ?? -1, -1);
                if ($code !== 200) {
                    $lastFailure = ApiFailure::business($code, PayloadNormalizer::scalarString($json['message'] ?? $json['errorMsg'] ?? ''));
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                }

                $enc = $json['data'] ?? '';
                if (!is_string($enc) || $enc === '') {
                    $lastFailure = ApiFailure::envelopeShape('api envelope data is empty or not a string');
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                }

                try {
                    $decrypted = self::decrypt($enc, $ts);
                } catch (\Throwable $e) {
                    $lastFailure = ApiFailure::decrypt($e->getMessage());
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                }

                try {
                    $resData = self::decodeJsonPayload($decrypted, 'api payload');
                } catch (\UnexpectedValueException $e) {
                    $lastFailure = ApiFailure::payloadShape($e->getMessage());
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                } catch (\Throwable $e) {
                    $lastFailure = ApiFailure::payloadJson($e->getMessage());
                    $this->recordApiFailure($lastFailure, $baseUrl, $path, $retry, $result->status);
                    throw ApiFailure::publicException($lastFailure);
                }

                $this->domainHealth->markSuccess($baseUrl, $latencyMs);
                return ['ts' => $ts, 'data' => $resData];
            }
        }

        if ($budgetDenied && $lastFailure === null) throw $this->budgetExhaustedException();
        throw ApiFailure::publicException($lastFailure);
    }

    public function fetchScrambleId(string $photoId): string
    {
        $query = http_build_query([
            'id'            => $photoId,
            'mode'          => 'vertical',
            'page'          => '0',
            'app_img_shunt' => '1',
        ]);

        $urlPath = JmConfig::ENDPOINT_SCRAMBLE . '?' . $query;
        $lastFailure = null;
        foreach ($this->domainHealth->orderedDomains($this->baseUrls) as $baseUrl) {
            for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
                $remainingMs = $this->beginUpstreamAttempt();
                if ($remainingMs === null) break 2;

                $ts = (string) $this->context->unixTime();
                $headers = array_merge([
                    'Accept-Encoding: gzip, deflate',
                    'User-Agent: ' . JmConfig::UA,
                    'token: ' . md5($ts . JmConfig::TOKEN_SECRET2),
                    'tokenparam: ' . $ts . ',' . JmConfig::VERSION,
                ], $this->context->testHeaders());
                $url = rtrim($baseUrl, '/') . $urlPath;
                $result = $this->http->get($url, $headers, $remainingMs);
                $latencyMs = max(0, (int) ($result->timings['total_ms'] ?? 0));
                $this->requestCount++;
                $this->context->recordAttempt($result, $baseUrl);

                if (!$result->ok) {
                    $category = CurlFailure::category($result->curlErrno);
                    $lastFailure = ApiFailure::network($category . ': ' . ($result->curlError !== '' ? $result->curlError : 'network error'));
                    $this->recordApiFailure($lastFailure, $baseUrl, JmConfig::ENDPOINT_SCRAMBLE, $retry, $result->status);
                    if ($retry < JmConfig::MAX_RETRIES) {
                        $this->sleepBeforeTransientRetry();
                        continue;
                    }
                    break;
                }

                $httpFailure = ApiFailure::http($result->status);
                if ($httpFailure !== null) {
                    $lastFailure = $httpFailure;
                    $this->recordApiFailure($lastFailure, $baseUrl, JmConfig::ENDPOINT_SCRAMBLE, $retry, $result->status);
                    if (!$lastFailure->shouldRetry()) {
                        $this->recordScrambleFallback($photoId, $lastFailure);
                        return (string) JmConfig::SCRAMBLE_220980;
                    }
                    if ($retry < JmConfig::MAX_RETRIES) {
                        $delayMs = $result->status === 429
                            ? RetryAfter::delayMs($result->headers['retry-after'] ?? null, $this->context->unixTime(), $this->context->budget()->remainingMs())
                            : 0;
                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        } else {
                            $this->sleepBeforeTransientRetry();
                        }
                        continue;
                    }
                    break;
                }

                if (preg_match('/var\s+scramble_id\s*=\s*(\d+);/', $result->body, $m)) {
                    $this->domainHealth->markSuccess($baseUrl, $latencyMs);
                    return $m[1];
                }

                $lastFailure = ApiFailure::scrambleTemplate('scramble_id missing from template');
                $this->recordApiFailure($lastFailure, $baseUrl, JmConfig::ENDPOINT_SCRAMBLE, $retry, $result->status);
                $this->recordScrambleFallback($photoId, $lastFailure);
                return (string) JmConfig::SCRAMBLE_220980;
            }
        }

        $this->recordScrambleFallback($photoId, $lastFailure);
        return (string) JmConfig::SCRAMBLE_220980;
    }

    public function downloadImage(string $url, ?int $downloadByteLimit = null): string
    {
        $candidateUrls = $this->imageRequestUrls($url);
        if ($downloadByteLimit !== null && $downloadByteLimit <= 0) {
            throw new UpstreamBudgetExhaustedException(UpstreamBudgetExhaustedException::REASON_BYTES);
        }
        $configuredBodyLimitBytes = self::imageMaxCompressedBytes();
        $prefetchByteLimitIsBinding = $downloadByteLimit !== null
            && $downloadByteLimit <= $configuredBodyLimitBytes;
        $bodyLimitBytes = $downloadByteLimit === null
            ? $configuredBodyLimitBytes
            : min($configuredBodyLimitBytes, $downloadByteLimit);
        $headers = array_merge([
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'User-Agent: ' . JmConfig::UA,
            'Referer: https://18comic.vip/',
        ], $this->context->testHeaders());
        $lastFailure = null;
        $budgetDenied = false;

        foreach ($candidateUrls as $candidateIndex => $candidateUrl) {
            $remainingMs = $this->beginUpstreamAttempt();
            if ($remainingMs === null) {
                $budgetDenied = true;
                break;
            }

            $result = $this->http->get($candidateUrl, $headers, $remainingMs, $bodyLimitBytes);
            $this->requestCount++;
            $baseUrl = self::urlOrigin($candidateUrl);
            $this->context->recordAttempt($result, $baseUrl);

            if ($result->bodyLimitExceeded) {
                $lastFailure = ApiFailure::payloadShape('Image compressed body limit exceeded');
                $this->recordApiFailure($lastFailure, $baseUrl, '/media/photos', 0, $result->status);
                if ($prefetchByteLimitIsBinding) {
                    throw new UpstreamBudgetExhaustedException(UpstreamBudgetExhaustedException::REASON_BYTES);
                }
                break;
            }

            if (!$result->ok) {
                $category = CurlFailure::category($result->curlErrno);
                $lastFailure = ApiFailure::network($category . ': image transport failed');
                $this->recordApiFailure($lastFailure, $baseUrl, '/media/photos', 0, $result->status);
                if ($candidateIndex === 0) continue;
                break;
            }

            if ($result->status >= 500 && $result->status <= 599) {
                $lastFailure = ApiFailure::http($result->status);
                if ($lastFailure === null) $lastFailure = ApiFailure::payloadShape('Image HTTP failure');
                $this->recordApiFailure($lastFailure, $baseUrl, '/media/photos', 0, $result->status);
                if ($candidateIndex === 0) continue;
                break;
            }

            if ($result->status < 200 || $result->status >= 300) {
                $lastFailure = ApiFailure::http($result->status)
                    ?? ApiFailure::payloadShape('Unexpected image HTTP status');
                $this->recordApiFailure($lastFailure, $baseUrl, '/media/photos', 0, $result->status);
                break;
            }

            if ($result->body === '') {
                $lastFailure = ApiFailure::payloadShape('empty image response');
                $this->recordApiFailure($lastFailure, $baseUrl, '/media/photos', 0, $result->status);
                break;
            }

            $this->domainHealth->markSuccess($baseUrl, max(0, (int) ($result->timings['total_ms'] ?? 0)));
            return $result->body;
        }

        if ($budgetDenied && $lastFailure === null) throw $this->budgetExhaustedException();
        throw new JmException('Image download failed', 502);
    }

    public static function testApiSource(): string
    {
        if (!RequestContext::testModeEnabled()) return 'production';

        $explicit = trim((string) getenv('JM_TEST_API_BASE_URLS'));
        if ($explicit !== '' && strtolower($explicit) !== 'disabled') {
            return 'explicit';
        }

        return trim((string) getenv('JM_TEST_FALLBACK_API_BASE_URLS')) !== ''
            ? 'fallback'
            : 'unconfigured';
    }

    private function beginUpstreamAttempt(): ?int
    {
        if (!$this->context->budget()->beginAttempt()) return null;
        return max(1, $this->context->budget()->remainingMs());
    }

    private function sleepBeforeTransientRetry(): void
    {
        if ($this->context->isTestMode()) return;
        $delayMs = min(300, max(0, $this->context->budget()->remainingMs()));
        if ($delayMs > 0) usleep($delayMs * 1000);
    }

    private function budgetExhaustedException(): UpstreamBudgetExhaustedException
    {
        $reason = $this->context->budget()->denialReason()
            ?? ($this->context->budget()->deadlineExhausted()
                ? UpstreamBudgetExhaustedException::REASON_WALL
                : UpstreamBudgetExhaustedException::REASON_ATTEMPTS);
        return new UpstreamBudgetExhaustedException($reason);
    }

    /** @return list<string> */
    private function initialBaseUrls(): array
    {
        $domainResolver = new DomainResolver($this->context);
        $resolvedDomains = $domainResolver->resolveForRequest();
        $domainResolver->scheduleRefreshIfNeeded();

        if ($this->context->isTestMode()) {
            $explicit = trim((string) getenv('JM_TEST_API_BASE_URLS'));
            $environmentName = $explicit !== '' && strtolower($explicit) !== 'disabled'
                ? 'JM_TEST_API_BASE_URLS'
                : 'JM_TEST_FALLBACK_API_BASE_URLS';
            $baseUrls = self::normalizeBaseUrls(self::environmentList($environmentName), true);
            if ($baseUrls === []) {
                throw new JmException('Test upstream configuration unavailable', 500);
            }
            return $baseUrls;
        }

        $baseUrls = self::normalizeBaseUrls($resolvedDomains, false);
        if ($baseUrls === []) {
            $baseUrls = self::normalizeBaseUrls(JmConfig::API_DOMAINS, false);
        }
        if ($baseUrls === []) {
            throw new JmException('API base URL configuration unavailable', 500);
        }
        return $baseUrls;
    }

    /** @param list<mixed> $baseUrls @return list<string> */
    private static function normalizeBaseUrls(array $baseUrls, bool $testMode): array
    {
        $allowedHosts = $testMode ? self::testAllowedHosts() : [];
        $normalized = [];

        foreach ($baseUrls as $candidate) {
            if (!is_scalar($candidate)) continue;
            $candidate = trim((string) $candidate);
            if ($candidate === '') continue;

            if (!str_contains($candidate, '://')) {
                if ($testMode) continue;
                $candidate = 'https://' . $candidate;
            }

            $parts = parse_url($candidate);
            if (!is_array($parts)) continue;
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) continue;

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!self::validHost($host)) continue;
            if ($testMode) {
                if (!in_array($scheme, ['http', 'https'], true) || !isset($allowedHosts[$host])) continue;
            } elseif ($scheme !== 'https') {
                continue;
            }

            $port = isset($parts['port']) ? (int) $parts['port'] : null;
            if ($port !== null && ($port < 1 || $port > 65535)) continue;
            $path = (string) ($parts['path'] ?? '');
            if ($path !== '' && !str_starts_with($path, '/')) continue;

            $baseUrl = $scheme . '://' . $host;
            if ($port !== null) $baseUrl .= ':' . $port;
            $baseUrl .= rtrim($path, '/');
            $normalized[$baseUrl] = $baseUrl;
        }

        return array_values($normalized);
    }

    /** @return list<string> */
    private static function environmentList(string $name): array
    {
        $raw = trim((string) getenv($name));
        if ($raw === '' || strtolower($raw) === 'disabled') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $value): bool => $value !== ''));
    }

    /** @return array<string,true> */
    private static function testAllowedHosts(): array
    {
        $allowed = [];
        foreach (self::environmentList('JM_TEST_ALLOWED_HOSTS') as $host) {
            $host = strtolower($host);
            if (self::validHost($host)) $allowed[$host] = true;
        }
        return $allowed;
    }

    private static function validHost(string $host): bool
    {
        if ($host === '') return false;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
        return preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) === 1;
    }

    /** @return list<string> */
    private function imageRequestUrls(string $mediaPath): array
    {
        return CdnPolicy::candidateUrls($mediaPath, $this->context, $this->domainHealth);
    }

    private static function urlOrigin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? 'unknown'));
        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) $origin .= ':' . (int) $parts['port'];
        return $origin;
    }

    private function recordApiFailure(ApiFailure $failure, string $baseUrl, string $path, int $retry, int $statusCode = 0): void
    {
        $this->domainHealth->markFailure($baseUrl, $failure->hardDomainFailure(), $failure->kind(), $failure->kind());
        error_log(sprintf(
            '[jm-api] api failure request_id=%s kind=%s endpoint=%s status=%d retry=%d hard=%s',
            $this->context->requestId(),
            $failure->kind(),
            $path,
            $statusCode > 0 ? $statusCode : $failure->httpStatus(),
            $retry,
            $failure->hardDomainFailure() ? '1' : '0'
        ));
    }

    private function recordScrambleFallback(string $photoId, ?ApiFailure $failure): void
    {
        error_log(sprintf(
            '[jm-api] scramble fallback request_id=%s failure_kind=%s',
            $this->context->requestId(),
            $failure?->kind() ?? 'none'
        ));

        $cache = new MemoryCache();
        if (!$cache->isAvailable()) return;

        $count = $cache->get('diagnostics:scramble-fallback-count');
        $cache->set('diagnostics:scramble-fallback-count', (int) $count + 1, 21600);
        $cache->set('diagnostics:last-scramble-fallback', [
            'failure_kind' => $failure?->kind(),
            'request_id' => $this->context->requestId(),
        ], 21600);
    }

    private static function decodeJsonObject(string $text, string $stage): array
    {
        $decoded = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("{$stage} JSON decode failed: " . json_last_error_msg());
        }
        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException("{$stage} JSON payload is not an object");
        }

        $normalized = [];
        foreach (get_object_vars($decoded) as $key => $value) {
            $normalized[$key] = self::normalizeDecodedJsonValue($value);
        }
        return $normalized;
    }

    private static function decodeJsonPayload(string $text, string $stage): mixed
    {
        $decoded = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("{$stage} JSON decode failed: " . json_last_error_msg());
        }
        if (!$decoded instanceof \stdClass && !is_array($decoded)) {
            throw new \UnexpectedValueException("{$stage} JSON payload is not a container");
        }
        return self::normalizeDecodedJsonValue($decoded);
    }

    private static function normalizeDecodedJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $item) {
                $normalized[$key] = self::normalizeDecodedJsonValue($item);
            }
            return array_is_list($normalized)
                ? new JsonObjectContainer($normalized)
                : $normalized;
        }
        if (is_array($value)) {
            return array_map(self::normalizeDecodedJsonValue(...), $value);
        }
        return $value;
    }

    // ── AES-256-ECB decrypt ──

    private static function decrypt(string $b64, string $ts): string
    {
        $cipher = base64_decode($b64, true);
        if ($cipher === false) throw new \RuntimeException('Base64 failed');

        $key = md5($ts . JmConfig::DATA_SECRET); // hex → 32 bytes → AES-256
        $plain = openssl_decrypt($cipher, 'AES-256-ECB', $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if ($plain === false) throw new \RuntimeException('AES failed');

        return self::pkcs7Unpad($plain);
    }

    private static function pkcs7Unpad(string $data): string
    {
        $len = strlen($data);
        if ($len === 0) return '';
        $pad = ord($data[$len - 1]);
        if ($pad < 1 || $pad > 16 || $pad > $len) return $data;
        for ($i = $len - $pad; $i < $len; $i++) {
            if (ord($data[$i]) !== $pad) return $data;
        }
        return substr($data, 0, $len - $pad);
    }

    public static function normalizeApiDomains(mixed $domains): array
    {
        if (!is_array($domains)) return [];

        $normalized = [];
        foreach ($domains as $domain) {
            if (!is_scalar($domain)) continue;
            $domain = strtolower(trim((string) $domain));
            if ($domain === '') continue;
            if (!preg_match('/^[a-z0-9.-]+$/', $domain)) continue;
            if (isset($normalized[$domain])) continue;
            $normalized[$domain] = $domain;
        }
        return array_values($normalized);
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        if (preg_match('/^\d+$/', trim((string) $raw)) !== 1) return $default;
        return min($max, max($min, (int) $raw));
    }

    private static function imageMaxCompressedBytes(): int
    {
        $configured = self::envInt(
            'JM_IMAGE_MAX_COMPRESSED_BYTES',
            JmConfig::DEFAULT_IMAGE_MAX_COMPRESSED_BYTES,
            0,
            512 * 1024 * 1024,
        );
        return $configured > 0 ? $configured : JmConfig::DEFAULT_IMAGE_MAX_COMPRESSED_BYTES;
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Models
// ═════════════════════════════════════════════════════════════════════════════

final class ChapterImagePolicy
{
    public static function isValidFilename(string $filename): bool
    {
        return $filename !== ''
            && $filename === trim($filename)
            && preg_match('//u', $filename) === 1
            && preg_match('/\p{Cc}/u', $filename) !== 1
            && preg_match('/[\x00-\x1F\x7F]/', $filename) !== 1
            && !str_contains($filename, '/')
            && !str_contains($filename, '\\')
            && !str_contains($filename, '%')
            && !str_contains($filename, '?')
            && !str_contains($filename, '#')
            && !str_contains($filename, '..');
    }

    public static function canonicalMediaPath(string $photoId, string $filename): ?string
    {
        if (preg_match('/^\d{1,20}$/', $photoId) !== 1 || !self::isValidFilename($filename)) {
            return null;
        }
        return "/media/photos/{$photoId}/" . rawurlencode($filename);
    }
}

/** @return list<string> */
function normalizeChapterImages(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new MalformedChapterException('Malformed chapter images', 502);
    }

    $filenames = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $filename = $item;
        } elseif (is_array($item)
            && array_key_exists('image', $item)
            && is_string($item['image'])
        ) {
            $filename = (string) $item['image'];
        } else {
            throw new MalformedChapterException('Malformed chapter image item', 502);
        }
        if (preg_match('//u', $filename) !== 1 || preg_match('/\p{Cc}/u', $filename) === 1) {
            throw new MalformedChapterException('Malformed chapter image filename', 502);
        }
        $filename = trim($filename);
        if (!ChapterImagePolicy::isValidFilename($filename)) {
            throw new MalformedChapterException('Malformed chapter image filename', 502);
        }
        $filenames[] = $filename;
    }
    return $filenames;
}

final class MalformedChapterException extends JmException {}

final class CdnPolicy
{
    /** @return list<string> */
    public static function productionBaseUrls(): array
    {
        return array_map(
            static fn(string $host): string => 'https://' . $host,
            JmConfig::CDN_DOMAINS,
        );
    }

    /** @return list<string> */
    public static function candidateUrls(
        string $mediaPath,
        ?RequestContext $context = null,
        ?DomainHealth $domainHealth = null,
    ): array {
        $matches = [];
        if (preg_match('#^/media/photos/(\d{1,20})/([^/\\\\\x00-\x1F\x7F]+)$#', $mediaPath, $matches) !== 1
            || ChapterImagePolicy::canonicalMediaPath($matches[1], rawurldecode($matches[2])) !== $mediaPath
        ) {
            throw new MalformedChapterException('Malformed chapter media path', 502);
        }
        $effectiveContext = $context ?? RequestContext::current();
        $baseUrls = $effectiveContext?->isTestMode() === true
            ? self::testBaseUrls()
            : self::productionBaseUrls();

        $selected = [];
        $primary = $baseUrls[0] ?? null;
        if (is_string($primary) && $primary !== '') {
            $selected[] = $primary;
        }

        $secondaryPool = array_slice($baseUrls, 1);
        if ($secondaryPool !== []) {
            $domainHealth ??= new DomainHealth(
                $baseUrls,
                $effectiveContext?->testCacheNamespace() ?? '',
            );
            $secondary = $domainHealth->orderedDomains($secondaryPool)[0] ?? null;
            if (is_string($secondary) && $secondary !== '') {
                $selected[] = $secondary;
            }
        }

        return array_map(
            static fn(string $baseUrl): string => $baseUrl . $mediaPath,
            $selected,
        );
    }

    public static function primaryUrl(string $mediaPath): string
    {
        $candidate = self::candidateUrls($mediaPath)[0] ?? null;
        if (!is_string($candidate) || $candidate === '') {
            throw new JmException('CDN configuration unavailable', 502);
        }
        return $candidate;
    }

    /** @return list<string> */
    private static function testBaseUrls(): array
    {
        $allowed = [];
        foreach (explode(',', (string) getenv('JM_TEST_ALLOWED_HOSTS')) as $value) {
            $host = strtolower(trim($value));
            if ($host !== '') $allowed[$host] = true;
        }

        $normalized = [];
        foreach (explode(',', (string) getenv('JM_TEST_CDN_BASE_URLS')) as $value) {
            $value = rtrim(trim($value), '/');
            if ($value === '') continue;
            $parts = parse_url($value);
            if (!is_array($parts)) continue;
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)
                || !isset($allowed[$host])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || ($path !== '' && $path !== '/')
            ) {
                continue;
            }
            $baseUrl = $scheme . '://' . $host;
            if (isset($parts['port'])) $baseUrl .= ':' . (int) $parts['port'];
            $normalized[$baseUrl] = $baseUrl;
        }
        if ($normalized === []) throw new JmException('Test CDN configuration unavailable', 500);
        return array_values($normalized);
    }
}

final class JmAlbum
{
    public string $id, $name, $description, $image, $views, $likes, $comments;
    public array $author, $tags, $works, $actors, $related;
    /** @var list<array{photo_id:string, sort:string, title:string}> */
    public array $episodes;

    private function __construct() {}

    public static function fromApiResponse(array $data, string $fallbackAlbumId = ''): self
    {
        $self = new self();
        $self->id          = PayloadNormalizer::identifierString($data['id'] ?? $fallbackAlbumId);
        $self->name        = PayloadNormalizer::scalarString($data['name'] ?? '');
        $self->author      = PayloadNormalizer::stringList($data['author'] ?? []);
        $self->description = PayloadNormalizer::scalarString($data['description'] ?? '');
        $self->image       = PayloadNormalizer::scalarString($data['image'] ?? '');
        $self->views       = PayloadNormalizer::scalarString($data['total_views'] ?? '0', '0');
        $self->likes       = PayloadNormalizer::scalarString($data['likes'] ?? '0', '0');
        $self->comments    = PayloadNormalizer::scalarString($data['comment_total'] ?? '0', '0');
        $self->tags        = PayloadNormalizer::stringList($data['tags'] ?? []);
        $self->works       = PayloadNormalizer::stringList($data['works'] ?? []);
        $self->actors      = PayloadNormalizer::stringList($data['actors'] ?? []);
        $self->related     = PayloadNormalizer::listArray($data['related_list'] ?? []);

        $episodes = [];
        foreach (PayloadNormalizer::listArray($data['series'] ?? []) as $sourceIndex => $ch) {
            if (!is_array($ch)) continue;
            $photoId = PayloadNormalizer::identifierString($ch['id'] ?? '');
            if ($photoId === '') continue;
            $episodes[] = [
                'photo_id' => $photoId,
                'sort'     => PayloadNormalizer::scalarString($ch['sort'] ?? '1', '1'),
                'title'    => PayloadNormalizer::scalarString($ch['name'] ?? ''),
                'source_index' => (int) $sourceIndex,
            ];
        }

        if (empty($episodes) && $self->id !== '') {
            $episodes[] = ['photo_id' => $self->id, 'sort' => '1', 'title' => $self->name];
        } else {
            $episodes = self::normalizeEpisodes($episodes);
        }

        $self->episodes = $episodes;
        return $self;
    }

    /** @param list<array{photo_id:string, sort:string, title:string, source_index?:int}> $episodes */
    private static function normalizeEpisodes(array $episodes): array
    {
        usort($episodes, function (array $a, array $b): int {
            $sortCompare = self::episodeSortValue((string) ($a['sort'] ?? '')) <=> self::episodeSortValue((string) ($b['sort'] ?? ''));
            if ($sortCompare !== 0) return $sortCompare;
            return ((int) ($a['source_index'] ?? 0)) <=> ((int) ($b['source_index'] ?? 0));
        });

        $seenPhotoIds = [];
        $normalized = [];
        foreach ($episodes as $ep) {
            $photoId = trim((string) ($ep['photo_id'] ?? ''));
            if ($photoId === '' || isset($seenPhotoIds[$photoId])) continue;
            $seenPhotoIds[$photoId] = true;

            $normalized[] = [
                'photo_id' => $photoId,
                'sort'     => (string) (count($normalized) + 1),
                'title'    => (string) ($ep['title'] ?? ''),
            ];
        }

        return $normalized;
    }

    private static function episodeSortValue(string $sort): int
    {
        $trimmed = trim($sort);
        return preg_match('/^\d+$/', $trimmed) === 1 ? (int) $trimmed : PHP_INT_MAX;
    }

    /** @return list<string> */
    public function allPhotoIds(): array { return array_column($this->episodes, 'photo_id'); }

    /** @return array<string,string> */
    public function nextChapterMap(): array
    {
        $map = [];
        $count = count($this->episodes);
        for ($i = 0; $i < $count - 1; $i++) {
            $current = (string) ($this->episodes[$i]['photo_id'] ?? '');
            $next = (string) ($this->episodes[$i + 1]['photo_id'] ?? '');
            if ($current !== '' && $next !== '') {
                $map[$current] = $next;
            }
        }
        return $map;
    }

    public function nextPhotoId(string $photoId): ?string
    {
        $map = $this->nextChapterMap();
        return $map[$photoId] ?? null;
    }

    /** @return list<array{photo_id:string, title:string, sort:string}> */
    public function chapterHeaders(): array
    {
        return array_map(fn($ep) => [
            'photo_id' => $ep['photo_id'],
            'title'    => $ep['title'],
            'sort'     => $ep['sort'],
        ], $this->episodes);
    }

    public function toArray(): array
    {
        return [
            'album_id'    => $this->id,
            'name'        => $this->name,
            'image'       => buildCoverUrl($this->id, $this->image),
            'author'      => $this->author,
            'description' => $this->description,
            'total_views' => $this->views,
            'likes'       => $this->likes,
            'comments'    => $this->comments,
            'tags'        => $this->tags,
            'works'       => $this->works,
            'actors'      => $this->actors,
            'related'     => $this->related,
            'chapters'    => count($this->episodes),
        ];
    }
}


final class JmChapter
{
    public string $photoId, $title, $sort;
    public int $pageCount;
    /** @var list<array{index:int, filename:string, media_path:string, scramble_id:string, decode_segments:int}> */
    public array $images;

    private function __construct() {}

    public static function fromApiResponse(array $data, string $scrambleId, string $requestedPhotoId): self
    {
        $self = new self();
        if (preg_match('/^\d{1,20}$/', $scrambleId) !== 1) {
            throw new MalformedChapterException('Malformed chapter scramble id', 502);
        }
        if (preg_match('/^\d{1,20}$/', $requestedPhotoId) !== 1) {
            throw new MalformedChapterException('Malformed requested chapter photo id', 502);
        }
        $rawResponsePhotoId = $data['id'] ?? null;
        if ($rawResponsePhotoId === null || (is_string($rawResponsePhotoId) && trim($rawResponsePhotoId) === '')) {
            $responsePhotoId = $requestedPhotoId;
        } elseif (is_string($rawResponsePhotoId) || is_int($rawResponsePhotoId)) {
            $responsePhotoId = (string) $rawResponsePhotoId;
        } else {
            throw new MalformedChapterException('Malformed chapter photo id', 502);
        }
        if (preg_match('/^\d{1,20}$/', $responsePhotoId) !== 1
            || $responsePhotoId !== $requestedPhotoId
        ) {
            throw new MalformedChapterException('Malformed chapter photo id', 502);
        }
        $self->photoId   = $responsePhotoId;
        $self->title     = PayloadNormalizer::scalarString($data['name'] ?? '');

        $sort = '1';
        $series = $data['series'] ?? [];
        if ($series === null) $series = [];
        if (!is_array($series) || !array_is_list($series)) {
            throw new MalformedChapterException('Malformed chapter series', 502);
        }
        foreach ($series as $ch) {
            if (!is_array($ch) || array_is_list($ch)) {
                throw new MalformedChapterException('Malformed chapter series item', 502);
            }
            if (PayloadNormalizer::identifierString($ch['id'] ?? '') === $self->photoId) {
                $sort = PayloadNormalizer::scalarString($ch['sort'] ?? '1', '1');
                break;
            }
        }
        $self->sort = $sort;

        $images = [];
        if (!array_key_exists('images', $data)) {
            throw new MalformedChapterException('Chapter images missing', 502);
        }
        foreach (normalizeChapterImages($data['images']) as $filename) {
            $images[] = [
                'index'           => count($images) + 1,
                'filename'        => $filename,
                'media_path'      => ChapterImagePolicy::canonicalMediaPath($self->photoId, $filename),
                'scramble_id'     => $scrambleId,
                'decode_segments' => ScrambleDecoder::segments($scrambleId, $self->photoId, $filename),
            ];
        }
        $self->pageCount = count($images);
        $self->images = $images;
        return $self;
    }

    public function toArray(?string $albumId = null, ?string $publicBaseUrl = null, ?string $nextChapterId = null): array
    {
        $images = array_map(function (array $image) use ($albumId, $publicBaseUrl, $nextChapterId): array {
            $mediaPath = (string) ($image['media_path'] ?? '');
            $sourceUrl = CdnPolicy::primaryUrl($mediaPath);
            $page = (int) ($image['index'] ?? 0);
            $segments = (int) ($image['decode_segments'] ?? 0);
            $filename = (string) ($image['filename'] ?? 'page.jpg');
            $extension = self::imageExtension($filename);
            $mime = ($segments > 0 && $extension !== 'gif')
                ? ScrambleDecoder::preferredDecodedMime()
                : self::imageMime($extension);
            $url = $sourceUrl;
            if ($albumId !== null && $publicBaseUrl !== null && $page > 0) {
                $url = buildDecodedPageUrl($publicBaseUrl, $albumId, $this->photoId, $page, $nextChapterId);
            }

            return [
                'index' => $page,
                'filename' => $filename,
                'url' => $url,
                'source_url' => $sourceUrl,
                'mime' => $mime,
                'scramble_id' => (string) ($image['scramble_id'] ?? ''),
                'decode_segments' => $segments,
            ];
        }, $this->images);

        return [
            'photo_id'   => $this->photoId,
            'title'      => $this->title,
            'sort'       => $this->sort,
            'page_count' => $this->pageCount,
            'images'     => $images,
        ];
    }

    public function toCachePayload(): array
    {
        return [
            'schema' => 'chapter-v2',
            'photo_id' => $this->photoId,
            'title' => $this->title,
            'sort' => $this->sort,
            'page_count' => $this->pageCount,
            'images' => $this->images,
        ];
    }

    public static function fromCachePayload(mixed $payload, string $requestedPhotoId, string $scrambleId): ?self
    {
        if (!is_array($payload)
            || !self::hasExactKeys($payload, ['schema', 'photo_id', 'title', 'sort', 'page_count', 'images'])
            || ($payload['schema'] ?? null) !== 'chapter-v2'
            || !is_string($payload['photo_id'] ?? null)
            || $payload['photo_id'] !== $requestedPhotoId
            || preg_match('/^\d{1,20}$/', $requestedPhotoId) !== 1
            || !is_string($payload['title'] ?? null)
            || !is_string($payload['sort'] ?? null)
            || !is_int($payload['page_count'] ?? null)
            || !is_array($payload['images'] ?? null)
            || !array_is_list($payload['images'])
            || $payload['page_count'] !== count($payload['images'])
            || preg_match('/^\d{1,20}$/', $scrambleId) !== 1
        ) {
            return null;
        }

        $images = [];
        foreach ($payload['images'] as $offset => $image) {
            if (!is_array($image)
                || !self::hasExactKeys($image, ['index', 'filename', 'media_path', 'scramble_id', 'decode_segments'])
                || !is_int($image['index'] ?? null)
                || $image['index'] !== $offset + 1
                || !is_string($image['filename'] ?? null)
                || ChapterImagePolicy::canonicalMediaPath($requestedPhotoId, $image['filename']) === null
                || !is_string($image['media_path'] ?? null)
                || $image['media_path'] !== ChapterImagePolicy::canonicalMediaPath($requestedPhotoId, $image['filename'])
                || !is_string($image['scramble_id'] ?? null)
                || $image['scramble_id'] !== $scrambleId
                || !is_int($image['decode_segments'] ?? null)
                || $image['decode_segments'] !== ScrambleDecoder::segments(
                    $scrambleId,
                    $requestedPhotoId,
                    $image['filename'],
                )
            ) {
                return null;
            }
            $images[] = $image;
        }

        $self = new self();
        $self->photoId = $requestedPhotoId;
        $self->title = $payload['title'];
        $self->sort = $payload['sort'];
        $self->pageCount = $payload['page_count'];
        $self->images = $images;
        return $self;
    }

    /** @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function imageExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            default => 'jpg',
        };
    }

    private static function imageMime(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}


final class JmListItem
{
    public string $id, $name, $author, $description, $image;
    public array $tags;
    public int $likes, $totalViews;
    public ?int $updatedAt;

    private function __construct() {}

    public static function fromPayload(array $item): self
    {
        $self = new self();
        $self->id          = PayloadNormalizer::identifierString($item['id'] ?? $item['aid'] ?? $item['AID'] ?? '');
        $name              = PayloadNormalizer::scalarString($item['name'] ?? '');
        $self->name        = InputValidator::sanitizeString($name !== '' ? $name : 'JM ' . $self->id);
        $self->author      = InputValidator::sanitizeString(PayloadNormalizer::scalarString($item['author'] ?? ''));
        $self->description = InputValidator::sanitizeString(PayloadNormalizer::scalarString($item['description'] ?? ''));
        $self->image       = PayloadNormalizer::scalarString($item['image'] ?? '');
        $self->tags        = self::tagsFromPayload($item);
        $self->likes       = self::intFromPayload($item['likes'] ?? 0);
        $self->totalViews  = self::intFromPayload($item['total_views'] ?? $item['totalViews'] ?? 0);
        $self->updatedAt   = isset($item['updated_at']) || isset($item['update_at'])
            ? self::intFromPayload($item['updated_at'] ?? $item['update_at'])
            : null;

        return $self;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'author'      => $this->author,
            'description' => $this->description,
            'image'       => buildCoverUrl($this->id, $this->image),
            'tags'        => $this->tags,
            'likes'       => $this->likes,
            'total_views' => $this->totalViews,
            'updated_at'  => $this->updatedAt,
        ];
    }

    private static function tagsFromPayload(array $item): array
    {
        $tags = [];
        foreach (['category', 'category_sub'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                $title = trim(PayloadNormalizer::scalarString($item[$key]['title'] ?? ''));
                if ($title !== '' && !in_array($title, $tags, true)) {
                    $tags[] = InputValidator::sanitizeString($title);
                }
            }
        }
        foreach (['tags', 'works', 'actors'] as $key) {
            $values = PayloadNormalizer::listArray(is_array($item[$key] ?? null) ? $item[$key] : (isset($item[$key]) ? [$item[$key]] : []));
            foreach ($values as $value) {
                $value = trim(PayloadNormalizer::scalarString($value));
                if ($value !== '' && !in_array($value, $tags, true)) {
                    $tags[] = InputValidator::sanitizeString($value);
                }
            }
        }
        return $tags;
    }

    private static function intFromPayload(mixed $value): int
    {
        return PayloadNormalizer::scalarInt($value);
    }
}


final class JmListResult
{
    /** @param list<JmListItem> $items */
    public function __construct(
        public string $mode,
        public int $page,
        public int $total,
        public bool $hasNextPage,
        public array $items,
    ) {}

    public function toArray(): array
    {
        return [
            'mode'          => $this->mode,
            'page'          => $this->page,
            'total'         => $this->total,
            'has_next_page' => $this->hasNextPage,
            'items'         => array_map(fn(JmListItem $item) => $item->toArray(), $this->items),
        ];
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Scramble Decoder
// ═════════════════════════════════════════════════════════════════════════════

final class ImageProcessingException extends \RuntimeException
{
    /** @param array<string,int|string> $metrics */
    public function __construct(string $message, private array $metrics = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string,int|string> */
    public function metrics(): array { return $this->metrics; }
}

final readonly class ImageDecodeResult
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $codec,
        public int $inputBytes,
        public int $width,
        public int $height,
        public int $pixels,
        public int $decodeMs,
        public int $encodeMs,
        public int $peakMemoryBytes,
    ) {}
}

interface ImageDecoder
{
    public function decode(string $bytes, int $segments, int $maxPixels): ImageDecodeResult;
}

final readonly class ImageEncodedOutput
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $codec,
    ) {}
}

interface ImageOutputEncoder
{
    public function encode(\GdImage $image): ImageEncodedOutput;
}

final class GdImageOutputEncoder implements ImageOutputEncoder
{
    private const WEBP_QUALITY = 85;
    private const JPEG_QUALITY = 85;
    private ?\Closure $webpWriter;
    private ?\Closure $jpegWriter;
    private ImagePayloadValidator $payloadValidator;

    public function __construct(
        ?callable $webpWriter = null,
        ?callable $jpegWriter = null,
        ?ImagePayloadValidator $payloadValidator = null,
    )
    {
        $this->webpWriter = $webpWriter === null ? null : \Closure::fromCallable($webpWriter);
        $this->jpegWriter = $jpegWriter === null ? null : \Closure::fromCallable($jpegWriter);
        $this->payloadValidator = $payloadValidator ?? new GdImagePayloadValidator();
    }

    public function encode(\GdImage $image): ImageEncodedOutput
    {
        $bufferLevel = ob_get_level();
        try {
            ob_start();
            if (self::canEncodeWebp()) {
                $ok = $this->webpWriter !== null
                    ? ($this->webpWriter)($image, self::WEBP_QUALITY)
                    : imagewebp($image, null, self::WEBP_QUALITY);
                $mime = 'image/webp';
                $codec = 'webp';
            } else {
                $ok = $this->jpegWriter !== null
                    ? ($this->jpegWriter)($image, self::JPEG_QUALITY)
                    : imagejpeg($image, null, self::JPEG_QUALITY);
                $mime = 'image/jpeg';
                $codec = 'jpeg';
            }
            $output = ob_get_clean();
            if ($ok !== true || !is_string($output) || $output === '') {
                throw new ImageProcessingException('Failed to encode decoded image');
            }
            $info = @getimagesizefromstring($output);
            if (!is_array($info) || strtolower((string) ($info['mime'] ?? '')) !== $mime) {
                throw new ImageProcessingException('Encoded image format mismatch');
            }
            $verifiedWidth = (int) ($info[0] ?? 0);
            $verifiedHeight = (int) ($info[1] ?? 0);
            if (!$this->payloadValidator->isCompleteDecode($output, $verifiedWidth, $verifiedHeight)) {
                throw new ImageProcessingException('Encoded image failed full validation');
            }
            if ($verifiedWidth !== imagesx($image)
                || $verifiedHeight !== imagesy($image)
            ) {
                throw new ImageProcessingException('Encoded image dimensions changed');
            }
            return new ImageEncodedOutput($output, $mime, $codec);
        } catch (ImageProcessingException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ImageProcessingException('Image encoder failed', [], $error);
        } finally {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
        }
    }

    private static function canEncodeWebp(): bool
    {
        return function_exists('imagewebp')
            && defined('IMG_WEBP')
            && ((imagetypes() & IMG_WEBP) === IMG_WEBP);
    }
}

final class ImagePixelPolicy
{
    public static function checkedPixels(int $width, int $height, int $maxPixels): int
    {
        if ($width <= 0 || $height <= 0) {
            throw new ImageProcessingException('Image dimensions must be positive');
        }
        if ($height > intdiv(PHP_INT_MAX, $width)) {
            throw new ImageProcessingException('Image pixel count overflow');
        }
        $effectiveMaxPixels = $maxPixels > 0 ? $maxPixels : JmConfig::DEFAULT_IMAGE_MAX_PIXELS;
        if ($height > intdiv($effectiveMaxPixels, $width)) {
            throw new ImageProcessingException('Image pixel limit exceeded');
        }
        return $width * $height;
    }
}

final class ImageContainerPolicy
{
    public static function isComplete(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => self::isCompleteJpeg($bytes),
            'image/png' => self::isCompletePng($bytes),
            'image/gif' => self::isCompleteGif($bytes),
            'image/webp' => self::isCompleteWebp($bytes),
            default => false,
        };
    }

    private static function isCompleteJpeg(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 4 || !str_starts_with($bytes, "\xFF\xD8")) return false;

        $offset = 2;
        $inScan = false;
        $seenFrame = false;
        $seenScan = false;
        while ($offset < $length) {
            if ($inScan) {
                if (ord($bytes[$offset]) !== 0xFF) {
                    $offset++;
                    continue;
                }
                $markerOffset = $offset;
                while ($offset < $length && ord($bytes[$offset]) === 0xFF) $offset++;
                if ($offset >= $length) return false;
                $marker = ord($bytes[$offset]);
                if ($marker === 0x00 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                    $offset++;
                    continue;
                }
                if ($marker === 0xD9) return $seenFrame && $seenScan && $offset + 1 === $length;
                $offset = $markerOffset;
                $inScan = false;
                continue;
            }

            if (ord($bytes[$offset]) !== 0xFF) return false;
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) $offset++;
            if ($offset >= $length) return false;
            $marker = ord($bytes[$offset]);
            $offset++;
            if ($marker === 0x00 || $marker === 0xD8 || $marker === 0x01
                || ($marker >= 0xD0 && $marker <= 0xD7)
            ) {
                return false;
            }
            if ($marker === 0xD9) return $seenFrame && $seenScan && $offset === $length;
            if ($offset + 2 > $length) return false;
            $unpacked = unpack('nlength', substr($bytes, $offset, 2));
            $segmentLength = is_array($unpacked) ? ($unpacked['length'] ?? null) : null;
            if (!is_int($segmentLength) || $segmentLength < 2 || $segmentLength > $length - $offset) {
                return false;
            }
            if (self::isJpegStartOfFrameMarker($marker)) {
                if ($segmentLength < 11) return false;
                $componentCount = ord($bytes[$offset + 7]);
                if ($componentCount < 1 || $segmentLength !== 8 + (3 * $componentCount)) return false;
                $seenFrame = true;
            }
            if ($marker === 0xDA) {
                if (!$seenFrame || $segmentLength < 8) return false;
                $scanComponentCount = ord($bytes[$offset + 2]);
                if ($scanComponentCount < 1 || $segmentLength !== 6 + (2 * $scanComponentCount)) return false;
                $seenScan = true;
                $inScan = true;
            }
            $offset += $segmentLength;
        }
        return false;
    }

    private static function isJpegStartOfFrameMarker(int $marker): bool
    {
        return in_array($marker, [
            0xC0, 0xC1, 0xC2, 0xC3,
            0xC5, 0xC6, 0xC7,
            0xC9, 0xCA, 0xCB,
            0xCD, 0xCE, 0xCF,
        ], true);
    }

    private static function isCompletePng(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 20 || !str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) return false;

        $offset = 8;
        $seenHeader = false;
        $seenImageData = false;
        while ($offset <= $length - 12) {
            $unpacked = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($unpacked) ? ($unpacked['length'] ?? null) : null;
            if (!is_int($chunkLength) || $chunkLength < 0 || $chunkLength > $length - $offset - 12) {
                return false;
            }
            $type = substr($bytes, $offset + 4, 4);
            if (preg_match('/^[A-Za-z]{4}$/D', $type) !== 1) return false;
            $data = substr($bytes, $offset + 8, $chunkLength);
            $storedCrc = substr($bytes, $offset + 8 + $chunkLength, 4);
            $computedCrc = pack('N', crc32($type . $data));
            if (!hash_equals($computedCrc, $storedCrc)) return false;
            $nextOffset = $offset + 12 + $chunkLength;
            if (!$seenHeader) {
                if ($type !== 'IHDR' || $chunkLength !== 13) return false;
                $seenHeader = true;
            } elseif ($type === 'IHDR') {
                return false;
            }
            if ($type === 'IDAT') $seenImageData = true;
            if ($type === 'IEND') {
                return $chunkLength === 0
                    && $nextOffset === $length
                    && $seenImageData;
            }
            $offset = $nextOffset;
        }
        return false;
    }

    private static function isCompleteGif(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 14 || (!str_starts_with($bytes, 'GIF87a') && !str_starts_with($bytes, 'GIF89a'))) {
            return false;
        }
        $logicalWidth = ord($bytes[6]) | (ord($bytes[7]) << 8);
        $logicalHeight = ord($bytes[8]) | (ord($bytes[9]) << 8);
        if ($logicalWidth <= 0 || $logicalHeight <= 0) return false;
        $packed = ord($bytes[10]);
        $offset = 13;
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (2 << ($packed & 0x07));
            if ($offset > $length) return false;
        }

        $seenImage = false;
        while ($offset < $length) {
            $blockType = ord($bytes[$offset]);
            if ($blockType === 0x3B) return $seenImage && $offset + 1 === $length;
            if ($blockType === 0x21) {
                if ($offset + 2 > $length) return false;
                $nextOffset = self::skipGifSubBlocks($bytes, $offset + 2, $length);
                if ($nextOffset === null) return false;
                $offset = $nextOffset;
                continue;
            }
            if ($blockType !== 0x2C || $offset + 10 > $length) return false;

            $frameLeft = ord($bytes[$offset + 1]) | (ord($bytes[$offset + 2]) << 8);
            $frameTop = ord($bytes[$offset + 3]) | (ord($bytes[$offset + 4]) << 8);
            $frameWidth = ord($bytes[$offset + 5]) | (ord($bytes[$offset + 6]) << 8);
            $frameHeight = ord($bytes[$offset + 7]) | (ord($bytes[$offset + 8]) << 8);
            if ($frameWidth <= 0
                || $frameHeight <= 0
                || $frameWidth > $logicalWidth
                || $frameLeft > $logicalWidth - $frameWidth
                || $frameHeight > $logicalHeight
                || $frameTop > $logicalHeight - $frameHeight
                || $frameHeight > intdiv(PHP_INT_MAX, $frameWidth)
            ) {
                return false;
            }
            $expectedIndexes = $frameWidth * $frameHeight;
            $imagePacked = ord($bytes[$offset + 9]);
            $offset += 10;
            if (($imagePacked & 0x80) !== 0) {
                $offset += 3 * (2 << ($imagePacked & 0x07));
                if ($offset > $length) return false;
            }
            if ($offset >= $length) return false;
            $minimumCodeSize = ord($bytes[$offset]);
            if ($minimumCodeSize < 2 || $minimumCodeSize > 8) return false;
            $nextOffset = self::validatedGifLzwNextOffset(
                $bytes,
                $offset + 1,
                $length,
                $minimumCodeSize,
                $expectedIndexes,
            );
            if ($nextOffset === null) return false;
            $seenImage = true;
            $offset = $nextOffset;
        }
        return false;
    }

    private static function validatedGifLzwNextOffset(
        string $bytes,
        int $offset,
        int $length,
        int $minimumCodeSize,
        int $expectedIndexes,
    ): ?int
    {
        $availableInput = $length - $offset;
        if ($availableInput <= 0
            || $expectedIndexes <= 0
            || $availableInput > intdiv(PHP_INT_MAX, 8)
        ) return null;

        $clearCode = 1 << $minimumCodeSize;
        $endCode = $clearCode + 1;
        $nextCode = $endCode + 1;
        $codeWidth = $minimumCodeSize + 1;
        $entryLengths = array_fill(0, 4096, 0);
        $entryFirst = array_fill(0, 4096, 0);
        for ($literal = 0; $literal < $clearCode; $literal++) {
            $entryLengths[$literal] = 1;
            $entryFirst[$literal] = $literal;
        }

        $cursor = $offset;
        $currentBlockRemaining = 0;
        $bitBuffer = 0;
        $bufferBits = 0;
        $outputCount = 0;
        $previousLength = null;
        $previousFirst = null;
        $codeReads = 0;
        $maxCodeReads = intdiv($availableInput * 8, 3) + 1;

        while ($codeReads++ < $maxCodeReads) {
            while ($bufferBits < $codeWidth) {
                if ($currentBlockRemaining === 0) {
                    if ($cursor >= $length) return null;
                    $currentBlockRemaining = ord($bytes[$cursor]);
                    $cursor++;
                    if ($currentBlockRemaining === 0
                        || $currentBlockRemaining > $length - $cursor
                    ) {
                        return null;
                    }
                }
                $bitBuffer |= ord($bytes[$cursor]) << $bufferBits;
                $bufferBits += 8;
                $cursor++;
                $currentBlockRemaining--;
            }
            $code = $bitBuffer & ((1 << $codeWidth) - 1);
            $bitBuffer >>= $codeWidth;
            $bufferBits -= $codeWidth;

            if ($code === $clearCode) {
                $nextCode = $endCode + 1;
                $codeWidth = $minimumCodeSize + 1;
                $previousLength = null;
                $previousFirst = null;
                continue;
            }
            if ($code === $endCode) {
                if ($outputCount !== $expectedIndexes
                    || $currentBlockRemaining !== 0
                    || $cursor >= $length
                    || ord($bytes[$cursor]) !== 0
                ) {
                    return null;
                }
                return $cursor + 1;
            }

            if ($previousLength === null) {
                if ($code < 0 || $code >= $clearCode) return null;
                if ($outputCount >= $expectedIndexes) return null;
                $outputCount++;
                $previousLength = 1;
                $previousFirst = $code;
                continue;
            }

            if ($code < $nextCode) {
                if ($code < $clearCode) {
                    $currentLength = 1;
                    $currentFirst = $code;
                } elseif ($code >= $endCode + 1 && $entryLengths[$code] > 0) {
                    $currentLength = $entryLengths[$code];
                    $currentFirst = $entryFirst[$code];
                } else {
                    return null;
                }
            } elseif ($code === $nextCode && $nextCode < 4096) {
                if ($previousLength >= $expectedIndexes) return null;
                $currentLength = $previousLength + 1;
                $currentFirst = $previousFirst;
            } else {
                return null;
            }

            if ($currentLength > $expectedIndexes - $outputCount) return null;
            $outputCount += $currentLength;

            if ($nextCode < 4096) {
                if ($previousLength >= $expectedIndexes) return null;
                $entryLengths[$nextCode] = $previousLength + 1;
                $entryFirst[$nextCode] = $previousFirst;
                $nextCode++;
                if ($codeWidth < 12 && $nextCode === (1 << $codeWidth)) $codeWidth++;
            }
            $previousLength = $currentLength;
            $previousFirst = $currentFirst;
        }
        return null;
    }

    private static function skipGifSubBlocks(string $bytes, int $offset, int $length): ?int
    {
        while ($offset < $length) {
            $blockLength = ord($bytes[$offset]);
            $offset++;
            if ($blockLength === 0) return $offset;
            if ($blockLength > $length - $offset) return null;
            $offset += $blockLength;
        }
        return null;
    }

    private static function isCompleteWebp(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 12
            || !str_starts_with($bytes, 'RIFF')
            || substr($bytes, 8, 4) !== 'WEBP'
        ) {
            return false;
        }
        $unpacked = unpack('Vsize', substr($bytes, 4, 4));
        $declaredSize = is_array($unpacked) ? ($unpacked['size'] ?? null) : null;
        if (!is_int($declaredSize) || $declaredSize < 4 || $declaredSize !== $length - 8) return false;

        $offset = 12;
        $chunkCount = 0;
        $seenImagePayload = false;
        while ($offset < $length) {
            if ($offset + 8 > $length) return false;
            $type = substr($bytes, $offset, 4);
            $chunkSizeRaw = unpack('Vsize', substr($bytes, $offset + 4, 4));
            $chunkSize = is_array($chunkSizeRaw) ? ($chunkSizeRaw['size'] ?? null) : null;
            if (!is_int($chunkSize) || $chunkSize < 0 || $chunkSize > $length - $offset - 8) return false;
            if ($chunkCount === 0 && !in_array($type, ['VP8 ', 'VP8L', 'VP8X'], true)) return false;
            if ($type === 'VP8 ' && $chunkSize < 10) return false;
            if ($type === 'VP8L' && $chunkSize < 5) return false;
            if ($type === 'VP8X' && $chunkSize !== 10) return false;
            if ($type === 'ANMF') {
                $payloadOffset = $offset + 8;
                if ($chunkSize < 16
                    || !self::isCompleteWebpFrame($bytes, $payloadOffset + 16, $payloadOffset + $chunkSize)
                ) {
                    return false;
                }
                $seenImagePayload = true;
            } elseif (in_array($type, ['VP8 ', 'VP8L'], true)) {
                $seenImagePayload = true;
            }

            $offset += 8 + $chunkSize;
            if (($chunkSize & 1) === 1) {
                if ($offset >= $length || $bytes[$offset] !== "\x00") return false;
                $offset++;
            }
            $chunkCount++;
        }
        return $offset === $length && $chunkCount > 0 && $seenImagePayload;
    }

    private static function isCompleteWebpFrame(string $bytes, int $offset, int $end): bool
    {
        $seenAlpha = false;
        $seenImage = false;
        while ($offset < $end) {
            if ($end - $offset < 8) return false;
            $type = substr($bytes, $offset, 4);
            $chunkSizeRaw = unpack('Vsize', substr($bytes, $offset + 4, 4));
            $chunkSize = is_array($chunkSizeRaw) ? ($chunkSizeRaw['size'] ?? null) : null;
            if (!is_int($chunkSize) || $chunkSize < 0 || $chunkSize > $end - $offset - 8) return false;

            if ($type === 'ALPH') {
                if ($seenAlpha || $seenImage || $chunkSize < 1) return false;
                $seenAlpha = true;
            } elseif ($type === 'VP8 ') {
                if ($seenImage || $chunkSize < 10) return false;
                $seenImage = true;
            } elseif ($type === 'VP8L') {
                if ($seenAlpha || $seenImage || $chunkSize < 5) return false;
                $seenImage = true;
            } else {
                return false;
            }

            $offset += 8 + $chunkSize;
            if (($chunkSize & 1) === 1) {
                if ($offset >= $end || $bytes[$offset] !== "\x00") return false;
                $offset++;
            }
        }
        return $offset === $end && $seenImage;
    }
}

interface ImagePayloadValidator
{
    public function isCompleteDecode(string $bytes, int $width, int $height): bool;
    public function hasCompleteDecodeAttestation(string $bytes, int $width, int $height): bool;
}

final class GdImagePayloadValidator implements ImagePayloadValidator
{
    private ?string $lastAttestation = null;

    public function isCompleteDecode(string $bytes, int $width, int $height): bool
    {
        if (!extension_loaded('gd')) return false;
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        if (!ImageContainerPolicy::isComplete($bytes, $mime)) return false;
        $decoded = null;
        try {
            $decoded = @imagecreatefromstring($bytes);
            $valid = $decoded instanceof \GdImage
                && imagesx($decoded) === $width
                && imagesy($decoded) === $height;
            if ($valid) $this->lastAttestation = self::attestation($bytes, $width, $height);
            return $valid;
        } catch (\Throwable) {
            return false;
        } finally {
            if ($decoded instanceof \GdImage) imagedestroy($decoded);
        }
    }

    public function hasCompleteDecodeAttestation(string $bytes, int $width, int $height): bool
    {
        return $this->lastAttestation !== null
            && hash_equals($this->lastAttestation, self::attestation($bytes, $width, $height));
    }

    private static function attestation(string $bytes, int $width, int $height): string
    {
        return hash('sha256', $bytes) . ':' . $width . ':' . $height;
    }
}

final class GdImageDecoder implements ImageDecoder
{
    private ImageOutputEncoder $outputEncoder;
    private ImagePayloadValidator $payloadValidator;

    public function __construct(
        ?ImageOutputEncoder $outputEncoder = null,
        ?ImagePayloadValidator $payloadValidator = null,
    )
    {
        $this->payloadValidator = $payloadValidator ?? new GdImagePayloadValidator();
        $this->outputEncoder = $outputEncoder ?? new GdImageOutputEncoder(
            payloadValidator: $this->payloadValidator,
        );
    }

    public function decode(string $bytes, int $segments, int $maxPixels): ImageDecodeResult
    {
        $startedNs = hrtime(true);
        $inputBytes = strlen($bytes);
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) {
            throw new ImageProcessingException('Unable to identify image payload', ['input_bytes' => $inputBytes]);
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        try {
            $pixels = ImagePixelPolicy::checkedPixels($width, $height, max(0, $maxPixels));
        } catch (ImageProcessingException $error) {
            throw new ImageProcessingException($error->getMessage(), [
                'input_bytes' => $inputBytes,
                'width' => $width,
                'height' => $height,
            ], $error);
        }
        $src = null;
        $dst = null;
        $bufferLevel = ob_get_level();
        $decodeMs = null;
        $encodeStartedNs = null;
        try {
            $mime = strtolower((string) ($info['mime'] ?? ''));
            $codec = match ($mime) {
                'image/jpeg', 'image/jpg' => 'jpeg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => throw new ImageProcessingException('Unsupported image payload'),
            };
            if ($segments < 0) {
                throw new ImageProcessingException('Invalid image scramble segments');
            }
            if ($segments === 0 || $mime === 'image/gif') {
                if (!$this->payloadValidator->isCompleteDecode($bytes, $width, $height)) {
                    throw new ImageProcessingException('Image payload failed full validation');
                }
                $decodeMs = self::elapsedMs($startedNs);
                return new ImageDecodeResult(
                    $bytes,
                    $mime,
                    $codec,
                    $inputBytes,
                    $width,
                    $height,
                    $pixels,
                    $decodeMs,
                    0,
                    memory_get_peak_usage(true),
                );
            }
            if (!ImageContainerPolicy::isComplete($bytes, $mime)) {
                throw new ImageProcessingException('Image container is incomplete');
            }
            if (!extension_loaded('gd')) throw new ImageProcessingException('GD required');

            $src = @imagecreatefromstring($bytes);
            if (!$src instanceof \GdImage) throw new ImageProcessingException('Cannot decode image bytes');

            $dst = imagecreatetruecolor($width, $height);
            if (!$dst instanceof \GdImage) throw new ImageProcessingException('Cannot allocate decoded image');
            $background = imagecolorallocate($dst, 255, 255, 255);
            if ($background === false || !imagefill($dst, 0, 0, $background)) {
                throw new ImageProcessingException('Cannot initialize decoded image');
            }

            $over = $height % $segments;
            for ($index = 0; $index < $segments; $index++) {
                $move = intdiv($height, $segments);
                $sourceY = $height - ($move * ($index + 1)) - $over;
                $destinationY = $move * $index;
                if ($index === 0) $move += $over; else $destinationY += $over;
                if (!imagecopy($dst, $src, 0, $destinationY, 0, $sourceY, $width, $move)) {
                    throw new ImageProcessingException('Cannot rearrange image segments');
                }
            }

            $decodeMs = self::elapsedMs($startedNs);
            $encodeStartedNs = hrtime(true);
            $encoded = $this->outputEncoder->encode($dst);
            $encodeMs = self::elapsedMs($encodeStartedNs);

            return new ImageDecodeResult(
                $encoded->bytes,
                $encoded->mime,
                $encoded->codec,
                $inputBytes,
                $width,
                $height,
                $pixels,
                $decodeMs,
                $encodeMs,
                memory_get_peak_usage(true),
            );
        } catch (ImageProcessingException $error) {
            throw new ImageProcessingException($error->getMessage(), [
                'input_bytes' => $inputBytes,
                'width' => $width,
                'height' => $height,
                'pixels' => $pixels,
                'decode_ms' => $decodeMs ?? self::elapsedMs($startedNs),
                'encode_ms' => $encodeStartedNs === null ? 0 : self::elapsedMs($encodeStartedNs),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ] + $error->metrics(), $error);
        } catch (\Throwable $error) {
            throw new ImageProcessingException('Image decode failed', [
                'input_bytes' => $inputBytes,
                'width' => $width,
                'height' => $height,
                'pixels' => $pixels,
                'decode_ms' => $decodeMs ?? self::elapsedMs($startedNs),
                'encode_ms' => $encodeStartedNs === null ? 0 : self::elapsedMs($encodeStartedNs),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ], $error);
        } finally {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
            if ($src instanceof \GdImage) imagedestroy($src);
            if ($dst instanceof \GdImage) imagedestroy($dst);
        }
    }

    private static function elapsedMs(int $startedNs): int
    {
        return max(0, (int) floor((hrtime(true) - $startedNs) / 1_000_000));
    }
}

final class ScrambleDecoder
{
    private const WEBP_QUALITY = 85;
    private const JPEG_QUALITY = 85;

    public static function segments(string $scrambleId, string $aid, string $filename): int
    {
        $sid = self::canonicalDecimal($scrambleId);
        $canonicalAid = self::canonicalDecimal($aid);

        if (self::compareDecimal($canonicalAid, $sid) < 0) return 0;
        if (self::compareDecimal($canonicalAid, (string) JmConfig::SCRAMBLE_268850) < 0) return 10;

        $x = self::compareDecimal($canonicalAid, (string) JmConfig::SCRAMBLE_421926) < 0 ? 10 : 8;
        $pageName = self::pageNameForScramble($filename);
        $h = md5($canonicalAid . $pageName);
        $n = ord($h[strlen($h) - 1]) % $x;

        return $n * 2 + 2;
    }

    private static function canonicalDecimal(string $value): string
    {
        if (preg_match('/\A[0-9]{1,20}\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid decimal identifier');
        }
        $canonical = ltrim($value, '0');
        return $canonical === '' ? '0' : $canonical;
    }

    private static function compareDecimal(string $left, string $right): int
    {
        $lengthOrder = strlen($left) <=> strlen($right);
        return $lengthOrder !== 0 ? $lengthOrder : (strcmp($left, $right) <=> 0);
    }

    private static function pageNameForScramble(string $filename): string
    {
        $clean = explode('?', str_replace('\\', '/', trim($filename)), 2)[0];
        $base = basename($clean);
        $name = pathinfo($clean, PATHINFO_FILENAME);
        return $name !== '' ? $name : $base;
    }

    public static function decodeFile(string $srcPath, int $segments, string $dstPath): bool
    {
        if ($segments === 0) return copy($srcPath, $dstPath);
        if (!extension_loaded('gd')) throw new \RuntimeException('GD required');

        $src = imagecreatefromstring(file_get_contents($srcPath));
        if ($src === false) throw new \RuntimeException("Cannot open: {$srcPath}");

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));

        $over = $h % $segments;
        for ($i = 0; $i < $segments; $i++) {
            $move = (int) floor($h / $segments);
            $ySrc = $h - ($move * ($i + 1)) - $over;
            $yDst = $move * $i;
            if ($i === 0) { $move += $over; } else { $yDst += $over; }
            imagecopy($dst, $src, 0, $yDst, 0, $ySrc, $w, $move);
        }

        $ext = strtolower(pathinfo($dstPath, PATHINFO_EXTENSION));
        $ok  = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($dst, $dstPath, self::JPEG_QUALITY),
            'png'         => imagepng($dst, $dstPath),
            'gif'         => imagegif($dst, $dstPath),
            'webp'        => imagewebp($dst, $dstPath, self::WEBP_QUALITY),
            default       => imagejpeg($dst, $dstPath, self::JPEG_QUALITY),
        };

        imagedestroy($src);
        imagedestroy($dst);
        return $ok;
    }

    public static function decodeBytes(string $bytes, int $segments): string
    {
        return self::decodeBytesWithInfo($bytes, $segments)['bytes'];
    }

    /** @return array{bytes:string, mime:string, codec:string} */
    public static function decodeBytesWithInfo(string $bytes, int $segments): array
    {
        if ($segments === 0) return ['bytes' => $bytes, 'mime' => 'application/octet-stream', 'codec' => 'original'];
        if (!extension_loaded('gd')) throw new \RuntimeException('GD required');

        $src = imagecreatefromstring($bytes);
        if ($src === false) throw new \RuntimeException('Cannot open image bytes');

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));

        $over = $h % $segments;
        for ($i = 0; $i < $segments; $i++) {
            $move = (int) floor($h / $segments);
            $ySrc = $h - ($move * ($i + 1)) - $over;
            $yDst = $move * $i;
            if ($i === 0) { $move += $over; } else { $yDst += $over; }
            imagecopy($dst, $src, 0, $yDst, 0, $ySrc, $w, $move);
        }

        ob_start();
        if (self::canEncodeWebp()) {
            $ok = imagewebp($dst, null, self::WEBP_QUALITY);
            $mime = 'image/webp';
            $codec = 'webp';
        } else {
            $ok = imagejpeg($dst, null, self::JPEG_QUALITY);
            $mime = 'image/jpeg';
            $codec = 'jpeg';
        }
        $decoded = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || $decoded === false || $decoded === '') {
            throw new \RuntimeException('Failed to encode decoded image');
        }

        return ['bytes' => $decoded, 'mime' => $mime, 'codec' => $codec];
    }

    public static function preferredDecodedMime(): string
    {
        return self::canEncodeWebp() ? 'image/webp' : 'image/jpeg';
    }

    public static function preferredDecodedCodec(): string
    {
        return self::canEncodeWebp() ? 'webp' : 'jpeg';
    }

    private static function canEncodeWebp(): bool
    {
        return function_exists('imagewebp')
            && defined('IMG_WEBP')
            && ((imagetypes() & IMG_WEBP) === IMG_WEBP);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Service
// ═════════════════════════════════════════════════════════════════════════════

final class JmService
{
    private const LOCAL_LIST_PAGE_SIZE = 20;
    private const SOURCE_LIST_PAGE_SIZE = 80;
    private const PROMOTE_SOURCE_PAGE_SIZE = 27;
    private const UNSUPPORTED_HOME_SECTION_TITLES = ['禁漫小说', '禁漫书库', '禁漫書庫', '禁漫小說'];

    private JmApiClient $api;
    private MemoryCache $cache;
    private string $cacheNamespace;
    private RequestContext $context;
    private ImageDecoder $imageDecoder;
    private ImagePayloadValidator $imagePayloadValidator;

    public function __construct(
        RequestContext $context,
        ?JmApiClient $api = null,
        ?MemoryCache $cache = null,
        ?ImageDecoder $imageDecoder = null,
        ?ImagePayloadValidator $imagePayloadValidator = null,
    )
    {
        $this->context = $context;
        $this->api = $api ?? new JmApiClient($context);
        $this->cache = $cache ?? new MemoryCache();
        $this->imagePayloadValidator = $imagePayloadValidator ?? new GdImagePayloadValidator();
        $this->imageDecoder = $imageDecoder ?? new GdImageDecoder(
            payloadValidator: $this->imagePayloadValidator,
        );
        $this->cacheNamespace = $context->testCacheNamespace();
    }

    /**
     * @param array<string,mixed> $keyFields
     * @param callable(array):bool $validator
     * @param callable():array $producer
     */
    private function cacheThroughArray(
        string $class,
        array $keyFields,
        int $ttl,
        callable $validator,
        callable $producer,
    ): array {
        if ($ttl <= 0 || !$this->cache->isAvailable()) {
            $this->context->recordSourceCache('disabled');
            return $this->produceValidatedArray($validator, $producer);
        }

        $key = $this->cacheNamespace . $class . ':v1:' . hash('sha256', json_encode(
            self::canonicalCacheValue($keyFields),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
        $cached = $this->cache->get($key);
        if (is_array($cached) && $validator($cached)) {
            $this->context->recordSourceCache('hit');
            return $cached;
        }
        if ($cached !== null) $this->cache->delete($key);

        $leaseKey = 'cache-fill-lease:' . hash('sha256', $key);
        $token = random_int(1, PHP_INT_MAX);
        $remainingMs = $this->context->budget()->remainingMs();
        $configuredLockTtl = self::envInt(
            'JM_CACHE_FILL_LOCK_TTL',
            JmConfig::DEFAULT_CACHE_FILL_LOCK_TTL,
            2,
            90,
        );
        $lockTtl = min(90, max($configuredLockTtl, (int) ceil(max(1, $remainingMs) / 1000) + 2));

        if ($this->cache->tryAdd($leaseKey, $token, $lockTtl)) {
            try {
                $cached = $this->cache->get($key);
                if (is_array($cached) && $validator($cached)) {
                    $this->context->recordSourceCache('hit');
                    return $cached;
                }

                $value = $this->produceValidatedArray($validator, $producer);
                $this->context->recordSourceCache('miss');
                $this->cache->set($key, $value, $ttl);
                return $value;
            } finally {
                $this->cache->compareAndDelete($leaseKey, $token);
            }
        }

        $waitMs = min(
            self::envInt('JM_CACHE_FILL_WAIT_MS', JmConfig::DEFAULT_CACHE_FILL_WAIT_MS, 0, 5000),
            max(0, $this->context->budget()->remainingMs() - 100),
        );
        $waitStartedNs = hrtime(true);
        while ((int) floor((hrtime(true) - $waitStartedNs) / 1_000_000) < $waitMs) {
            usleep(random_int(20_000, 60_000));
            $cached = $this->cache->get($key);
            if (is_array($cached) && $validator($cached)) {
                $this->context->recordSourceCache('hit');
                return $cached;
            }
        }

        if ($this->context->budget()->remainingMs() <= 100) {
            throw new JmException('Source cache fill exceeded request deadline', 504);
        }

        $value = $this->produceValidatedArray($validator, $producer);
        $this->context->recordSourceCache('miss');
        $this->cache->set($key, $value, $ttl);
        return $value;
    }

    /** @param callable(array):bool $validator @param callable():array $producer */
    private function metadataCacheThroughArray(
        string $key,
        int $ttl,
        callable $validator,
        callable $producer,
    ): array {
        if ($ttl <= 0 || !$this->cache->isAvailable()) {
            return $this->produceValidatedArray($validator, $producer, 'Invalid upstream metadata payload');
        }

        $cached = $this->cache->get($key);
        if (is_array($cached) && $validator($cached)) return $cached;
        if ($cached !== null) $this->cache->delete($key);

        $leaseKey = 'metadata-fill-lease:' . hash('sha256', $key);
        $token = random_int(1, PHP_INT_MAX);
        $remainingMs = $this->context->budget()->remainingMs();
        $configuredLockTtl = self::envInt(
            'JM_CACHE_FILL_LOCK_TTL',
            JmConfig::DEFAULT_CACHE_FILL_LOCK_TTL,
            2,
            90,
        );
        $lockTtl = min(90, max($configuredLockTtl, (int) ceil(max(1, $remainingMs) / 1000) + 2));

        if ($this->cache->tryAdd($leaseKey, $token, $lockTtl)) {
            try {
                $cached = $this->cache->get($key);
                if (is_array($cached) && $validator($cached)) return $cached;

                $value = $this->produceValidatedArray($validator, $producer, 'Invalid upstream metadata payload');
                $this->cache->set($key, $value, $ttl);
                return $value;
            } finally {
                $this->cache->compareAndDelete($leaseKey, $token);
            }
        }

        $waitMs = min(
            self::envInt('JM_CACHE_FILL_WAIT_MS', JmConfig::DEFAULT_CACHE_FILL_WAIT_MS, 0, 5000),
            max(0, $this->context->budget()->remainingMs() - 100),
        );
        $waitStartedNs = hrtime(true);
        while ((int) floor((hrtime(true) - $waitStartedNs) / 1_000_000) < $waitMs) {
            usleep(random_int(20_000, 60_000));
            $cached = $this->cache->get($key);
            if (is_array($cached) && $validator($cached)) return $cached;
        }

        if ($this->context->budget()->remainingMs() <= 100) {
            throw new JmException('Metadata cache fill exceeded request deadline', 504);
        }

        $value = $this->produceValidatedArray($validator, $producer, 'Invalid upstream metadata payload');
        $this->cache->set($key, $value, $ttl);
        return $value;
    }

    /** @param callable(array):bool $validator @param callable():array $producer */
    private function produceValidatedArray(
        callable $validator,
        callable $producer,
        string $errorMessage = 'Invalid upstream list payload',
    ): array
    {
        $value = $producer();
        if (!$validator($value)) throw new JmException($errorMessage, 502);
        return $value;
    }

    private static function canonicalCacheValue(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::canonicalCacheValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalCacheValue($item);
        return $value;
    }

    private static function normalizeAlbumPayload(mixed $payload, string $expectedAlbumId): array
    {
        if (!is_array($payload) || array_is_list($payload)) {
            throw new JmException('Invalid upstream album payload', 502);
        }

        $rawId = $payload['id'] ?? null;
        $id = $rawId === null ? $expectedAlbumId : trim(PayloadNormalizer::identifierString($rawId));
        if ($id === '' || !hash_equals($expectedAlbumId, $id)) {
            throw new JmException('Invalid upstream album id', 502);
        }
        $name = trim(PayloadNormalizer::scalarString($payload['name'] ?? ''));
        if ($name === '') throw new JmException('Invalid upstream album name', 502);

        $rawSeries = $payload['series'] ?? null;
        if ($rawSeries !== null && (!is_array($rawSeries) || !array_is_list($rawSeries))) {
            throw new JmException('Invalid upstream album series', 502);
        }
        $series = [];
        foreach ($rawSeries ?? [] as $chapter) {
            if (!is_array($chapter) || array_is_list($chapter)) {
                throw new JmException('Invalid upstream album series item', 502);
            }
            $photoId = trim(PayloadNormalizer::identifierString($chapter['id'] ?? ''));
            if (preg_match('/^\d{1,20}$/', $photoId) !== 1) {
                throw new JmException('Invalid upstream album series id', 502);
            }
            $series[] = [
                'id' => $photoId,
                'name' => self::albumScalar($chapter['name'] ?? '', ''),
                'sort' => self::albumScalar($chapter['sort'] ?? '1', '1'),
            ];
        }

        $related = $payload['related_list'] ?? [];
        if ($related === null) $related = [];
        if (!is_array($related) || !array_is_list($related)) {
            throw new JmException('Invalid upstream album related list', 502);
        }
        $normalizedRelated = [];
        foreach ($related as $item) {
            $normalizedRelated[] = self::normalizeJsonCacheValue($item);
        }

        return [
            'id' => $id,
            'name' => $name,
            'author' => self::albumStringList($payload['author'] ?? [], 'author'),
            'description' => self::albumScalar($payload['description'] ?? '', ''),
            'image' => self::albumScalar($payload['image'] ?? '', ''),
            'total_views' => self::albumScalar($payload['total_views'] ?? '0', '0'),
            'likes' => self::albumScalar($payload['likes'] ?? '0', '0'),
            'comment_total' => self::albumScalar($payload['comment_total'] ?? '0', '0'),
            'tags' => self::albumStringList($payload['tags'] ?? [], 'tags'),
            'works' => self::albumStringList($payload['works'] ?? [], 'works'),
            'actors' => self::albumStringList($payload['actors'] ?? [], 'actors'),
            'related_list' => $normalizedRelated,
            'series' => $series,
        ];
    }

    private static function isAlbumPayload(array $payload, string $expectedAlbumId): bool
    {
        try {
            return self::normalizeAlbumPayload($payload, $expectedAlbumId) === $payload;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function albumScalar(mixed $value, string $default): string
    {
        return PayloadNormalizer::scalarString($value, $default);
    }

    private static function albumStringList(mixed $value, string $field): array
    {
        return self::normalizeStringListPayload($value, 'album ' . $field);
    }

    private static function normalizeStringListPayload(mixed $value, string $label): array
    {
        if ($value === null) return [];
        if (is_scalar($value)) return PayloadNormalizer::stringList($value);
        if (!is_array($value) || !array_is_list($value)) {
            throw new JmException("Invalid upstream {$label} list", 502);
        }
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new JmException("Invalid upstream {$label} list item", 502);
            }
        }
        return PayloadNormalizer::stringList($value);
    }

    private static function normalizeJsonCacheValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) return $value;
        if (!is_array($value)) throw new JmException('Invalid upstream album nested value', 502);
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalizeJsonCacheValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::normalizeJsonCacheValue($item);
        return $value;
    }

    private static function normalizeListItemPayload(mixed $item): array
    {
        if (!is_array($item) || array_is_list($item)) {
            throw new JmException('Invalid upstream list item', 502);
        }

        $id = trim(PayloadNormalizer::identifierString($item['id'] ?? $item['aid'] ?? $item['AID'] ?? ''));
        if (preg_match('/^\d{1,20}$/', $id) !== 1) {
            throw new JmException('Invalid upstream list item id', 502);
        }

        $normalized = [
            'id' => $id,
            'name' => PayloadNormalizer::scalarString($item['name'] ?? ''),
            'author' => PayloadNormalizer::scalarString($item['author'] ?? ''),
            'description' => PayloadNormalizer::scalarString($item['description'] ?? ''),
            'image' => PayloadNormalizer::scalarString($item['image'] ?? ''),
        ];
        foreach (['category', 'category_sub'] as $field) {
            $category = $item[$field] ?? null;
            if (is_array($category) && !array_is_list($category)) {
                $normalized[$field] = [
                    'title' => PayloadNormalizer::scalarString($category['title'] ?? ''),
                ];
            }
        }
        $normalized['tags'] = self::normalizeStringListPayload($item['tags'] ?? [], 'list item tags');
        $normalized['works'] = self::normalizeStringListPayload($item['works'] ?? [], 'list item works');
        $normalized['actors'] = self::normalizeStringListPayload($item['actors'] ?? [], 'list item actors');
        $normalized['likes'] = PayloadNormalizer::scalarInt($item['likes'] ?? 0);
        $normalized['total_views'] = PayloadNormalizer::scalarInt($item['total_views'] ?? $item['totalViews'] ?? 0);
        $normalized['updated_at'] = isset($item['updated_at']) || isset($item['update_at'])
            ? PayloadNormalizer::scalarInt($item['updated_at'] ?? $item['update_at'])
            : null;
        return $normalized;
    }

    private static function normalizeListItemsPayload(mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new JmException('Invalid upstream list payload', 502);
        }
        $normalized = [];
        foreach ($payload as $item) {
            $normalized[] = self::normalizeListItemPayload($item);
        }
        return $normalized;
    }

    private static function normalizePagedListPayload(mixed $payload, string $itemsKey, bool $allowRedirect = false): array
    {
        if (!is_array($payload)) {
            throw new JmException('Invalid upstream paged list payload', 502);
        }
        $rawRedirectAid = $payload['redirect_aid'] ?? null;
        if ($allowRedirect && $rawRedirectAid !== null && !is_string($rawRedirectAid) && !is_int($rawRedirectAid)) {
            throw new JmException('Invalid upstream search redirect id', 502);
        }
        $redirectAid = $allowRedirect ? trim(PayloadNormalizer::identifierString($rawRedirectAid ?? '')) : '';
        if ($redirectAid !== '' && preg_match('/^\d{1,20}$/', $redirectAid) !== 1) {
            throw new JmException('Invalid upstream search redirect id', 502);
        }
        if (!array_key_exists($itemsKey, $payload) && $redirectAid === '') {
            throw new JmException('Invalid upstream paged list payload', 502);
        }
        $items = self::normalizeListItemsPayload($payload[$itemsKey] ?? []);
        $totalRaw = $payload['total'] ?? ($redirectAid !== '' ? 1 : null);
        $normalized = [$itemsKey => $items, 'total' => self::normalizeListTotal($totalRaw)];
        if ($allowRedirect) {
            $normalized['redirect_aid'] = $redirectAid;
        }
        return $normalized;
    }

    private static function normalizeListTotal(mixed $value): int
    {
        if (is_int($value)) {
            if ($value >= 0) return $value;
            throw new JmException('Invalid upstream list total', 502);
        }
        if (!is_string($value)) {
            throw new JmException('Invalid upstream list total', 502);
        }

        $raw = trim($value);
        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new JmException('Invalid upstream list total', 502);
        }
        $canonical = ltrim($raw, '0');
        if ($canonical === '') return 0;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($maximum)
            || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)
        ) {
            throw new JmException('Invalid upstream list total', 502);
        }
        return (int) $canonical;
    }

    private static function normalizePromoteHomePayload(mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new JmException('Invalid upstream promote payload', 502);
        }
        $sections = [];
        foreach ($payload as $section) {
            if (!is_array($section) || !array_key_exists('content', $section)) {
                throw new JmException('Invalid upstream promote section', 502);
            }
            $sections[] = [
                'title' => PayloadNormalizer::scalarString($section['title'] ?? ''),
                'content' => self::normalizeListItemsPayload($section['content']),
            ];
        }
        return $sections;
    }

    private static function isListItemsPayload(array $payload): bool
    {
        try { return self::normalizeListItemsPayload($payload) === $payload; } catch (\Throwable) { return false; }
    }

    private static function isPagedListPayload(array $payload, string $itemsKey, bool $allowRedirect = false): bool
    {
        try { return self::normalizePagedListPayload($payload, $itemsKey, $allowRedirect) === $payload; } catch (\Throwable) { return false; }
    }

    private static function isPromoteHomePayload(array $payload): bool
    {
        try { return self::normalizePromoteHomePayload($payload) === $payload; } catch (\Throwable) { return false; }
    }

    public function fetchAlbum(string $jmid): JmAlbum
    {
        $canonicalAlbumId = trim($jmid);
        if ($canonicalAlbumId === '') throw new JmException('Invalid album id', 400);
        $ttl = self::envInt('JM_ALBUM_CACHE_TTL', JmConfig::DEFAULT_ALBUM_CACHE_TTL, 0, 3600);
        $key = $this->cacheNamespace . 'album:v1:' . hash('sha256', $canonicalAlbumId);
        $payload = $this->metadataCacheThroughArray(
            $key,
            $ttl,
            static fn(array $value): bool => self::isAlbumPayload($value, $canonicalAlbumId),
            fn(): array => self::normalizeAlbumPayload(
                $this->api->callJson(JmConfig::ENDPOINT_ALBUM, ['id' => $canonicalAlbumId])['data'] ?? null,
                $canonicalAlbumId,
            ),
        );
        $resp = ['data' => $payload];
        return JmAlbum::fromApiResponse($resp['data'], $jmid);
    }

    public function fetchLatestList(int $page): JmListResult
    {
        $window = self::sourceListWindow($page);
        $sourcePage = (int) $window['source_page'];
        $payload = $this->cacheThroughArray(
            'list-source',
            ['endpoint' => JmConfig::ENDPOINT_LATEST, 'source_page' => $sourcePage],
            self::envInt('JM_LIST_CACHE_TTL', JmConfig::DEFAULT_LIST_CACHE_TTL, 0, 3600),
            static fn(array $value): bool => self::isListItemsPayload($value),
            fn(): array => self::normalizeListItemsPayload(
                $this->api->callJson(JmConfig::ENDPOINT_LATEST, ['page' => (string) $sourcePage])['data'] ?? null,
            ),
        );
        return $this->windowedListResultFromItems('latest', $page, $payload, 0, $window);
    }

    public function fetchPopularList(int $page, string $order = 'new'): JmListResult
    {
        $order = normalizeCatalogOrder($order);
        $window = self::sourceListWindow($page);
        $sourcePage = (int) $window['source_page'];
        $payload = $this->cacheThroughArray(
            'list-source',
            [
                'endpoint' => JmConfig::ENDPOINT_CATEGORY_FILTER,
                'source_page' => $sourcePage,
                'category' => 'latest',
                'order' => $order,
            ],
            self::envInt('JM_LIST_CACHE_TTL', JmConfig::DEFAULT_LIST_CACHE_TTL, 0, 3600),
            static fn(array $value): bool => self::isPagedListPayload($value, 'content'),
            fn(): array => self::normalizePagedListPayload(
                $this->api->callJson(JmConfig::ENDPOINT_CATEGORY_FILTER, [
                    'page' => (string) $sourcePage,
                    'c' => 'latest',
                    'o' => $order,
                ])['data'] ?? null,
                'content',
            ),
        );
        $items = $payload['content'];
        $total = (int) $payload['total'];
        return $this->windowedListResultFromItems('popular', $page, $items, $total, $window);
    }

    public function fetchPromoteList(int $page, int $sectionId = 0): JmListResult
    {
        if ($sectionId <= 0) {
            return $this->fetchPromoteHomeList($page);
        }

        $window = self::promoteListWindow($page);
        $sourcePage = (int) $window['source_page'];
        $sourceHasMore = true;
        $buffer = [];
        $total = 0;

        while (count($buffer) < $window['offset'] + self::LOCAL_LIST_PAGE_SIZE && $sourceHasMore) {
            $currentSourcePage = $sourcePage;
            $payload = $this->cacheThroughArray(
                'list-source',
                [
                    'endpoint' => JmConfig::ENDPOINT_PROMOTE_LIST,
                    'source_page' => $currentSourcePage,
                    'section_id' => $sectionId,
                ],
                self::envInt('JM_LIST_CACHE_TTL', JmConfig::DEFAULT_LIST_CACHE_TTL, 0, 3600),
                static fn(array $value): bool => self::isPagedListPayload($value, 'list'),
                fn(): array => self::normalizePagedListPayload(
                    $this->api->callJson(JmConfig::ENDPOINT_PROMOTE_LIST, [
                        'id' => (string) $sectionId,
                        'page' => (string) $currentSourcePage,
                    ])['data'] ?? null,
                    'list',
                ),
            );
            $items = $payload['list'];
            $total = (int) $payload['total'];
            $count = count($items);
            $loadedCount = $sourcePage * self::PROMOTE_SOURCE_PAGE_SIZE + $count;
            $sourceHasMore = $count >= self::PROMOTE_SOURCE_PAGE_SIZE && ($total === 0 || $loadedCount < $total);
            foreach ($items as $item) {
                $buffer[] = $item;
            }
            $sourcePage = $sourcePage + 1;
        }

        $window['source_has_more'] = $sourceHasMore;
        return $this->windowedListResultFromItems('promote', $page, $buffer, $total, $window);
    }

    public function fetchWeeklyList(int $page, ?string $categoryId = null, ?string $typeId = null): JmListResult
    {
        if ($categoryId === null || $typeId === null) {
            $defaults = $this->fetchWeekDefaults();
            $categoryId ??= $defaults['category_id'];
            $typeId ??= $defaults['type_id'];
        }

        $payload = $this->cacheThroughArray(
            'list-source',
            [
                'endpoint' => JmConfig::ENDPOINT_WEEK_FILTER,
                'page' => $page,
                'category_id' => $categoryId,
                'type_id' => $typeId,
            ],
            self::envInt('JM_WEEKLY_LIST_CACHE_TTL', JmConfig::DEFAULT_WEEKLY_LIST_CACHE_TTL, 0, 3600),
            static fn(array $value): bool => self::isPagedListPayload($value, 'list'),
            fn(): array => self::normalizePagedListPayload(
                $this->api->callJson(JmConfig::ENDPOINT_WEEK_FILTER, [
                    'page' => (string) $page,
                    'id' => $categoryId,
                    'type' => $typeId,
                ])['data'] ?? null,
                'list',
            ),
        );
        $items = $payload['list'];
        $total = (int) $payload['total'];
        return $this->listResultFromItems('weekly', $page, $items, $total);
    }

    private function fetchPromoteHomeList(int $page): JmListResult
    {
        $sections = $this->cacheThroughArray(
            'list-source',
            ['endpoint' => JmConfig::ENDPOINT_PROMOTE],
            self::envInt('JM_LIST_CACHE_TTL', JmConfig::DEFAULT_LIST_CACHE_TTL, 0, 3600),
            static fn(array $value): bool => self::isPromoteHomePayload($value),
            fn(): array => self::normalizePromoteHomePayload(
                $this->api->callJson(JmConfig::ENDPOINT_PROMOTE, [])['data'] ?? null,
            ),
        );
        $items = [];
        $seenIds = [];

        foreach ($sections as $section) {
            if (!is_array($section)) continue;
            if (self::isUnsupportedHomeSection(PayloadNormalizer::scalarString($section['title'] ?? ''))) continue;
            $content = $section['content'] ?? [];
            if (!is_array($content)) continue;
            foreach ($content as $item) {
                if (is_array($item)) {
                    $itemId = PayloadNormalizer::scalarString($item['id'] ?? $item['aid'] ?? $item['AID'] ?? '');
                    if ($itemId !== '' && isset($seenIds[$itemId])) continue;
                    if ($itemId !== '') $seenIds[$itemId] = true;
                    $items[] = $item;
                }
            }
        }

        $window = [
            'source_page' => 0,
            'offset' => (max(1, $page) - 1) * self::LOCAL_LIST_PAGE_SIZE,
            'source_page_size' => max(1, count($items)),
            'source_has_more' => false,
        ];

        return $this->windowedListResultFromItems('promote', $page, $items, count($items), $window);
    }

    private static function isUnsupportedHomeSection(string $title): bool
    {
        return in_array(trim($title), self::UNSUPPORTED_HOME_SECTION_TITLES, true);
    }

    private static function weekDefaultsCacheKey(string $namespace): string
    {
        $fields = [
            'endpoint' => JmConfig::ENDPOINT_WEEK,
            'schema' => 'category-type-defaults',
        ];
        return $namespace . 'week-defaults:v1:' . hash('sha256', json_encode(
            self::canonicalCacheValue($fields),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array{category_id:string, type_id:string} */
    private static function normalizeWeekDefaultsPayload(mixed $payload): array
    {
        if (!is_array($payload) || array_is_list($payload)) {
            throw new JmException('Weekly defaults unavailable', 502);
        }
        $categories = $payload['categories'] ?? null;
        $types = $payload['type'] ?? null;
        if (!is_array($categories) || !array_is_list($categories) || !is_array($types) || !array_is_list($types)) {
            throw new JmException('Weekly defaults unavailable', 502);
        }

        $categoryId = '';
        foreach ($categories as $category) {
            if (!is_array($category) || array_is_list($category)) continue;
            $candidateId = trim(PayloadNormalizer::identifierString($category['id'] ?? ''));
            if (preg_match('/^\d{1,20}$/', $candidateId) === 1) {
                $categoryId = $candidateId;
                break;
            }
        }

        $typeId = '';
        foreach ($types as $type) {
            if (!is_array($type) || array_is_list($type)) continue;
            $candidateId = trim(PayloadNormalizer::identifierString($type['id'] ?? ''));
            if (self::isValidWeeklyTypeId($candidateId)) {
                $typeId = $candidateId;
                break;
            }
        }

        if ($categoryId === '' || $typeId === '') {
            throw new JmException('Weekly defaults unavailable', 502);
        }
        return ['category_id' => $categoryId, 'type_id' => $typeId];
    }

    /** @return array{category_id:string, type_id:string} */
    private function produceWeekDefaults(): array
    {
        return self::normalizeWeekDefaultsPayload(
            $this->api->callJson(JmConfig::ENDPOINT_WEEK, [])['data'] ?? null,
        );
    }

    private static function validatedWeekDefaultsEntry(mixed $candidate, int $freshTtl, int $staleTtl): ?array
    {
        if (!is_array($candidate) || count($candidate) !== 4 || !isset(
            $candidate['value'],
            $candidate['fetched_at'],
            $candidate['fresh_until'],
            $candidate['stale_until'],
        )) return null;
        if (!is_array($candidate['value']) || !is_int($candidate['fetched_at'])
            || !is_int($candidate['fresh_until']) || !is_int($candidate['stale_until'])) return null;

        $value = $candidate['value'];
        $categoryId = trim(PayloadNormalizer::scalarString($value['category_id'] ?? ''));
        $typeId = trim(PayloadNormalizer::scalarString($value['type_id'] ?? ''));
        if (preg_match('/^\d{1,20}$/', $categoryId) !== 1
            || !self::isValidWeeklyTypeId($typeId)
            || $value !== ['category_id' => $categoryId, 'type_id' => $typeId]) return null;

        $fetchedAt = $candidate['fetched_at'];
        if ($fetchedAt < 0
            || $candidate['fresh_until'] !== $fetchedAt + $freshTtl
            || $candidate['stale_until'] !== $fetchedAt + $freshTtl + $staleTtl) return null;

        return [
            'value' => $value,
            'fetched_at' => $fetchedAt,
            'fresh_until' => $candidate['fresh_until'],
            'stale_until' => $candidate['stale_until'],
        ];
    }

    private static function canUseStaleWeekDefaults(?array $entry, int $now, int $staleTtl): bool
    {
        return $entry !== null && $staleTtl > 0 && $now <= $entry['stale_until'];
    }

    private static function isValidWeeklyTypeId(string $value): bool
    {
        return preg_match('/^(?:\d{1,20}|[A-Za-z][A-Za-z0-9_-]{0,31})$/', $value) === 1;
    }

    private function recordWeekDefaultsStaleFallback(): void
    {
        $this->cache->increment($this->cacheNamespace . 'diagnostics:week-defaults-stale-fallback-count:v1', 86400);
    }

    /** @return array{category_id:string, type_id:string} */
    private function fetchWeekDefaults(): array
    {
        $freshTtl = self::envInt(
            'JM_WEEK_DEFAULTS_CACHE_TTL',
            JmConfig::DEFAULT_WEEK_DEFAULTS_CACHE_TTL,
            0,
            86400,
        );
        $staleTtl = self::envInt(
            'JM_WEEK_DEFAULTS_STALE_TTL',
            JmConfig::DEFAULT_WEEK_DEFAULTS_STALE_TTL,
            0,
            604800,
        );
        if ($freshTtl <= 0 || !$this->cache->isAvailable()) return $this->produceWeekDefaults();

        $key = self::weekDefaultsCacheKey($this->cacheNamespace);
        $rawEntry = $this->cache->get($key);
        $entry = self::validatedWeekDefaultsEntry($rawEntry, $freshTtl, $staleTtl);
        if ($rawEntry !== null && $entry === null) $this->cache->delete($key);

        $now = $this->context->unixTime();
        if ($entry !== null && $now < $entry['fresh_until']) return $entry['value'];

        $leaseKey = 'week-defaults-fill-lease:' . hash('sha256', $key);
        $remainingMs = $this->context->budget()->remainingMs();
        $configuredLockTtl = self::envInt(
            'JM_CACHE_FILL_LOCK_TTL',
            JmConfig::DEFAULT_CACHE_FILL_LOCK_TTL,
            2,
            90,
        );
        $lockTtl = min(90, max($configuredLockTtl, (int) ceil(max(1, $remainingMs) / 1000) + 2));

        $refreshAsOwner = function (int $token) use ($key, $leaseKey, $freshTtl, $staleTtl, $entry): array {
            try {
                $latestRaw = $this->cache->get($key);
                $latest = self::validatedWeekDefaultsEntry($latestRaw, $freshTtl, $staleTtl);
                if ($latestRaw !== null && $latest === null) $this->cache->delete($key);
                $now = $this->context->unixTime();
                if ($latest !== null && $now < $latest['fresh_until']) return $latest['value'];
                if ($latest !== null) $entry = $latest;

                try {
                    $value = $this->produceWeekDefaults();
                } catch (JmException $e) {
                    $now = $this->context->unixTime();
                    if (self::canUseStaleWeekDefaults($entry, $now, $staleTtl)) {
                        $this->recordWeekDefaultsStaleFallback();
                        return $entry['value'];
                    }
                    throw $e;
                }

                $fetchedAt = $this->context->unixTime();
                $newEntry = [
                    'value' => $value,
                    'fetched_at' => $fetchedAt,
                    'fresh_until' => $fetchedAt + $freshTtl,
                    'stale_until' => $fetchedAt + $freshTtl + $staleTtl,
                ];
                $physicalTtl = $freshTtl + $staleTtl;
                $this->cache->set($key, $newEntry, $physicalTtl);
                return $value;
            } finally {
                $this->cache->compareAndDelete($leaseKey, $token);
            }
        };

        $token = random_int(1, PHP_INT_MAX);
        if ($this->cache->tryAdd($leaseKey, $token, $lockTtl)) return $refreshAsOwner($token);

        $waitMs = min(
            self::envInt('JM_CACHE_FILL_WAIT_MS', JmConfig::DEFAULT_CACHE_FILL_WAIT_MS, 0, 5000),
            max(0, $this->context->budget()->remainingMs() - 100),
        );
        $waitStartedNs = hrtime(true);
        while ((int) floor((hrtime(true) - $waitStartedNs) / 1_000_000) < $waitMs) {
            usleep(random_int(20_000, 60_000));
            $latestRaw = $this->cache->get($key);
            $latest = self::validatedWeekDefaultsEntry($latestRaw, $freshTtl, $staleTtl);
            $now = $this->context->unixTime();
            if ($latest !== null && $now < $latest['fresh_until']) return $latest['value'];
            if ($this->cache->get($leaseKey) === null) { break; }
        }

        if ($this->context->budget()->remainingMs() <= 100) {
            throw new JmException('Weekly defaults refresh exceeded request deadline', 504);
        }
        $token = random_int(1, PHP_INT_MAX);
        if ($this->cache->tryAdd($leaseKey, $token, $lockTtl)) return $refreshAsOwner($token);
        throw new JmException('Weekly defaults refresh in progress', 504);
    }

    public function searchAlbums(string $query, int $page, string $order = 'mr'): JmListResult
    {
        $payload = $this->cacheThroughArray(
            'list-source',
            [
                'endpoint' => JmConfig::ENDPOINT_SEARCH,
                'upstream_page' => $page,
                'order' => $order,
                'query_sha256' => hash('sha256', $query),
            ],
            self::envInt('JM_SEARCH_CACHE_TTL', JmConfig::DEFAULT_SEARCH_CACHE_TTL, 0, 1800),
            static fn(array $value): bool => self::isPagedListPayload($value, 'content', true),
            fn(): array => self::normalizePagedListPayload(
                $this->api->callJson(JmConfig::ENDPOINT_SEARCH, [
                    'page' => (string) $page,
                    'o' => $order,
                    'search_query' => $query,
                ])['data'] ?? null,
                'content',
                true,
            ),
        );
        $items = $payload['content'];
        $total = (int) $payload['total'];

        $redirectAid = PayloadNormalizer::scalarString($payload['redirect_aid'] ?? '');
        if (empty($items) && $redirectAid !== '') {
            $items[] = ['id' => $redirectAid, 'name' => 'JM ' . $redirectAid];
            $total = max($total, 1);
        }

        return $this->searchListResultFromItems($page, $items, $total);
    }

    public function fetchScrambleId(string $photoId): string
    {
        $cacheKey = $this->cacheNamespace . 'scramble:' . md5($photoId);
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && preg_match('/^\d{1,20}$/', $cached) === 1) {
            return $cached;
        }
        if ($cached !== null) $this->cache->delete($cacheKey);

        $id = $this->api->fetchScrambleId($photoId);
        if (preg_match('/^\d{1,20}$/', $id) !== 1) {
            throw new MalformedChapterException('Malformed scramble id', 502);
        }
        $this->cache->set($cacheKey, $id, 3600);
        return $id;
    }

    public function fetchChapter(string $photoId, string $scrambleId): JmChapter
    {
        $ttl = self::chapterCacheTtl();
        $cacheKey = $this->cacheNamespace . self::chapterCacheKey($photoId, $scrambleId);
        if ($ttl > 0) {
            $cachedRaw = $this->cache->get($cacheKey);
            $cached = JmChapter::fromCachePayload($cachedRaw, $photoId, $scrambleId);
            if ($cached !== null) {
                return $cached;
            }
            if ($cachedRaw !== null) $this->cache->delete($cacheKey);
        }

        $resp = $this->api->callJson(JmConfig::ENDPOINT_CHAPTER, ['id' => $photoId]);
        if (!is_array($resp['data'] ?? null) || array_is_list($resp['data'])) {
            throw new MalformedChapterException('Malformed chapter payload', 502);
        }
        $chapter = JmChapter::fromApiResponse($resp['data'], $scrambleId, $photoId);
        if ($ttl > 0) $this->cache->set($cacheKey, $chapter->toCachePayload(), $ttl);
        return $chapter;
    }

    public function fetchReaderManifest(string $photoId): array
    {
        $ttl = self::chapterCacheTtl();
        $scrambleId = $this->fetchScrambleId($photoId);
        $cacheKey = $this->cacheNamespace . self::readerManifestCacheKey($photoId, $scrambleId);
        if ($ttl > 0) {
            $cachedRaw = $this->cache->get($cacheKey);
            $cached = self::validatedReaderManifest($cachedRaw, $photoId, $scrambleId, $this->cacheNamespace);
            if ($cached !== null) {
                return $cached;
            }
            if ($cachedRaw !== null) $this->cache->delete($cacheKey);
        }

        $chapter = $this->fetchChapter($photoId, $scrambleId);
        $images = [];
        foreach ($chapter->images as $image) {
            $page = (int) ($image['index'] ?? 0);
            if ($page < 1) continue;

            $filename = (string) ($image['filename'] ?? 'page.jpg');
            $segments = (int) ($image['decode_segments'] ?? 0);
            $mediaPath = (string) ($image['media_path'] ?? '');
            $images[] = [
                'index' => $page,
                'filename' => $filename,
                'media_path' => $mediaPath,
                'mime' => self::readerManifestImageMime($filename, $segments),
                'scramble_id' => $scrambleId,
                'decode_segments' => $segments,
                'cache_key' => $this->cacheNamespace . self::decodedPageCacheKey($photoId, $page, $image),
            ];
        }

        $manifest = [
            'schema' => 'reader-manifest-v2',
            'photo_id' => $photoId,
            'scramble_id' => $scrambleId,
            'page_count' => $chapter->pageCount,
            'images' => $images,
        ];

        if ($ttl > 0) $this->cache->set($cacheKey, $manifest, $ttl);

        return $manifest;
    }

    /** @return array{bytes:string, mime:string, codec:string, cache_hit:bool, singleflight:string, cache_store:string, apcu_free:?int, upstream_bytes:int, prefetch_manifest:array} */
    public function fetchDecodedPage(string $photoId, int $page, ?int $maxUpstreamBytes = null): array
    {
        $manifest = $this->fetchReaderManifest($photoId);
        $image = $manifest['images'][$page - 1] ?? null;

        if ($image === null) {
            throw new SecurityException("页码 {$page} 超出范围 1-{$manifest['page_count']}", 400);
        }

        $cacheKey = (string) ($image['cache_key'] ?? ($this->cacheNamespace . self::decodedPageCacheKey($photoId, $page, $image)));
        $cached = $this->cachedDecodedPage($cacheKey);
        if ($cached !== null) {
            return $cached + ['singleflight' => 'hit', 'prefetch_manifest' => $manifest];
        }

        $result = $this->singleFlight(
            $cacheKey,
            fn(): array => $this->materializeDecodedPage($cacheKey, $image, $maxUpstreamBytes),
        );
        $result['prefetch_manifest'] = $manifest;
        return $result;
    }

    public function maybePrefetchPages(
        string $photoId,
        int $page,
        bool $enabled,
        ?string $nextChapterId = null,
        ?array $currentManifest = null,
    ): string
    {
        $pages = self::envInt('JM_PREFETCH_PAGES', JmConfig::DEFAULT_PREFETCH_PAGES, 0, 30);
        $highPriorityPages = self::envInt(
            'JM_PREFETCH_HIGH_PRIORITY_PAGES',
            JmConfig::DEFAULT_PREFETCH_HIGH_PRIORITY_PAGES,
            0,
            10,
        );
        $maxActive = self::envInt('JM_PREFETCH_MAX_ACTIVE', JmConfig::DEFAULT_PREFETCH_MAX_ACTIVE, 0, 32);
        $wallBudgetMs = self::envInt(
            'JM_PREFETCH_WALL_BUDGET_MS',
            JmConfig::DEFAULT_PREFETCH_WALL_BUDGET_MS,
            0,
            60000,
        );
        $byteBudget = self::envInt(
            'JM_PREFETCH_BYTE_BUDGET',
            JmConfig::DEFAULT_PREFETCH_BYTE_BUDGET,
            0,
            512 * 1024 * 1024,
        );
        // Internal work before this shutdown callback is bounded by the shared
        // request budget plus an earlier deferred domain refresh. Client-side
        // response backpressure has no finite upper bound, so page/slot authority
        // is retained by process-lifetime flock handles from schedule through the
        // callback finally; APCu TTL entries are refreshable mirrors, not owners.
        $scheduleDelayMs = max(
            1,
            $this->context->budget()->remainingMs()
                + (self::envBool('JM_DOMAIN_REFRESH_DEFERRED', true) ? 10000 : 0),
        );

        $coordinator = new PrefetchCoordinator(
            $this->cache,
            static fn(): int => hrtime(true),
            static function (callable $callback): void { register_shutdown_function($callback); },
            function (array $candidate, int $remainingMs, int $remainingBytes): array {
                return $this->context->withPrefetchScope(
                    fn(): array => $this->context->budget()->withSecondaryCap(
                        $remainingMs,
                        fn(): array => $this->fetchDecodedPage(
                            (string) $candidate['photo_id'],
                            (int) $candidate['page'],
                            $remainingBytes,
                        ),
                    ),
                );
            },
            function (array $stats): void { $this->recordPrefetchStats($stats); },
            fn(): bool => $this->prefetchWaterlineOk(),
            null,
            function (array $event): void {
                if (!$this->context->isTestMode() || $this->context->testRunId() === '') return;
                $directory = trim((string) getenv('JM_TEST_PREFETCH_STATS_DIR'));
                if ($directory === '') return;
                PrefetchTestObserver::record($directory, $this->context->testRunId(), $event);
            },
        );

        $stats = $coordinator->schedule(
            $enabled && self::pageCacheTtl() > 0,
            $pages,
            $maxActive,
            $wallBudgetMs,
            $byteBudget,
            $scheduleDelayMs,
            function () use ($photoId, $page, $pages, $highPriorityPages, $nextChapterId, $currentManifest): array {
                if (!is_array($currentManifest)
                    || (string) ($currentManifest['photo_id'] ?? '') !== $photoId
                ) {
                    return [];
                }
                $pageCount = (int) ($currentManifest['page_count'] ?? 0);
                if ($pageCount < 1 || $page < 1 || $page > $pageCount) return [];

                $normalizedNextChapterId = normalizeNextChapterHint($nextChapterId);
                $nextChapterEligible = self::envBool('JM_NEXT_CHAPTER_PREFETCH', true)
                    && PrefetchCoordinator::isNearEnd(
                        $page,
                        $pageCount,
                        self::envInt(
                            'JM_NEXT_CHAPTER_PREFETCH_REMAINING',
                            JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_REMAINING,
                            0,
                            1000,
                        ),
                        self::envInt(
                            'JM_NEXT_CHAPTER_PREFETCH_PROGRESS',
                            JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_PROGRESS,
                            1,
                            100,
                        ),
                    );
                return PrefetchCoordinator::planCandidates(
                    $photoId,
                    $page,
                    $pageCount,
                    $pages,
                    $highPriorityPages,
                    $normalizedNextChapterId,
                    $nextChapterEligible,
                    self::envInt(
                        'JM_NEXT_CHAPTER_PREFETCH_PAGES',
                        JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_PAGES,
                        0,
                        5,
                    ),
                    function (string $candidatePhotoId, int $candidatePage) use ($currentManifest): bool {
                        if ((string) ($currentManifest['photo_id'] ?? '') !== $candidatePhotoId) return false;
                        $image = $currentManifest['images'][$candidatePage - 1] ?? null;
                        if (!is_array($image)) return true;
                        $cacheKey = (string) ($image['cache_key'] ?? '');
                        return $cacheKey !== '' && $this->cachedDecodedPage($cacheKey) !== null;
                    },
                );
            },
        );
        return $stats['scheduled'] ? 'scheduled' : (string) ($stats['skip_reason'] ?? 'none');
    }

    /** @return array{chapters: JmChapter[], errors: list<array{photo_id:string, error:string}>} */
    public function fetchChapters(array $photoIds): array
    {
        $chapters = [];
        $errors   = [];
        $firstFailure = null;
        foreach ($photoIds as $pid) {
            try {
                $scrambleId = $this->fetchScrambleId($pid);
                $chapters[] = $this->fetchChapter($pid, $scrambleId);
            } catch (MalformedChapterException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $firstFailure ??= $e;
                $errors[] = ['photo_id' => $pid, 'error' => 'Failed'];
            }
        }
        if ($chapters === [] && $firstFailure !== null) {
            if ($firstFailure instanceof JmException) throw $firstFailure;
            throw new JmException('All requested chapters failed', 502);
        }
        return ['chapters' => $chapters, 'errors' => $errors];
    }

    public function requestCount(): int { return $this->api->requestCount(); }

    public static function runtimeDiagnostics(MemoryCache $cache, ?RequestContext $context = null): array
    {
        $pageCacheTtl = self::pageCacheTtl();
        return [
            'singleflight' => [
                'enabled' => $cache->isAvailable() && $pageCacheTtl > 0,
                'lock_ttl_seconds' => self::envInt('JM_SINGLEFLIGHT_LOCK_TTL', JmConfig::DEFAULT_SINGLEFLIGHT_LOCK_TTL, 1, 300),
                'wait_ms' => self::envInt('JM_SINGLEFLIGHT_WAIT_MS', JmConfig::DEFAULT_SINGLEFLIGHT_WAIT_MS, 0, 30000),
            ],
            'prefetch' => [
                'default_pages' => self::envInt('JM_PREFETCH_PAGES', JmConfig::DEFAULT_PREFETCH_PAGES, 0, 30),
                'high_priority_pages' => self::envInt('JM_PREFETCH_HIGH_PRIORITY_PAGES', JmConfig::DEFAULT_PREFETCH_HIGH_PRIORITY_PAGES, 0, 10),
                'next_chapter_pages' => self::envInt('JM_NEXT_CHAPTER_PREFETCH_PAGES', JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_PAGES, 0, 5),
                'wall_budget_ms' => self::envInt('JM_PREFETCH_WALL_BUDGET_MS', JmConfig::DEFAULT_PREFETCH_WALL_BUDGET_MS, 0, 60000),
                'byte_budget' => self::envInt('JM_PREFETCH_BYTE_BUDGET', JmConfig::DEFAULT_PREFETCH_BYTE_BUDGET, 0, 512 * 1024 * 1024),
                'max_active' => self::envInt('JM_PREFETCH_MAX_ACTIVE', JmConfig::DEFAULT_PREFETCH_MAX_ACTIVE, 0, 32),
                'low_memory_policy' => 'stop-all-priorities',
                'aggregate' => self::prefetchAggregateDiagnostics($cache, $context),
            ],
            'cache_policy' => [
                'page_cache_enabled' => $cache->isAvailable() && $pageCacheTtl > 0,
                'page_cache_ttl_seconds' => $pageCacheTtl,
                'max_item_bytes' => self::pageCacheMaxItemBytes(),
                'page_cache_min_free_bytes' => self::envInt('JM_PAGE_CACHE_MIN_FREE_BYTES', JmConfig::DEFAULT_PAGE_CACHE_MIN_FREE_BYTES, 0, 512 * 1024 * 1024),
                'page_cache_min_free_ratio' => self::envInt('JM_PAGE_CACHE_MIN_FREE_RATIO', JmConfig::DEFAULT_PAGE_CACHE_MIN_FREE_RATIO, 0, 100),
                'prefetch_min_free_bytes' => self::envInt('JM_PREFETCH_MIN_FREE_BYTES', JmConfig::DEFAULT_PREFETCH_MIN_FREE_BYTES, 0, 512 * 1024 * 1024),
                'prefetch_min_free_ratio' => self::envInt('JM_PREFETCH_MIN_FREE_RATIO', JmConfig::DEFAULT_PREFETCH_MIN_FREE_RATIO, 0, 100),
            ],
            'metadata_cache' => self::metadataCacheDiagnostics($cache, $context),
            'upstream' => [
                'scramble_fallback_count' => (int) ($cache->get('diagnostics:scramble-fallback-count') ?? 0),
                'last_scramble_fallback' => $cache->get('diagnostics:last-scramble-fallback'),
            ],
        ];
    }

    private static function metadataCacheDiagnostics(MemoryCache $cache, ?RequestContext $context): array
    {
        $albumTtl = self::envInt('JM_ALBUM_CACHE_TTL', JmConfig::DEFAULT_ALBUM_CACHE_TTL, 0, 3600);
        $freshTtl = self::envInt(
            'JM_WEEK_DEFAULTS_CACHE_TTL',
            JmConfig::DEFAULT_WEEK_DEFAULTS_CACHE_TTL,
            0,
            86400,
        );
        $staleTtl = self::envInt(
            'JM_WEEK_DEFAULTS_STALE_TTL',
            JmConfig::DEFAULT_WEEK_DEFAULTS_STALE_TTL,
            0,
            604800,
        );
        $namespace = $context?->testCacheNamespace() ?? '';
        $now = $context?->unixTime() ?? time();

        if ($freshTtl <= 0) {
            $entryStatus = 'disabled';
        } elseif (!$cache->isAvailable()) {
            $entryStatus = 'unavailable';
        } else {
            $rawEntry = $cache->get(self::weekDefaultsCacheKey($namespace));
            $entry = self::validatedWeekDefaultsEntry($rawEntry, $freshTtl, $staleTtl);
            if ($rawEntry !== null && $entry === null) {
                $entryStatus = 'malformed';
            } elseif ($entry === null) {
                $entryStatus = 'missing';
            } elseif ($now < $entry['fresh_until']) {
                $entryStatus = 'fresh';
            } elseif ($staleTtl > 0 && $now <= $entry['stale_until']) {
                $entryStatus = 'stale';
            } else {
                $entryStatus = 'expired';
            }
        }

        $fallbackCount = $cache->isAvailable()
            ? (int) ($cache->get($namespace . 'diagnostics:week-defaults-stale-fallback-count:v1') ?? 0)
            : 0;
        return [
            'apcu_available' => $cache->isAvailable(),
            'album' => [
                'enabled' => $cache->isAvailable() && $albumTtl > 0,
                'ttl_seconds' => $albumTtl,
            ],
            'week_defaults' => [
                'enabled' => $cache->isAvailable() && $freshTtl > 0,
                'fresh_ttl_seconds' => $freshTtl,
                'stale_ttl_seconds' => $staleTtl,
                'entry_status' => $entryStatus,
                'stale_fallback_count' => $fallbackCount,
            ],
            'singleflight' => [
                'lock_ttl_seconds' => self::envInt(
                    'JM_CACHE_FILL_LOCK_TTL',
                    JmConfig::DEFAULT_CACHE_FILL_LOCK_TTL,
                    2,
                    90,
                ),
                'wait_ms' => self::envInt(
                    'JM_CACHE_FILL_WAIT_MS',
                    JmConfig::DEFAULT_CACHE_FILL_WAIT_MS,
                    0,
                    5000,
                ),
            ],
        ];
    }

    private static function prefetchAggregateDiagnostics(MemoryCache $cache, ?RequestContext $context): array
    {
        $namespace = $context?->testCacheNamespace() ?? '';
        $metrics = [];
        foreach (['events', 'scheduled', 'attempted', 'cache_hits', 'stored', 'bytes', 'wall_ms'] as $metric) {
            $metrics[$metric] = (int) ($cache->get($namespace . 'diagnostics:prefetch:' . $metric . ':v1') ?? 0);
        }
        $skips = [];
        foreach (PrefetchCoordinator::SKIP_REASONS as $reason) {
            $count = (int) ($cache->get($namespace . 'diagnostics:prefetch:skip:' . $reason . ':v1') ?? 0);
            if ($count > 0) $skips[$reason] = $count;
        }
        $metrics['skip_counts'] = $skips;
        return $metrics;
    }

    private function recordPrefetchStats(array $stats): void
    {
        $safe = PrefetchCoordinator::emptyStats();
        $safe['scheduled'] = ($stats['scheduled'] ?? false) === true;
        foreach (['attempted', 'cache_hits', 'stored', 'bytes', 'wall_ms'] as $metric) {
            $safe[$metric] = max(0, (int) ($stats[$metric] ?? 0));
        }
        $reason = $stats['skip_reason'] ?? null;
        $safe['skip_reason'] = is_string($reason) && in_array($reason, PrefetchCoordinator::SKIP_REASONS, true)
            ? $reason
            : null;

        error_log('[jm-api] prefetch_stats ' . json_encode(
            [
                'request_id' => $this->context->requestId(),
                'route' => 'prefetch',
                'stats' => $safe,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        if (!$this->cache->isAvailable()) return;

        $ttl = 86400;
        $namespace = $this->context->testCacheNamespace();
        if ($safe['scheduled']) $this->cache->increment($namespace . 'diagnostics:prefetch:scheduled:v1', $ttl);
        foreach (['attempted', 'cache_hits', 'stored', 'bytes', 'wall_ms'] as $metric) {
            if ($safe[$metric] > 0) {
                $this->cache->increment($namespace . 'diagnostics:prefetch:' . $metric . ':v1', $ttl, $safe[$metric]);
            }
        }
        if ($safe['skip_reason'] !== null) {
            $this->cache->increment($namespace . 'diagnostics:prefetch:skip:' . $safe['skip_reason'] . ':v1', $ttl);
        }
        // Publish the event count last so readers never treat a partially-updated aggregate as complete.
        $this->cache->increment($namespace . 'diagnostics:prefetch:events:v1', $ttl);
    }

    private function materializeDecodedPage(string $cacheKey, array $image, ?int $maxUpstreamBytes = null): array
    {
        $segments = (int) ($image['decode_segments'] ?? 0);
        $raw = $this->api->downloadImage((string) ($image['media_path'] ?? ''), $maxUpstreamBytes);
        try {
            $decoded = $this->imageDecoder->decode(
                $raw,
                $segments,
                self::imageMaxPixels(),
            );
            $validatedDigest = $this->validatedDecodedPageResultDigest($decoded);
        } catch (ImageProcessingException $error) {
            $this->recordImageDecodeFailure(strlen($raw), $error);
            throw new JmException('Image processing failed', 502);
        } catch (\Throwable $error) {
            $this->recordImageDecodeFailure(strlen($raw), new ImageProcessingException('Image decoder failure', [], $error));
            throw new JmException('Image processing failed', 502);
        }
        $this->recordImageDecodeSuccess($decoded);
        $result = [
            'bytes' => $decoded->bytes,
            'mime' => $decoded->mime,
            'codec' => $decoded->codec,
            'width' => $decoded->width,
            'height' => $decoded->height,
            'pixels' => $decoded->pixels,
            'cache_hit' => false,
            'upstream_bytes' => $decoded->inputBytes,
        ];
        $result['cache_store'] = $this->cacheDecodedPage($cacheKey, $result, $validatedDigest);
        $result['apcu_free'] = $this->apcuFreeBytes();
        return $result;
    }

    private function validatedDecodedPageResultDigest(ImageDecodeResult $decoded): string
    {
        $digest = hash('sha256', $decoded->bytes);
        $entry = self::validatedDecodedPageEnvelope([
            'schema' => 'decoded-page-v3',
            'bytes' => $decoded->bytes,
            'bytes_sha256' => $digest,
            'mime' => $decoded->mime,
            'codec' => $decoded->codec,
            'width' => $decoded->width,
            'height' => $decoded->height,
            'pixels' => $decoded->pixels,
        ], false);
        if ($entry === null) {
            throw new ImageProcessingException('Decoded image result failed structural validation', [
                'input_bytes' => $decoded->inputBytes,
                'width' => $decoded->width,
                'height' => $decoded->height,
                'pixels' => $decoded->pixels,
            ]);
        }

        try {
            $fullyDecoded = $this->imagePayloadValidator->hasCompleteDecodeAttestation(
                $entry['bytes'],
                $entry['width'],
                $entry['height'],
            ) || $this->imagePayloadValidator->isCompleteDecode(
                $entry['bytes'],
                $entry['width'],
                $entry['height'],
            );
        } catch (\Throwable $error) {
            throw new ImageProcessingException(
                'Decoded image result validation failed',
                [
                    'input_bytes' => $decoded->inputBytes,
                    'width' => $decoded->width,
                    'height' => $decoded->height,
                    'pixels' => $decoded->pixels,
                ],
                $error,
            );
        }
        if (!$fullyDecoded) {
            throw new ImageProcessingException('Decoded image result failed full validation', [
                'input_bytes' => $decoded->inputBytes,
                'width' => $decoded->width,
                'height' => $decoded->height,
                'pixels' => $decoded->pixels,
            ]);
        }
        return $digest;
    }

    private function recordImageDecodeSuccess(ImageDecodeResult $decoded): void
    {
        error_log('[jm-api] image_decode ' . json_encode([
            'request_id' => $this->context->requestId(),
            'status' => 'success',
            'input_bytes' => $decoded->inputBytes,
            'width' => $decoded->width,
            'height' => $decoded->height,
            'pixels' => $decoded->pixels,
            'decode_ms' => $decoded->decodeMs,
            'encode_ms' => $decoded->encodeMs,
            'peak_memory_bytes' => $decoded->peakMemoryBytes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function recordImageDecodeFailure(int $inputBytes, ImageProcessingException $error): void
    {
        $metrics = $error->metrics();
        error_log('[jm-api] image_decode ' . json_encode([
            'request_id' => $this->context->requestId(),
            'status' => 'failure',
            'input_bytes' => max(0, (int) ($metrics['input_bytes'] ?? $inputBytes)),
            'width' => max(0, (int) ($metrics['width'] ?? 0)),
            'height' => max(0, (int) ($metrics['height'] ?? 0)),
            'pixels' => max(0, (int) ($metrics['pixels'] ?? 0)),
            'decode_ms' => max(0, (int) ($metrics['decode_ms'] ?? 0)),
            'encode_ms' => max(0, (int) ($metrics['encode_ms'] ?? 0)),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function singleFlight(string $cacheKey, callable $producer): array
    {
        if (!$this->cache->isAvailable() || self::pageCacheTtl() <= 0) {
            $result = $producer();
            $result['singleflight'] = 'disabled';
            return $result;
        }

        $lockKey = 'lock:' . $cacheKey;
        $token = self::lockToken();
        $lockTtl = self::envInt('JM_SINGLEFLIGHT_LOCK_TTL', JmConfig::DEFAULT_SINGLEFLIGHT_LOCK_TTL, 1, 300);

        if ($this->cache->tryAdd($lockKey, $token, $lockTtl)) {
            try {
                $cached = $this->cachedDecodedPage($cacheKey);
                if ($cached !== null) {
                    $cached['singleflight'] = 'hit-after-wait';
                    return $cached;
                }

                $result = $producer();
                $result['singleflight'] = 'owner';
                return $result;
            } finally {
                $this->cache->compareAndDelete($lockKey, $token);
            }
        }

        $waitMs = min(
            self::envInt('JM_SINGLEFLIGHT_WAIT_MS', JmConfig::DEFAULT_SINGLEFLIGHT_WAIT_MS, 0, 30000),
            $this->context->budget()->remainingMs(),
        );
        $waitStart = microtime(true);
        while ((int) round((microtime(true) - $waitStart) * 1000) < $waitMs) {
            $remainingWaitMs = min(
                $waitMs - (int) round((microtime(true) - $waitStart) * 1000),
                $this->context->budget()->remainingMs(),
            );
            if ($remainingWaitMs <= 0) break;
            usleep(min(random_int(50_000, 150_000), $remainingWaitMs * 1000));
            $cached = $this->cachedDecodedPage($cacheKey);
            if ($cached !== null) {
                $cached['singleflight'] = 'hit-after-wait';
                return $cached;
            }
        }

        $result = $producer();
        $result['singleflight'] = 'timeout';
        return $result;
    }

    private function cachedDecodedPage(string $cacheKey): ?array
    {
        $ttl = self::pageCacheTtl();
        if ($ttl <= 0 || !$this->cache->isAvailable()) return null;
        $raw = $this->cache->get($cacheKey);
        $cached = $this->validatedDecodedPageCacheEntry($raw, $ttl);
        if ($cached === null) {
            if ($raw !== null) $this->cache->delete($cacheKey);
            return null;
        }

        return [
            'bytes' => $cached['bytes'],
            'mime' => $cached['mime'],
            'codec' => $cached['codec'],
            'width' => $cached['width'],
            'height' => $cached['height'],
            'pixels' => $cached['pixels'],
            'cache_hit' => true,
            'cache_store' => 'hit',
            'apcu_free' => $this->apcuFreeBytes(),
            'upstream_bytes' => 0,
        ];
    }

    private function cacheDecodedPage(string $cacheKey, array $result, string $validatedDigest): string
    {
        $ttl = self::pageCacheTtl();
        if ($ttl <= 0 || !$this->cache->isAvailable()) return 'disabled';

        $maxBytes = self::pageCacheMaxItemBytes();
        if ($maxBytes > 0 && strlen((string) ($result['bytes'] ?? '')) > $maxBytes) return 'skipped-too-large';
        if (!$this->pageCacheWaterlineOk()) return 'skipped-low-memory';

        $bytes = $result['bytes'] ?? null;
        $entry = self::validatedDecodedPageEnvelope([
            'schema' => 'decoded-page-v3',
            'bytes' => $result['bytes'] ?? null,
            'bytes_sha256' => is_string($bytes) ? hash('sha256', $bytes) : null,
            'mime' => $result['mime'] ?? null,
            'codec' => $result['codec'] ?? null,
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
            'pixels' => $result['pixels'] ?? null,
        ]);
        if ($entry === null || !hash_equals($validatedDigest, $entry['bytes_sha256'])) return 'skipped-invalid';

        $markerKey = $this->cacheNamespace . self::decodedPageAttestationKey($entry['bytes_sha256']);
        $stored = $this->cache->set($cacheKey, $entry, $ttl);
        if (!$stored) return 'disabled';
        try {
            $this->cache->set(
                $markerKey,
                self::decodedPageAttestation($entry['bytes_sha256']),
                $ttl,
            );
        } catch (\Throwable) {
            // The page remains usable; a later HIT will validate and retry the marker.
        }
        return 'stored';
    }

    private function prefetchWaterlineOk(): bool
    {
        return $this->memoryWaterlineOk(
            self::envInt('JM_PREFETCH_MIN_FREE_BYTES', JmConfig::DEFAULT_PREFETCH_MIN_FREE_BYTES, 0, 512 * 1024 * 1024),
            self::envInt('JM_PREFETCH_MIN_FREE_RATIO', JmConfig::DEFAULT_PREFETCH_MIN_FREE_RATIO, 0, 100),
            false
        );
    }

    private function pageCacheWaterlineOk(): bool
    {
        return $this->memoryWaterlineOk(
            self::envInt('JM_PAGE_CACHE_MIN_FREE_BYTES', JmConfig::DEFAULT_PAGE_CACHE_MIN_FREE_BYTES, 0, 512 * 1024 * 1024),
            self::envInt('JM_PAGE_CACHE_MIN_FREE_RATIO', JmConfig::DEFAULT_PAGE_CACHE_MIN_FREE_RATIO, 0, 100),
            true
        );
    }

    private function memoryWaterlineOk(int $minFreeBytes, int $minFreeRatio, bool $allowUnknown): bool
    {
        if (!$this->cache->isAvailable()) return false;

        $state = $this->cache->memoryState();
        $free = $state['free_memory_bytes'];
        $ratio = $state['free_ratio'];
        if ($free === null || $ratio === null) return $allowUnknown;

        return (int) $free >= $minFreeBytes && (int) $ratio >= $minFreeRatio;
    }

    private function apcuFreeBytes(): ?int
    {
        $free = $this->cache->memoryState()['free_memory_bytes'] ?? null;
        return is_int($free) ? $free : null;
    }

    private static function readerManifestCacheKey(string $photoId, string $scrambleId): string
    {
        return 'manifest:v2:' . hash('sha256', $photoId . ':' . $scrambleId);
    }

    private static function chapterCacheKey(string $photoId, string $scrambleId): string
    {
        return 'chapter:v2:' . hash('sha256', $photoId . ':' . $scrambleId);
    }

    private static function chapterCacheTtl(): int
    {
        return self::envInt('JM_CHAPTER_CACHE_TTL', JmConfig::DEFAULT_CHAPTER_CACHE_TTL, 0, 86400);
    }

    private static function pageCacheTtl(): int
    {
        return self::envInt('JM_PAGE_CACHE_TTL', JmConfig::DEFAULT_PAGE_CACHE_TTL, 0, 86400);
    }

    private static function imageMaxPixels(): int
    {
        $configured = self::envInt(
            'JM_IMAGE_MAX_PIXELS',
            JmConfig::DEFAULT_IMAGE_MAX_PIXELS,
            0,
            1_000_000_000,
        );
        return $configured > 0 ? $configured : JmConfig::DEFAULT_IMAGE_MAX_PIXELS;
    }

    /** @return null|array{schema:string,bytes:string,bytes_sha256:string,mime:string,codec:string,width:int,height:int,pixels:int} */
    private static function validatedDecodedPageEnvelope(
        mixed $value,
        bool $enforceCacheByteCap = true,
    ): ?array
    {
        if (!is_array($value)
            || !self::hasExactKeys($value, [
                'schema', 'bytes', 'bytes_sha256', 'mime', 'codec', 'width', 'height', 'pixels',
            ])
            || ($value['schema'] ?? null) !== 'decoded-page-v3'
            || !is_string($value['bytes'] ?? null)
            || $value['bytes'] === ''
            || !is_string($value['bytes_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $value['bytes_sha256']) !== 1
            || !is_string($value['mime'] ?? null)
            || !is_string($value['codec'] ?? null)
            || !is_int($value['width'] ?? null)
            || !is_int($value['height'] ?? null)
            || !is_int($value['pixels'] ?? null)
        ) {
            return null;
        }

        $maxBytes = self::pageCacheMaxItemBytes();
        if ($enforceCacheByteCap && $maxBytes > 0 && strlen($value['bytes']) > $maxBytes) return null;
        $actualDigest = hash('sha256', $value['bytes']);
        if (!hash_equals($actualDigest, $value['bytes_sha256'])) return null;
        $mime = strtolower($value['mime']);
        if ($value['mime'] !== $mime
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || $value['codec'] !== self::codecFromMime($mime)
        ) {
            return null;
        }

        $info = @getimagesizefromstring($value['bytes']);
        if (!is_array($info)) return null;
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $actualMime = strtolower((string) ($info['mime'] ?? ''));
        if ($actualMime !== $mime || !ImageContainerPolicy::isComplete($value['bytes'], $mime)) return null;
        try {
            $pixels = ImagePixelPolicy::checkedPixels($width, $height, self::imageMaxPixels());
        } catch (ImageProcessingException) {
            return null;
        }
        if ($value['width'] !== $width || $value['height'] !== $height || $value['pixels'] !== $pixels) {
            return null;
        }
        return $value;
    }

    /** @return array{schema:string,bytes_sha256:string} */
    private static function decodedPageAttestation(string $digest): array
    {
        return ['schema' => 'decoded-page-attestation-v2', 'bytes_sha256' => $digest];
    }

    private static function decodedPageAttestationKey(string $digest): string
    {
        return 'decoded-page-attestation:v2:' . $digest;
    }

    /** @return null|array{schema:string,bytes_sha256:string} */
    private static function validatedDecodedPageAttestation(mixed $value, string $digest): ?array
    {
        if (!is_array($value)
            || !self::hasExactKeys($value, ['schema', 'bytes_sha256'])
            || ($value['schema'] ?? null) !== 'decoded-page-attestation-v2'
            || !is_string($value['bytes_sha256'] ?? null)
            || !hash_equals($digest, $value['bytes_sha256'])
        ) {
            return null;
        }
        return $value;
    }

    /** @return null|array{schema:string,bytes:string,bytes_sha256:string,mime:string,codec:string,width:int,height:int,pixels:int} */
    private function validatedDecodedPageCacheEntry(mixed $value, int $ttl): ?array
    {
        $entry = self::validatedDecodedPageEnvelope($value);
        if ($entry === null) return null;

        $markerKey = $this->cacheNamespace . self::decodedPageAttestationKey($entry['bytes_sha256']);
        $marker = $this->cache->get($markerKey);
        if (self::validatedDecodedPageAttestation($marker, $entry['bytes_sha256']) !== null) {
            return $entry;
        }

        try {
            $fullyDecoded = $this->imagePayloadValidator->isCompleteDecode(
                $entry['bytes'],
                $entry['width'],
                $entry['height'],
            );
        } catch (\Throwable) {
            $fullyDecoded = false;
        }
        if (!$fullyDecoded) {
            $this->cache->delete($markerKey);
            return null;
        }

        try {
            $this->cache->set(
                $markerKey,
                self::decodedPageAttestation($entry['bytes_sha256']),
                $ttl,
            );
        } catch (\Throwable) {
            // Missing attestation only makes the next HIT validate again.
        }
        return $entry;
    }

    private static function decodedPageCacheKey(string $photoId, int $page, array $image): string
    {
        return 'page:v3:' . hash('sha256', implode(':', [
            $photoId,
            (string) $page,
            (string) ($image['filename'] ?? ''),
            (string) ($image['media_path'] ?? ''),
            (string) ($image['decode_segments'] ?? 0),
        ]));
    }

    private static function validatedReaderManifest(
        mixed $value,
        string $photoId,
        string $scrambleId,
        string $cacheNamespace,
    ): ?array
    {
        if (!is_array($value)
            || !self::hasExactKeys($value, ['schema', 'photo_id', 'scramble_id', 'page_count', 'images'])
            || ($value['schema'] ?? null) !== 'reader-manifest-v2'
            || ($value['photo_id'] ?? null) !== $photoId
            || ($value['scramble_id'] ?? null) !== $scrambleId
            || preg_match('/^\d{1,20}$/', $photoId) !== 1
            || preg_match('/^\d{1,20}$/', $scrambleId) !== 1
            || !is_int($value['page_count'] ?? null)
            || !is_array($value['images'] ?? null)
            || !array_is_list($value['images'])
            || $value['page_count'] !== count($value['images'])
        ) {
            return null;
        }

        foreach ($value['images'] as $offset => $image) {
            if (!is_array($image)
                || !self::hasExactKeys($image, [
                    'index', 'filename', 'media_path', 'mime', 'scramble_id', 'decode_segments', 'cache_key',
                ])
                || !is_int($image['index'] ?? null)
                || $image['index'] !== $offset + 1
                || !is_string($image['filename'] ?? null)
                || ChapterImagePolicy::canonicalMediaPath($photoId, $image['filename']) === null
                || !is_string($image['media_path'] ?? null)
                || $image['media_path'] !== ChapterImagePolicy::canonicalMediaPath($photoId, $image['filename'])
                || !is_string($image['mime'] ?? null)
                || !in_array($image['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
                || ($image['scramble_id'] ?? null) !== $scrambleId
                || !is_int($image['decode_segments'] ?? null)
                || $image['decode_segments'] !== ScrambleDecoder::segments(
                    $scrambleId,
                    $photoId,
                    $image['filename'],
                )
                || $image['mime'] !== self::readerManifestImageMime(
                    $image['filename'],
                    $image['decode_segments'],
                )
                || !is_string($image['cache_key'] ?? null)
                || $image['cache_key'] !== $cacheNamespace . self::decodedPageCacheKey($photoId, $offset + 1, $image)
            ) {
                return null;
            }
        }
        return $value;
    }

    /** @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function pageCacheMaxItemBytes(): int
    {
        return self::envInt(
            'JM_PAGE_CACHE_MAX_ITEM_BYTES',
            self::envInt('JM_PAGE_CACHE_MAX_BYTES', JmConfig::DEFAULT_PAGE_CACHE_MAX_ITEM_BYTES, 0, 512 * 1024 * 1024),
            0,
            512 * 1024 * 1024
        );
    }

    private static function lockToken(): int
    {
        return random_int(1, PHP_INT_MAX);
    }

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        if (!preg_match('/^\d+$/', trim((string) $raw))) return $default;
        return min($max, max($min, (int) $raw));
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        $normalized = strtolower(trim((string) $raw));
        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) return false;
        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) return true;
        return $default;
    }

    private function listResultFromItems(string $mode, int $page, array $items, int $total): JmListResult
    {
        $sourceItemCount = self::payloadItemCount($items);
        $mapped = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $mappedItem = self::listItemFromPayload($item);
                if ($mappedItem !== null) $mapped[] = $mappedItem;
            }
        }

        $loaded = ($page - 1) * self::LOCAL_LIST_PAGE_SIZE + $sourceItemCount;
        $hasNextPage = $total > 0 ? $loaded < $total : $sourceItemCount > 0;

        return new JmListResult($mode, $page, $total, $hasNextPage, $mapped);
    }

    private function searchListResultFromItems(int $page, array $items, int $total): JmListResult
    {
        $sourceItemCount = self::payloadItemCount($items);
        $mapped = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $mappedItem = self::listItemFromPayload($item);
                if ($mappedItem !== null) $mapped[] = $mappedItem;
            }
        }

        $loaded = ($page - 1) * self::SOURCE_LIST_PAGE_SIZE + $sourceItemCount;
        $hasNextPage = ($sourceItemCount >= self::SOURCE_LIST_PAGE_SIZE || count($mapped) >= self::SOURCE_LIST_PAGE_SIZE)
            && ($total <= 0 || $loaded < $total);

        return new JmListResult('search', $page, $total, $hasNextPage, $mapped);
    }

    /** @return array{source_page:int, offset:int} */
    private static function sourceListWindow(int $page): array
    {
        $start = (max(1, $page) - 1) * self::LOCAL_LIST_PAGE_SIZE;
        return [
            'source_page' => intdiv($start, self::SOURCE_LIST_PAGE_SIZE),
            'offset' => $start % self::SOURCE_LIST_PAGE_SIZE,
            'source_page_size' => self::SOURCE_LIST_PAGE_SIZE,
        ];
    }

    /** @return array{source_page:int, offset:int, source_page_size:int} */
    private static function promoteListWindow(int $page): array
    {
        $start = (max(1, $page) - 1) * self::LOCAL_LIST_PAGE_SIZE;
        return [
            'source_page' => intdiv($start, self::PROMOTE_SOURCE_PAGE_SIZE),
            'offset' => $start % self::PROMOTE_SOURCE_PAGE_SIZE,
            'source_page_size' => self::PROMOTE_SOURCE_PAGE_SIZE,
        ];
    }

    /** @param array{source_page:int, offset:int, source_page_size?:int, source_has_more?:bool} $window */
    private function windowedListResultFromItems(string $mode, int $page, array $items, int $total, array $window): JmListResult
    {
        $sourcePageSize = max(1, (int) ($window['source_page_size'] ?? self::SOURCE_LIST_PAGE_SIZE));
        $sourceItemCount = self::payloadItemCount($items);
        $mapped = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $mappedItem = self::listItemFromPayload($item);
                if ($mappedItem !== null) $mapped[] = $mappedItem;
            }
        }

        $sliced = array_slice($mapped, $window['offset'], self::LOCAL_LIST_PAGE_SIZE);
        $hasNextPage = $total > 0
            ? ($page * self::LOCAL_LIST_PAGE_SIZE) < $total
            : (
                count($mapped) > $window['offset'] + self::LOCAL_LIST_PAGE_SIZE
                || (array_key_exists('source_has_more', $window) ? (bool) $window['source_has_more'] : $sourceItemCount >= $sourcePageSize)
            );

        return new JmListResult($mode, $page, $total, $hasNextPage, $sliced);
    }

    private static function listItemFromPayload(array $item): ?JmListItem
    {
        $mappedItem = JmListItem::fromPayload($item);
        if ($mappedItem->id === '') return null;
        return $mappedItem;
    }

    private static function payloadItemCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (is_array($item)) $count++;
        }
        return $count;
    }

    private static function intFromPayload(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_float($value)) return (int) $value;
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) return (int) trim($value);
        return 0;
    }

    private static function imageExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            default => 'jpg',
        };
    }

    private static function imageMime(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private static function readerManifestImageMime(string $filename, int $segments): string
    {
        $extension = self::imageExtension($filename);
        return ($segments > 0 && $extension !== 'gif')
            ? ScrambleDecoder::preferredDecodedMime()
            : self::imageMime($extension);
    }

    private static function codecFromMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/jpeg', 'image/jpg' => 'jpeg',
            default => 'original',
        };
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Security Manager
// ═════════════════════════════════════════════════════════════════════════════

final class TrustedProxyPolicy
{
    /** @param list<array{network:string,prefix:int,text:string}> $entries */
    private function __construct(private array $entries) {}

    /** @param list<string> $cidrs */
    public static function fromCidrs(array $cidrs): self
    {
        $entries = [];
        foreach ($cidrs as $cidr) {
            if (!is_string($cidr)) continue;
            $cidr = trim($cidr);
            if (preg_match('/^([^\s\/]+)\/(\d{1,3})$/', $cidr, $match) !== 1) continue;
            $address = $match[1];
            if (filter_var($address, FILTER_VALIDATE_IP) === false) continue;
            $packed = @inet_pton($address);
            if (!is_string($packed)) continue;
            $maxPrefix = strlen($packed) * 8;
            $prefix = (int) $match[2];
            if ($prefix < 0 || $prefix > $maxPrefix) continue;
            $entries[] = ['network' => $packed, 'prefix' => $prefix, 'text' => $cidr];
        }
        return new self($entries);
    }

    /** @param array<string,mixed> $query */
    public static function fromEnvironment(
        ?RequestContext $context = null,
        array $query = [],
        string $remoteAddress = '',
    ): self
    {
        $raw = trim((string) getenv('JM_TRUSTED_PROXY_CIDRS'));
        $cidrs = $raw === '' ? [] : array_map('trim', explode(',', $raw));
        if ($context?->isTestMode() === true && ($query['test_trusted_proxy'] ?? null) === '1') {
            $cidrs[] = '127.0.0.0/8';
            $cidrs[] = '::1/128';
            if (filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $cidrs[] = $remoteAddress . '/32';
            } elseif (filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $cidrs[] = $remoteAddress . '/128';
            }
        }
        return self::fromCidrs($cidrs);
    }

    /** @return list<string> */
    public function cidrs(): array
    {
        return array_map(static fn(array $entry): string => $entry['text'], $this->entries);
    }

    public function isTrustedProxy(string $remoteAddress): bool
    {
        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) return false;
        $packed = @inet_pton($remoteAddress);
        if (!is_string($packed)) return false;

        foreach ($this->entries as $entry) {
            $network = $entry['network'];
            if (strlen($network) !== strlen($packed)) continue;
            $prefix = $entry['prefix'];
            $wholeBytes = intdiv($prefix, 8);
            if ($wholeBytes > 0 && substr($network, 0, $wholeBytes) !== substr($packed, 0, $wholeBytes)) continue;
            $remainingBits = $prefix % 8;
            if ($remainingBits === 0) return true;
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($network[$wholeBytes]) & $mask) === (ord($packed[$wholeBytes]) & $mask)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $server */
    public function clientIp(array $server): string
    {
        $remoteAddress = is_string($server['REMOTE_ADDR'] ?? null)
            ? $server['REMOTE_ADDR']
            : '';
        $validRemote = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? $remoteAddress
            : '127.0.0.1';
        if (!$this->isTrustedProxy($remoteAddress)) return $validRemote;

        $xff = self::safeHeader($server['HTTP_X_FORWARDED_FOR'] ?? null);
        if ($xff !== null) {
            foreach (explode(',', $xff) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
            }
        }
        foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $key) {
            $candidate = self::safeHeader($server[$key] ?? null);
            if ($candidate !== null
                && !str_contains($candidate, ',')
                && filter_var(trim($candidate), FILTER_VALIDATE_IP) !== false
            ) {
                return trim($candidate);
            }
        }
        return $validRemote;
    }

    /** @param array<string,mixed> $server */
    public function requestBaseUrl(array $server): string
    {
        $directProto = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off'
            ? 'https'
            : 'http';
        $directHost = self::normalizeHost($server['HTTP_HOST'] ?? null) ?? 'localhost';
        $proto = $directProto;
        $host = $directHost;

        $remoteAddress = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        if ($this->isTrustedProxy($remoteAddress)) {
            $forwardedProto = self::firstForwardedValue($server['HTTP_X_FORWARDED_PROTO'] ?? null);
            if (in_array(strtolower((string) $forwardedProto), ['http', 'https'], true)) {
                $proto = strtolower((string) $forwardedProto);
            }
            $forwardedHost = self::firstForwardedValue($server['HTTP_X_FORWARDED_HOST'] ?? null);
            $normalizedForwardedHost = self::normalizeHost($forwardedHost);
            if ($normalizedForwardedHost !== null) $host = $normalizedForwardedHost;
        }

        $scriptName = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $lastSlash = strrpos($scriptName, '/');
        $basePath = $lastSlash === false ? '' : rtrim(substr($scriptName, 0, $lastSlash), '/');
        if ($basePath === '.' || $basePath === '/') $basePath = '';
        return $proto . '://' . $host . $basePath;
    }

    private static function firstForwardedValue(mixed $value): ?string
    {
        $safe = self::safeHeader($value);
        if ($safe === null) return null;
        $first = trim(explode(',', $safe, 2)[0]);
        return $first !== '' ? $first : null;
    }

    private static function normalizeHost(mixed $value): ?string
    {
        $hostValue = self::safeHeader($value);
        if ($hostValue === null) return null;
        $hostValue = strtolower(trim($hostValue));
        if ($hostValue === ''
            || preg_match('/[\s\/@?#\\\\]/', $hostValue) === 1
            || str_contains($hostValue, ',')
        ) {
            return null;
        }
        $parts = parse_url('http://' . $hostValue);
        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            return null;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '') return null;
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            return null;
        }
        $normalized = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) return null;
            $normalized .= ':' . $port;
        }
        return $normalized;
    }

    private static function safeHeader(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048) return null;
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) return null;
        return $value;
    }
}

final class SecurityManager
{
    private RedisStore $store;
    private string $clientIp;

    /** @param array<string,mixed>|null $server @param array<string,mixed>|null $query */
    public function __construct(
        ?RedisStore $store = null,
        ?TrustedProxyPolicy $trustedProxyPolicy = null,
        ?array $server = null,
        ?array $query = null,
    )
    {
        $this->store = $store ?? new RedisStore();
        $server ??= $_SERVER;
        $query ??= $_GET;
        $trustedProxyPolicy ??= TrustedProxyPolicy::fromEnvironment(
            RequestContext::current(),
            $query,
            is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '',
        );
        $this->clientIp = $trustedProxyPolicy->clientIp($server);
        if (RequestContext::current()?->isTestMode() === true && !headers_sent()) {
            header('X-JM-Test-Client-Ip: ' . $this->clientIp);
        }
    }

    public function clientIpForDiagnostics(): string { return $this->clientIp; }

    // ── Rate limiting (CC flood) ──

    /** Throws on rate limit exceeded. */
    public function enforceRateLimit(): void
    {
        // Check if IP is banned
        if ($this->store->isBanned($this->clientIp)) {
            throw new SecurityException('请求过于频繁，请稍后重试', 429);
        }

        [$allowed, $remaining, $retry] = $this->store->checkRate(
            $this->clientIp,
            JmConfig::RATE_WINDOW,
            JmConfig::RATE_MAX_REQUESTS
        );

        if (!$allowed) {
            // Ban after repeated violations
            $violations = $this->store->incr("violations:{$this->clientIp}", 300);
            if ($violations >= 3) {
                $this->store->ban($this->clientIp, JmConfig::RATE_PENALTY);
            }
            throw new SecurityException(
                '请求过于频繁，请 ' . $retry . ' 秒后重试',
                429,
                ['retry_after' => $retry]
            );
        }
    }

    // ── ID brute force detection ──

    /** Detect rapid iteration through JM IDs from same IP. */
    public function checkBruteForce(string $jmid): void
    {
        if (!$this->store->isAvailable()) return;

        // Count distinct jmids accessed by this IP in 10 minutes
        $key = "jmids:{$this->clientIp}";
        $count = $this->store->addToExpiringSetAndCount($key, $jmid, 600);

        if ($count > 100) {
            $this->store->ban($this->clientIp, 600);
            throw new SecurityException('异常访问模式，已临时限制', 429);
        }
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Input validation
// ═════════════════════════════════════════════════════════════════════════════

final class InputValidator
{
    /**
     * Validate and sanitize jmid.
     * Only allows digits, strips prefixes and URL wrappers.
     */
    public static function parseJmId(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            throw new SecurityException('Invalid JM ID', 400);
        }
        $raw = trim((string) $raw);

        // Reject overly long or obviously malicious input
        if (strlen($raw) > 200) {
            throw new SecurityException('Invalid ID format', 400);
        }

        // Strip "JM" prefix
        if (preg_match('/^JM(\d{1,20})$/i', $raw, $m)) return self::normalizeJmId($m[1]);

        // Extract from URL
        if (preg_match('~/(?:album|photo)s?/(\d{1,20})(?!\d)(?=[/?#]|$)~i', $raw, $m)) {
            return self::normalizeJmId($m[1]);
        }
        if (preg_match('/[?&](?:jmid|id)=(\d{1,20})(?!\d)(?=[&#]|$)/i', $raw, $m)) {
            return self::normalizeJmId($m[1]);
        }

        // Pure digits
        if (preg_match('/^(\d{1,20})$/', $raw, $m)) return self::normalizeJmId($m[1]);

        throw new SecurityException('Invalid JM ID', 400);
    }

    private static function normalizeJmId(string $id): string
    {
        if (strlen($id) > JmConfig::JMID_MAX_LENGTH) {
            throw new SecurityException('Invalid JM ID', 400);
        }
        return $id;
    }

    /** Validate chapter parameter. */
    public static function validateChapterParam(string $param, JmAlbum $album): array
    {
        // "all"
        if (strcasecmp($param, 'all') === 0) {
            $ids = $album->allPhotoIds();
            if (count($ids) > JmConfig::MAX_CHAPTERS) {
                throw new SecurityException(
                    '章节过多（' . count($ids) . '），单次最多 ' . JmConfig::MAX_CHAPTERS . ' 章',
                    400
                );
            }
            return $ids;
        }

        // "@N" index
        if (str_starts_with($param, '@')) {
            if (!preg_match('/^@\d+$/', $param)) {
                throw new SecurityException('无效的章节参数', 400);
            }
            $idx = (int) substr($param, 1);
            $max = count($album->episodes);
            if ($idx < 1 || $idx > $max) {
                throw new SecurityException("章节序号 {$idx} 超出范围 1-{$max}", 400);
            }
            return [$album->episodes[$idx - 1]['photo_id']];
        }

        // "id1,id2,id3"
        if (str_contains($param, ',')) {
            $ids = array_map('trim', explode(',', $param));
            if (count($ids) > JmConfig::MAX_CHAPTERS) {
                throw new SecurityException(
                    '单次最多 ' . JmConfig::MAX_CHAPTERS . ' 章',
                    400
                );
            }
            $valid   = [];
            $seen    = [];
            $photoIds = array_column($album->episodes, 'photo_id');
            foreach ($ids as $id) {
                if (preg_match('/^\d+$/', $id) !== 1 || !in_array($id, $photoIds, true)) {
                    throw new SecurityException('无效或不属于该专辑的章节 ID', 400);
                }
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $valid[] = $id;
                }
            }
            return $valid;
        }

        // Single ID
        if (preg_match('/^\d+$/', $param)) {
            $photoIds = array_column($album->episodes, 'photo_id');
            if (in_array($param, $photoIds, true)) return [$param];
        }

        throw new SecurityException('无效的章节参数', 400);
    }

    public static function validatePageParam(string $param): int
    {
        $param = trim($param);

        if (!preg_match('/^\d{1,4}$/', $param)) {
            throw new SecurityException('无效的页码参数', 400);
        }

        $page = (int) $param;
        if ($page < 1) {
            throw new SecurityException('页码必须大于 0', 400);
        }

        return $page;
    }

    public static function validateNumericChapterId(string $param): string
    {
        $param = trim($param);
        if (!preg_match('/^\d{1,20}$/', $param)) {
            throw new SecurityException('Invalid chapter ID', 400);
        }
        return $param;
    }

    /** Sanitize output: strip null bytes, control chars from strings. */
    public static function sanitizeString(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Exceptions
// ═════════════════════════════════════════════════════════════════════════════

class SecurityException extends \RuntimeException
{
    public function __construct(string $msg, int $code, private array $extra = [])
    {
        parent::__construct($msg, $code);
    }
    public function extra(): array { return $this->extra; }
}

class JmException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0)
    {
        parent::__construct($message, $code);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Helpers
// ═════════════════════════════════════════════════════════════════════════════

function elapsedMs(float $startNs): int
{
    return (int) round((hrtime(true) - $startNs) / 1_000_000);
}

function jmApiVersion(): string
{
    $version = getenv('JM_API_VERSION');
    return is_string($version) && trim($version) !== '' ? trim($version) : JmConfig::APP_VERSION;
}

function configString(string $name, string $default): string
{
    $raw = getenv($name);
    return $raw === false || trim((string) $raw) === '' ? $default : trim((string) $raw);
}

function requestBaseUrl(): string
{
    $trustedProxyPolicy = TrustedProxyPolicy::fromEnvironment(
        RequestContext::current(),
        $_GET,
        is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
    );
    return $trustedProxyPolicy->requestBaseUrl($_SERVER);
}

function buildDecodedPageUrl(string $baseUrl, string $albumId, string $chapterId, int $page, ?string $nextChapterId = null): string
{
    $params = [
        'jmid'    => $albumId,
        'chapter' => $chapterId,
        'page'    => (string) $page,
    ];
    if ($nextChapterId !== null && $nextChapterId !== '') {
        $params['next_chapter'] = $nextChapterId;
    }

    return rtrim($baseUrl, '/') . '/?' . http_build_query($params);
}

function isSafeCoverString(string $candidate): bool
{
    return preg_match('/\p{Cc}/u', $candidate) === 0;
}

function validatedAbsoluteCoverUrl(string $candidate): ?string
{
    if ($candidate === '' || !isSafeCoverString($candidate)) return null;
    if (filter_var($candidate, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($candidate);
    if (!is_array($parts)) return null;
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
    if (preg_match('/[\x00-\x20\x7F]/', $host) === 1) return null;
    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) return null;
    return $candidate;
}

function buildCoverUrl(string $albumId, string $image): string
{
    if (!isSafeCoverString($image)) $image = '';
    $absoluteCover = validatedAbsoluteCoverUrl($image);
    if ($absoluteCover !== null) return $absoluteCover;

    $image = trim($image);

    $epoch = configString('JM_CDN_EPOCH', '1');
    $index = ((int) sprintf('%u', crc32($albumId . ':' . $epoch))) % count(JmConfig::CDN_DOMAINS);
    $cdn = JmConfig::CDN_DOMAINS[$index];
    if ($image !== '') {
        if (str_starts_with($image, '/')) {
            return "https://{$cdn}{$image}";
        }
        if (str_starts_with($image, 'media/')) {
            return "https://{$cdn}/{$image}";
        }
    }

    return "https://{$cdn}/media/albums/{$albumId}_3x4.jpg";
}

function normalizeListPage(mixed $value): int
{
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') return 1;
    if (!preg_match('/^\d{1,5}$/', $raw)) {
        throw new SecurityException('无效的分页参数', 400);
    }
    return max(1, (int) $raw);
}

function normalizeListMode(mixed $value): string
{
    $mode = strtolower(trim(is_scalar($value) ? (string) $value : 'popular'));
    return match ($mode) {
        '', 'popular', 'hot', 'rank', 'ranking' => 'popular',
        'latest', 'new', 'updates' => 'latest',
        'promote', 'promotelist', 'recommend' => 'promote',
        'weekly', 'week' => 'weekly',
        default => throw new SecurityException('无效的列表模式', 400),
    };
}

function normalizePromoteSectionId(mixed $value): int
{
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') return 0;
    if (!preg_match('/^\d{1,10}$/', $raw)) {
        throw new SecurityException('无效的推荐分区', 400);
    }
    return (int) $raw;
}

function normalizeOptionalWeeklyId(mixed $value, bool $allowSlug = false): ?string
{
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') return null;
    $valid = $allowSlug
        ? preg_match('/^(?:\d{1,20}|[A-Za-z][A-Za-z0-9_-]{0,31})$/', $raw) === 1
        : preg_match('/^\d{1,20}$/', $raw) === 1;
    if (!$valid) {
        throw new SecurityException('无效的每周推荐筛选参数', 400);
    }
    return $raw;
}

function normalizeSearchQuery(mixed $value): string
{
    $query = trim(is_scalar($value) ? (string) $value : '');
    if ($query === '') {
        throw new SecurityException('缺少搜索关键词', 400);
    }
    if (mb_strlen($query, 'UTF-8') > 100) {
        throw new SecurityException('搜索关键词过长', 400);
    }
    return InputValidator::sanitizeString($query);
}

function normalizeSearchOrder(mixed $value): string
{
    $order = strtolower(trim(is_scalar($value) ? (string) $value : 'mr'));
    return in_array($order, ['mr', 'mv', 'mp', 'tf', 'new'], true) ? $order : 'mr';
}

function normalizeCatalogOrder(mixed $value): string
{
    $order = strtolower(trim(is_scalar($value) ? (string) $value : 'new'));
    return in_array($order, ['new', 'mv', 'tf'], true) ? $order : 'new';
}

function isPrefetchEnabled(mixed $value): bool
{
    $raw = strtolower(trim(is_scalar($value) ? (string) $value : '1'));
    return !in_array($raw, ['0', 'false', 'off', 'no'], true);
}

function normalizeNextChapterHint(mixed $value): ?string
{
    if (!is_scalar($value)) return null;
    $raw = trim((string) $value);
    return preg_match('/^\d{1,20}$/', $raw) === 1 ? $raw : null;
}

function apiDomainDiagnostics(MemoryCache $cache, RequestContext $context): array
{
    $resolver = new DomainResolver($context, $cache);
    $domains = $resolver->resolveForRequest();
    $health = DomainHealth::diagnostics($domains);
    return array_merge($health, $resolver->diagnostics(), ['health' => $health]);
}

function isDirectNumericChapterImageRequest(mixed $chapterParam, mixed $pageParam): bool
{
    // numeric direct image requests validate chapter id format and page bounds, not album membership
    if (!is_scalar($chapterParam) || !is_scalar($pageParam)) return false;
    $chapter = trim((string) $chapterParam);
    $page = trim((string) $pageParam);
    return $chapter !== '' && $page !== '' && preg_match('/^\d{1,20}$/', $chapter) === 1;
}

/** @return never */
function sendBinaryImage(string $bytes, string $mime, bool $cacheHit = false, string $codec = 'original', array $diagnostics = []): void
{
    http_response_code(200);
    RequestContext::current()?->emitResponseHeaders();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=86400');
    header('X-JM-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
    header('X-JM-Image-Codec: ' . $codec);
    header('X-JM-Singleflight: ' . (string) ($diagnostics['singleflight'] ?? 'disabled'));
    header('X-JM-Prefetch: ' . (string) ($diagnostics['prefetch'] ?? 'none'));
    header('X-JM-Cache-Store: ' . (string) ($diagnostics['cache_store'] ?? ($cacheHit ? 'hit' : 'disabled')));
    if (isset($diagnostics['apcu_free']) && $diagnostics['apcu_free'] !== null) {
        header('X-JM-APCu-Free: ' . (string) $diagnostics['apcu_free']);
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo $bytes;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    exit;
}

/** @return never */
function sendJson(array $data, bool $minify): void
{
    $code = ($data['code'] ?? 200);
    $body = json_encode($data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | ($minify ? 0 : JSON_PRETTY_PRINT));
    if (!is_string($body)) {
        $code = 500;
        $body = '{"code":500,"success":false,"error":"服务器内部错误"}';
    }
    http_response_code($code);
    RequestContext::current()?->emitResponseHeaders();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    // Prevent framing (clickjacking)
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    if ($code === 429) {
        header('Retry-After: ' . ($data['retry_after'] ?? 60));
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    exit;
}

/** @return array<string,mixed> */
function safeErrorExtras(int $code, array $extra): array
{
    if ($code >= 500) {
        return array_intersect_key($extra, array_flip(['retry_after', 'request_id']));
    }
    return $extra;
}

/** @return never */
function sendError(int $code, string $msg, array $extra = []): void
{
    RequestContext::current()?->emitResponseHeaders();
    // Don't leak internal details in production
    $safeMsg = ($code >= 500) ? '服务器内部错误' : $msg;
    $payload = ['code' => $code, 'success' => false, 'error' => $safeMsg];
    if ($extra) {
        if ($code >= 500) {
            $extra = safeErrorExtras($code, $extra);
        }
        $payload = array_merge($payload, $extra);
    }
    sendJson($payload, false);
}


// ═════════════════════════════════════════════════════════════════════════════
// Entry Point
// ═════════════════════════════════════════════════════════════════════════════

if (defined('JM_API_LIBRARY_ONLY') && JM_API_LIBRARY_ONLY === true) {
    return;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(300);
ignore_user_abort(true);
header_remove('X-Powered-By');
header('X-JM-API-Version: ' . jmApiVersion());
$bootstrapContext = RequestContext::fromGlobals('bootstrap');

// Check extensions
foreach (['curl', 'openssl', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        sendError(500, 'Server misconfigured');
    }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
$requestMethod = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? '')));
if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($requestMethod !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendError(405, '仅支持 GET 请求');
}

// ── Health check (no rate limit) ──

if (($_GET['health'] ?? '') === '1') {
    $healthContext = RequestContext::fromGlobals('health');
    $memoryCache = new MemoryCache();
    $runtimeDiagnostics = JmService::runtimeDiagnostics($memoryCache, $healthContext);
    $report = [
        'app_version'  => jmApiVersion(),
        'php'          => PHP_VERSION,
        'apcu'         => $memoryCache->isAvailable(),
        'apcu_details' => $memoryCache->diagnostics(),
        'singleflight' => $runtimeDiagnostics['singleflight'],
        'prefetch'     => $runtimeDiagnostics['prefetch'],
        'cache_policy' => $runtimeDiagnostics['cache_policy'],
        'metadata_cache' => $runtimeDiagnostics['metadata_cache'],
        'upstream'     => $runtimeDiagnostics['upstream'],
        'domains'      => apiDomainDiagnostics($memoryCache, $healthContext),
        'test_mode'    => RequestContext::testModeEnabled(),
        'test_cache_scoped' => $healthContext->isTestMode() && $healthContext->testRunId() !== '',
        'test_api_source' => JmApiClient::testApiSource(),
        'redis'        => (new RedisStore())->isAvailable(),
        'memory'       => memory_get_usage(true),
    ];
    sendJson(['code' => 200, 'success' => true, 'version' => jmApiVersion(), 'diagnostics' => $report], false);
}

// ── Security: rate limit first ──

$requestRoute = isset($_GET['search'])
    ? 'search'
    : (isset($_GET['list']) ? 'list' : (isset($_GET['page']) ? 'image' : 'chapter'));
$requestContext = RequestContext::fromGlobals($requestRoute);

$security = new SecurityManager();

try {
    $security->enforceRateLimit();
} catch (SecurityException $e) {
    sendError(429, '请求过于频繁', ['retry_after' => $e->extra()['retry_after'] ?? 60]);
}

// ── Parse input ──

$minify = ($_GET['format'] ?? '') === 'min';
$listParam = $_GET['list'] ?? null;
$searchParam = $_GET['search'] ?? null;

if ($listParam !== null || $searchParam !== null) {
    $startMs = hrtime(true);
    try {
        $service = new JmService($requestContext);
        $page = normalizeListPage($_GET['page'] ?? '1');
        if ($searchParam !== null) {
            $query = normalizeSearchQuery($searchParam);
            $order = normalizeSearchOrder($_GET['order'] ?? $_GET['o'] ?? 'mr');
            $result = $service->searchAlbums($query, $page, $order);
        } else {
            $mode = normalizeListMode($listParam);
            $result = match ($mode) {
                'latest' => $service->fetchLatestList($page),
                'promote' => $service->fetchPromoteList($page, normalizePromoteSectionId($_GET['section'] ?? $_GET['id'] ?? '0')),
                'weekly' => $service->fetchWeeklyList(
                    $page,
                    normalizeOptionalWeeklyId($_GET['category_id'] ?? $_GET['category'] ?? null),
                    normalizeOptionalWeeklyId($_GET['type_id'] ?? $_GET['type'] ?? null, true),
                ),
                default => $service->fetchPopularList(
                    $page,
                    normalizeCatalogOrder($_GET['order'] ?? $_GET['o'] ?? 'new'),
                ),
            };
        }

        $data = $result->toArray();
        $requestDiagnostics = $requestContext->diagnostics();
        $data['elapsed_ms'] = elapsedMs($startMs);
        $data['api_calls'] = $service->requestCount();
        $data['source_cache_hits'] = $requestDiagnostics['source_cache_hits'];
        $data['source_cache_misses'] = $requestDiagnostics['source_cache_misses'];
        $data['source_cache_status'] = $requestDiagnostics['source_cache_status'] ?? 'disabled';

        sendJson(['code' => 200, 'success' => true, 'data' => $data], $minify);
    } catch (SecurityException $e) {
        sendError($e->getCode() ?: 400, $e->getMessage());
    } catch (JmException $e) {
        error_log('[jm-api] request failure request_id=' . $requestContext->requestId() . ' type=' . $e::class . ' code=' . $e->getCode());
        sendError($e->getCode() ?: 502, '上游服务不可用');
    } catch (\Throwable $e) {
        error_log('[jm-api] request failure request_id=' . $requestContext->requestId() . ' type=' . $e::class . ' code=' . $e->getCode());
        sendError(500, '服务器内部错误');
    }
}

$jmid = $_GET['jmid'] ?? null;
if ($jmid === null || $jmid === '') {
    sendError(400, '缺少参数 jmid');
}

try {
    $jmid = InputValidator::parseJmId($jmid);
} catch (SecurityException $e) {
    sendError(400, '无效的 JM ID');
}

$chapterParam = $_GET['chapter'] ?? null;
$pageParam    = $_GET['page'] ?? null;
if ($chapterParam !== null && !is_scalar($chapterParam)) {
    sendError(400, '无效的章节参数');
}
if ($pageParam !== null && !is_scalar($pageParam)) {
    sendError(400, '无效的页码参数');
}

// ── Brute force check ──

try {
    $security->checkBruteForce($jmid);
} catch (SecurityException $e) {
    sendError(429, '请求过于频繁');
}

// ── Execute ──

$startMs = hrtime(true);

try {
    $service = new JmService($requestContext);
    if (isDirectNumericChapterImageRequest($chapterParam, $pageParam)) {
        try {
            $chapterId = InputValidator::validateNumericChapterId((string) $chapterParam);
            $page = InputValidator::validatePageParam((string) $pageParam);
            $image = $service->fetchDecodedPage($chapterId, $page);
        } catch (SecurityException $e) {
            sendError(400, $e->getMessage());
        }

        $prefetchStatus = $service->maybePrefetchPages(
            $chapterId,
            $page,
            isPrefetchEnabled($_GET['prefetch'] ?? '1'),
            normalizeNextChapterHint($_GET['next_chapter'] ?? null),
            is_array($image['prefetch_manifest'] ?? null) ? $image['prefetch_manifest'] : null,
        );
        sendBinaryImage(
            $image['bytes'],
            $image['mime'],
            (bool) ($image['cache_hit'] ?? false),
            (string) ($image['codec'] ?? 'original'),
            [
                'singleflight' => (string) ($image['singleflight'] ?? 'disabled'),
                'cache_store' => (string) ($image['cache_store'] ?? 'disabled'),
                'prefetch' => $prefetchStatus,
                'apcu_free' => $image['apcu_free'] ?? null,
            ]
        );
    }

    // Step 1: fetch album
    $album    = $service->fetchAlbum($jmid);
    $episodes = $album->episodes;
    $publicBaseUrl = requestBaseUrl();

    if ($pageParam !== null && $pageParam !== '') {
        if ($chapterParam === null || $chapterParam === '') {
            sendError(400, '缺少参数 chapter');
        }

        try {
            $fetchIds = InputValidator::validateChapterParam($chapterParam, $album);
            if (count($fetchIds) !== 1) {
                throw new SecurityException('图片端点一次只能读取一个章节', 400);
            }
            $page = InputValidator::validatePageParam($pageParam);
        } catch (SecurityException $e) {
            sendError(400, $e->getMessage());
        }

        try {
            $image = $service->fetchDecodedPage($fetchIds[0], $page);
        } catch (SecurityException $e) {
            sendError(400, $e->getMessage());
        }
        $prefetchStatus = $service->maybePrefetchPages(
            $fetchIds[0],
            $page,
            isPrefetchEnabled($_GET['prefetch'] ?? '1'),
            $album->nextPhotoId($fetchIds[0]),
            is_array($image['prefetch_manifest'] ?? null) ? $image['prefetch_manifest'] : null,
        );
        sendBinaryImage(
            $image['bytes'],
            $image['mime'],
            (bool) ($image['cache_hit'] ?? false),
            (string) ($image['codec'] ?? 'original'),
            [
                'singleflight' => (string) ($image['singleflight'] ?? 'disabled'),
                'cache_store' => (string) ($image['cache_store'] ?? 'disabled'),
                'prefetch' => $prefetchStatus,
                'apcu_free' => $image['apcu_free'] ?? null,
            ]
        );
    }

    // Mode A: metadata only (no chapter param)
    if ($chapterParam === null || $chapterParam === '') {
        sendJson([
            'code'    => 200,
            'success' => true,
            'data'    => [
                'album'          => $album->toArray(),
                'chapters'       => $album->chapterHeaders(),
                'chapters_total' => count($episodes),
                'elapsed_ms'     => elapsedMs($startMs),
                'api_calls'      => $service->requestCount(),
            ],
        ], $minify);
    }

    // Mode B/C: resolve chapters
    try {
        $fetchIds = InputValidator::validateChapterParam($chapterParam, $album);
    } catch (SecurityException $e) {
        sendError(400, $e->getMessage());
    }

    $result = $service->fetchChapters($fetchIds);
    $nextChapterMap = $album->nextChapterMap();

    $response = [
        'code'    => 200,
        'success' => true,
        'data'    => [
            'album'            => $album->toArray(),
            'chapters'         => array_map(fn(JmChapter $ch) => $ch->toArray($album->id, $publicBaseUrl, $nextChapterMap[$ch->photoId] ?? null), $result['chapters']),
            'chapters_total'   => count($episodes),
            'chapters_fetched' => count($result['chapters']),
            'elapsed_ms'       => elapsedMs($startMs),
            'api_calls'        => $service->requestCount(),
        ],
    ];

    if (!empty($result['errors'])) {
        $response['data']['fetch_errors'] = $result['errors'];
    }

    sendJson($response, $minify);

} catch (JmException $e) {
    error_log('[jm-api] request failure request_id=' . $requestContext->requestId() . ' type=' . $e::class . ' code=' . $e->getCode());
    sendError($e->getCode() ?: 502, '上游服务不可用');
} catch (\Throwable $e) {
    error_log('[jm-api] request failure request_id=' . $requestContext->requestId() . ' type=' . $e::class . ' code=' . $e->getCode());
    sendError(500, '服务器内部错误');
}
