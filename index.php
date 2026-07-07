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
    public const APP_VERSION    = '2026.07.07.3';
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
    public const DEFAULT_PAGE_CACHE_MAX_ITEM_BYTES = 104857600;
    public const DEFAULT_PAGE_CACHE_MAX_BYTES = 104857600;
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
}


// ═════════════════════════════════════════════════════════════════════════════
// HTTP Client
// ═════════════════════════════════════════════════════════════════════════════

final class JmHttpClient
{
    private \CurlHandle $ch;

    public function __construct()
    {
        $ch = curl_init();
        if ($ch === false) throw new \RuntimeException('curl_init failed');
        $this->ch = $ch;

        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => JmConfig::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => JmConfig::CONNECT_TIMEOUT,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
    }

    /** @return array{0:bool, 1:string, 2:int} */
    public function get(string $url, array $headers): array
    {
        curl_setopt_array($this->ch, [
            CURLOPT_URL        => $url,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($this->ch);
        $err  = curl_errno($this->ch);

        if ($err !== 0) {
            return [false, 'cURL [' . $err . ']: ' . curl_error($this->ch), 0];
        }
        if ($body === false || $body === '') {
            return [false, 'Empty response', (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE)];
        }
        return [true, $body, (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE)];
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Redis wrapper
// ═════════════════════════════════════════════════════════════════════════════

final class RedisStore
{
    private ?\Redis $redis;
    private string $prefix;

    public function __construct(string $prefix = 'jm:')
    {
        $this->prefix = $prefix;
        try {
            $r = new \Redis();
            if ($r->connect('127.0.0.1', 6379, 0.5)) {
                $r->setOption(\Redis::OPT_PREFIX, $prefix);
                $this->redis = $r;
                return;
            }
        } catch (\Throwable) {}
        $this->redis = null;
    }

    public function isAvailable(): bool
    {
        return $this->redis !== null;
    }

    // ── Rate limiter (sliding window) ──

    /**
     * Check-and-increment rate for a key.
     * Returns [allowed: bool, remaining: int, retryAfter: int seconds].
     */
    public function checkRate(string $key, int $window, int $max): array
    {
        if (!$this->redis) return [true, $max, 0];

        $now = time();
        $k   = "rate:{$key}";

        // Remove expired entries + add current
        $this->redis->zRemRangeByScore($k, '-inf', (string) ($now - $window));
        $count = $this->redis->zCard($k);

        if ($count >= $max) {
            // Get oldest entry to calculate retry-after
            $oldest = $this->redis->zRange($k, 0, 0, true);
            $retry  = $oldest ? ((int) array_key_first($oldest) + $window - $now) : $window;
            return [false, 0, max(1, $retry)];
        }

        // Add current timestamp as score+member with microsecond uniqueness
        $this->redis->zAdd($k, (float) $now, (string) ($now . '.' . ($count + 1)));
        $this->redis->expire($k, $window + 10);

        return [true, $max - $count - 1, 0];
    }

    /** Ban a key for N seconds. */
    public function ban(string $key, int $seconds): void
    {
        if (!$this->redis) return;
        $this->redis->setex("ban:{$key}", $seconds, '1');
    }

    /** Check if a key is banned. */
    public function isBanned(string $key): bool
    {
        if (!$this->redis) return false;
        return (bool) $this->redis->exists("ban:{$key}");
    }

    /** Increment a counter, return new count. */
    public function incr(string $key, int $ttl): int
    {
        if (!$this->redis) return 1;
        $c = $this->redis->incr($key);
        if ($c === 1) $this->redis->expire($key, $ttl);
        return $c;
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// API Client — auth, domain rotation, retry, decryption
// ═════════════════════════════════════════════════════════════════════════════

final class MemoryCache
{
    private const PREFIX = 'jmapi:';
    private bool $enabled;

    public function __construct(private string $prefix = self::PREFIX)
    {
        $this->enabled = function_exists('apcu_enabled') && apcu_enabled();
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

    public function compareAndDelete(string $key, string $expectedToken): bool
    {
        if (!$this->enabled) return false;

        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);
        if (!$success || !is_string($value) || !hash_equals($expectedToken, $value)) {
            return false;
        }

        return apcu_delete($this->prefix . $key);
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

    public function diagnostics(): array
    {
        $details = [
            'enabled'            => $this->enabled,
            'total_memory_bytes' => null,
            'free_memory_bytes'  => null,
            'used_memory_bytes'  => null,
            'free_ratio'         => null,
            'entries'            => null,
            'hits'               => null,
            'misses'             => null,
        ];

        if (!$this->enabled) return $details;

        $details = array_merge($details, $this->memoryState());

        if (function_exists('apcu_cache_info')) {
            $info = @apcu_cache_info(true);
            if (is_array($info)) {
                $details['entries'] = isset($info['num_entries']) ? (int) $info['num_entries'] : null;
                $details['hits'] = isset($info['num_hits']) ? (int) $info['num_hits'] : null;
                $details['misses'] = isset($info['num_misses']) ? (int) $info['num_misses'] : null;
            }
        }

        return $details;
    }
}

final class DomainHealth
{
    private const KEY_PREFIX = 'domain-health:';

    private MemoryCache $cache;
    private array $domainsInOriginalOrder;

    public function __construct(array $domainsInOriginalOrder)
    {
        $this->cache = new MemoryCache();
        $this->domainsInOriginalOrder = array_values($domainsInOriginalOrder);
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

    public function markFailure(string $domain, bool $hardFailure = true): void
    {
        if (!$this->cache->isAvailable()) return;

        $stats = $this->stats($domain);
        $stats['failure_count'] = (int) ($stats['failure_count'] ?? 0) + 1;
        $stats['last_failure_at'] = time();
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
        return self::KEY_PREFIX . md5($domain);
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
    private JmHttpClient $http;
    private int $requestCount = 0;
    private array $domains;
    private DomainHealth $domainHealth;

    public function __construct()
    {
        $this->http    = new JmHttpClient();
        $this->domains = self::resolveDomains();
        $this->domainHealth = new DomainHealth($this->domains);
    }

    public function requestCount(): int { return $this->requestCount; }

    /** @return array{ts:string, data:array} */
    public function callJson(string $path, array $params): array
    {
        $ts         = (string) time();
        $token      = md5($ts . JmConfig::TOKEN_SECRET);
        $tokenparam = "{$ts}," . JmConfig::VERSION;
        $urlPath    = $path . '?' . http_build_query($params);

        $headers = [
            'Accept-Encoding: gzip, deflate',
            'User-Agent: ' . JmConfig::UA,
            'token: ' . $token,
            'tokenparam: ' . $tokenparam,
        ];

        $lastError = null;

        foreach ($this->domainHealth->orderedDomains($this->domains) as $domain) {
            $url = "https://{$domain}{$urlPath}";

            for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
                if ($retry > 0) usleep(300_000);

                $requestStarted = microtime(true);
                [$ok, $body, $statusCode] = $this->http->get($url, $headers);
                $latencyMs = (int) round((microtime(true) - $requestStarted) * 1000);
                $this->requestCount++;

                if (!$ok)                  { $this->domainHealth->markFailure($domain, true); $lastError = 'Network error'; continue; }
                if ($statusCode >= 500)    { $this->domainHealth->markFailure($domain, true); $lastError = "HTTP {$statusCode}"; continue; }

                $json = json_decode($body, true);
                if ($json === null)        { $this->domainHealth->markFailure($domain, false); continue; }

                $code = $json['code'] ?? -1;
                if ($code !== 200)         { $this->domainHealth->markFailure($domain, false); continue; }

                $enc = $json['data'] ?? '';
                if ($enc === '')           { $this->domainHealth->markFailure($domain, false); continue; }

                try {
                    $decrypted = self::decrypt($enc, $ts);
                } catch (\Throwable)       { $this->domainHealth->markFailure($domain, false); continue; }

                $resData = json_decode($decrypted, true);
                if ($resData === null)     { $this->domainHealth->markFailure($domain, false); continue; }

                $this->domainHealth->markSuccess($domain, $latencyMs);
                return ['ts' => $ts, 'data' => $resData];
            }
        }

        throw new JmException('API 域名全部不可用', 502);
    }

    public function fetchScrambleId(string $photoId): string
    {
        $ts         = (string) time();
        $token      = md5($ts . JmConfig::TOKEN_SECRET2);
        $tokenparam = "{$ts}," . JmConfig::VERSION;

        $headers = [
            'Accept-Encoding: gzip, deflate',
            'User-Agent: ' . JmConfig::UA,
            'token: ' . $token,
            'tokenparam: ' . $tokenparam,
        ];

        $query = http_build_query([
            'id'            => $photoId,
            'mode'          => 'vertical',
            'page'          => '0',
            'app_img_shunt' => '1',
        ]);

        $urlPath = JmConfig::ENDPOINT_SCRAMBLE . '?' . $query;

        foreach ($this->domainHealth->orderedDomains($this->domains) as $domain) {
            $url = "https://{$domain}{$urlPath}";
            for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
                if ($retry > 0) usleep(300_000);
                $requestStarted = microtime(true);
                [$ok, $body, $code] = $this->http->get($url, $headers);
                $latencyMs = (int) round((microtime(true) - $requestStarted) * 1000);
                $this->requestCount++;
                if (!$ok || $code >= 500) {
                    $this->domainHealth->markFailure($domain, true);
                    continue;
                }
                if (preg_match('/var\s+scramble_id\s*=\s*(\d+);/', $body, $m)) {
                    $this->domainHealth->markSuccess($domain, $latencyMs);
                    return $m[1];
                }
                $this->domainHealth->markFailure($domain, false);
            }
        }

        return (string) JmConfig::SCRAMBLE_220980;
    }

    public function downloadImage(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || $host === '' || !str_starts_with($path, '/media/photos/')) {
            throw new JmException('Invalid image URL', 502);
        }

        $headers = [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'User-Agent: ' . JmConfig::UA,
            'Referer: https://18comic.vip/',
        ];

        [$ok, $body, $statusCode] = $this->http->get($url, $headers);
        $this->requestCount++;

        if (!$ok || $statusCode >= 400 || $body === '') {
            throw new JmException('Image download failed', 502);
        }

        return $body;
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

    // ── Domain auto-update ──

    private static function resolveDomains(): array
    {
        $cache = new MemoryCache();
        $cachedDomains = $cache->get('api-domains');
        if (is_array($cachedDomains) && !empty($cachedDomains)) {
            return $cachedDomains;
        }

        foreach (JmConfig::DOMAIN_SERVER_URLS as $url) {
            try {
                $ctx = stream_context_create([
                    'http' => ['timeout' => 10],
                    'ssl'  => ['verify_peer' => true],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body === false || $body === '') continue;

                while (strlen($body) > 0 && ord($body[0]) > 127) {
                    $body = substr($body, 1);
                }
                $body = trim($body);

                $cipher = base64_decode($body, true);
                if ($cipher === false) continue;

                $key = md5(JmConfig::DOMAIN_SECRET); // hex → 32 bytes → AES-256
                $plain = openssl_decrypt($cipher, 'AES-256-ECB', $key,
                    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
                if ($plain === false) continue;

                $plain = self::pkcs7Unpad($plain);
                $data  = json_decode($plain, true);
                $servers = $data['Server'] ?? null;
                if (is_array($servers) && !empty($servers)) {
                    $cache->set('api-domains', $servers, 86400);
                    return $servers;
                }
            } catch (\Throwable) { continue; }
        }

        return JmConfig::API_DOMAINS;
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// Models
// ═════════════════════════════════════════════════════════════════════════════

final class JmAlbum
{
    public string $id, $name, $description, $image, $views, $likes, $comments;
    public array $author, $tags, $works, $actors, $related;
    /** @var list<array{photo_id:string, sort:string, title:string}> */
    public array $episodes;

    private function __construct() {}

    public static function fromApiResponse(array $data): self
    {
        $self = new self();
        $self->id          = (string) $data['id'];
        $self->name        = $data['name'] ?? '';
        $self->author      = $data['author'] ?? [];
        $self->description = $data['description'] ?? '';
        $rawImage          = $data['image'] ?? '';
        $self->image       = is_scalar($rawImage) ? (string) $rawImage : '';
        $self->views       = $data['total_views'] ?? '0';
        $self->likes       = $data['likes'] ?? '0';
        $self->comments    = (string) ($data['comment_total'] ?? '0');
        $self->tags        = $data['tags'] ?? [];
        $self->works       = $data['works'] ?? [];
        $self->actors      = $data['actors'] ?? [];
        $self->related     = $data['related_list'] ?? [];

        $episodes = [];
        foreach ($data['series'] ?? [] as $sourceIndex => $ch) {
            $episodes[] = [
                'photo_id' => (string) $ch['id'],
                'sort'     => (string) ($ch['sort'] ?? '1'),
                'title'    => $ch['name'] ?? '',
                'source_index' => (int) $sourceIndex,
            ];
        }

        if (empty($episodes)) {
            $episodes[] = ['photo_id' => (string) $data['id'], 'sort' => '1', 'title' => $self->name];
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
    /** @var list<array{index:int, filename:string, url:string, scramble_id:string, decode_segments:int}> */
    public array $images;

    private function __construct() {}

    public static function fromApiResponse(array $data, string $scrambleId): self
    {
        $self = new self();
        $self->photoId   = (string) $data['id'];
        $self->title     = $data['name'] ?? '';
        $self->pageCount = count($data['images'] ?? []);

        $sort = '1';
        foreach ($data['series'] ?? [] as $ch) {
            if ((string) $ch['id'] === (string) $data['id']) {
                $sort = (string) ($ch['sort'] ?? '1');
                break;
            }
        }
        $self->sort = $sort;

        $cdn    = JmConfig::CDN_DOMAINS[array_rand(JmConfig::CDN_DOMAINS)];
        $images = [];
        foreach ($data['images'] ?? [] as $i => $fn) {
            $images[] = [
                'index'           => $i + 1,
                'filename'        => $fn,
                'url'             => "https://{$cdn}/media/photos/{$self->photoId}/{$fn}",
                'scramble_id'     => $scrambleId,
                'decode_segments' => ScrambleDecoder::segments($scrambleId, $self->photoId, $fn),
            ];
        }
        $self->images = $images;
        return $self;
    }

    public function toArray(?string $albumId = null, ?string $publicBaseUrl = null, ?string $nextChapterId = null): array
    {
        $images = array_map(function (array $image) use ($albumId, $publicBaseUrl, $nextChapterId): array {
            $sourceUrl = (string) ($image['url'] ?? '');
            $page = (int) ($image['index'] ?? 0);
            $segments = (int) ($image['decode_segments'] ?? 0);
            $filename = (string) ($image['filename'] ?? 'page.jpg');
            $extension = self::imageExtension($filename);

            $image['source_url'] = $sourceUrl;
            $image['mime'] = ($segments > 0 && $extension !== 'gif')
                ? ScrambleDecoder::preferredDecodedMime()
                : self::imageMime($extension);

            if ($albumId !== null && $publicBaseUrl !== null && $page > 0) {
                $image['url'] = buildDecodedPageUrl($publicBaseUrl, $albumId, $this->photoId, $page, $nextChapterId);
            }

            return $image;
        }, $this->images);

        return [
            'photo_id'   => $this->photoId,
            'title'      => $this->title,
            'sort'       => $this->sort,
            'page_count' => $this->pageCount,
            'images'     => $images,
        ];
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
        $self->id          = (string) ($item['id'] ?? $item['aid'] ?? $item['AID'] ?? '');
        $self->name        = InputValidator::sanitizeString((string) ($item['name'] ?? 'JM ' . $self->id));
        $self->author      = InputValidator::sanitizeString((string) ($item['author'] ?? ''));
        $self->description = InputValidator::sanitizeString((string) ($item['description'] ?? ''));
        $self->image       = (string) ($item['image'] ?? '');
        $self->tags        = self::tagsFromPayload($item);
        $self->likes       = self::intFromPayload($item['likes'] ?? 0);
        $self->totalViews  = self::intFromPayload($item['total_views'] ?? $item['totalViews'] ?? 0);
        $self->updatedAt   = isset($item['update_at']) ? self::intFromPayload($item['update_at']) : null;

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
                $title = trim((string) ($item[$key]['title'] ?? ''));
                if ($title !== '' && !in_array($title, $tags, true)) {
                    $tags[] = InputValidator::sanitizeString($title);
                }
            }
        }
        foreach (['tags', 'works', 'actors'] as $key) {
            $values = $item[$key] ?? [];
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value !== '' && !in_array($value, $tags, true)) {
                    $tags[] = InputValidator::sanitizeString($value);
                }
            }
        }
        return $tags;
    }

    private static function intFromPayload(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_float($value)) return (int) $value;
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) return (int) trim($value);
        return 0;
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

final class ScrambleDecoder
{
    private const WEBP_QUALITY = 85;
    private const JPEG_QUALITY = 85;

    public static function segments(string $scrambleId, string $aid, string $filename): int
    {
        $sid = (int) $scrambleId;
        $aid = (int) $aid;

        if ($aid < $sid)                     return 0;
        if ($aid < JmConfig::SCRAMBLE_268850) return 10;

        $x = ($aid < JmConfig::SCRAMBLE_421926) ? 10 : 8;
        $pageName = self::pageNameForScramble($filename);
        $h = md5($aid . $pageName);
        $n = ord($h[strlen($h) - 1]) % $x;

        return $n * 2 + 2;
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

    private JmApiClient $api;
    private MemoryCache $cache;

    public function __construct()
    {
        $this->api = new JmApiClient();
        $this->cache = new MemoryCache();
    }

    public function fetchAlbum(string $jmid): JmAlbum
    {
        $resp = $this->api->callJson(JmConfig::ENDPOINT_ALBUM, ['id' => $jmid]);
        return JmAlbum::fromApiResponse($resp['data']);
    }

    public function fetchLatestList(int $page): JmListResult
    {
        $window = self::sourceListWindow($page);
        $resp = $this->api->callJson(JmConfig::ENDPOINT_LATEST, ['page' => (string) $window['source_page']]);
        $payload = is_array($resp['data']) ? $resp['data'] : [];
        return $this->windowedListResultFromItems('latest', $page, $payload, 0, $window);
    }

    public function fetchPopularList(int $page): JmListResult
    {
        $window = self::sourceListWindow($page);
        $resp = $this->api->callJson(JmConfig::ENDPOINT_CATEGORY_FILTER, [
            'page' => (string) $window['source_page'],
            'c'    => 'latest',
            'o'    => 'new',
        ]);
        $payload = is_array($resp['data']) ? $resp['data'] : [];
        $items = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : [];
        $total = self::intFromPayload($payload['total'] ?? 0);
        return $this->windowedListResultFromItems('popular', $page, $items, $total, $window);
    }

    public function searchAlbums(string $query, int $page, string $order = 'mr'): JmListResult
    {
        $resp = $this->api->callJson(JmConfig::ENDPOINT_SEARCH, [
            'page'         => (string) $page,
            'o'            => $order,
            'search_query' => $query,
        ]);
        $payload = is_array($resp['data']) ? $resp['data'] : [];
        $items = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : [];
        $total = self::intFromPayload($payload['total'] ?? count($items));

        if (empty($items) && !empty($payload['redirect_aid'])) {
            $items[] = ['id' => (string) $payload['redirect_aid'], 'name' => 'JM ' . $payload['redirect_aid']];
            $total = max($total, 1);
        }

        return $this->listResultFromItems('search', $page, $items, $total);
    }

    public function fetchScrambleId(string $photoId): string
    {
        $cacheKey = 'scramble:' . md5($photoId);
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $id = $this->api->fetchScrambleId($photoId);
        $this->cache->set($cacheKey, $id, 3600);
        return $id;
    }

    public function fetchChapter(string $photoId, string $scrambleId): JmChapter
    {
        $cacheKey = 'chapter:' . md5($photoId . ':' . $scrambleId);
        $cached = $this->cache->get($cacheKey);
        if ($cached instanceof JmChapter) {
            return $cached;
        }

        $resp = $this->api->callJson(JmConfig::ENDPOINT_CHAPTER, ['id' => $photoId]);
        $chapter = JmChapter::fromApiResponse($resp['data'], $scrambleId);
        $this->cache->set($cacheKey, $chapter, self::envInt('JM_CHAPTER_CACHE_TTL', JmConfig::DEFAULT_CHAPTER_CACHE_TTL, 0, 86400));
        return $chapter;
    }

    public function fetchReaderManifest(string $photoId): array
    {
        $scrambleId = $this->fetchScrambleId($photoId);
        $cacheKey = self::readerManifestCacheKey($photoId, $scrambleId);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['photo_id'], $cached['images']) && is_array($cached['images'])) {
            return $cached;
        }

        $chapter = $this->fetchChapter($photoId, $scrambleId);
        $images = [];
        foreach ($chapter->images as $image) {
            $page = (int) ($image['index'] ?? 0);
            if ($page < 1) continue;

            $filename = (string) ($image['filename'] ?? 'page.jpg');
            $extension = self::imageExtension($filename);
            $segments = (int) ($image['decode_segments'] ?? 0);
            $sourceUrl = (string) ($image['url'] ?? '');
            $images[] = [
                'index' => $page,
                'filename' => $filename,
                'url' => $sourceUrl,
                'source_url' => $sourceUrl,
                'mime' => ($segments > 0 && $extension !== 'gif')
                    ? ScrambleDecoder::preferredDecodedMime()
                    : self::imageMime($extension),
                'scramble_id' => $scrambleId,
                'decode_segments' => $segments,
                'cache_key' => self::decodedPageCacheKey($photoId, $page, $image),
            ];
        }

        $manifest = [
            'photo_id' => $photoId,
            'scramble_id' => $scrambleId,
            'page_count' => $chapter->pageCount,
            'images' => $images,
        ];

        $this->cache->set(
            $cacheKey,
            $manifest,
            self::envInt('JM_CHAPTER_CACHE_TTL', JmConfig::DEFAULT_CHAPTER_CACHE_TTL, 0, 86400)
        );

        return $manifest;
    }

    /** @return array{bytes:string, mime:string, codec:string, cache_hit:bool, singleflight:string, cache_store:string, apcu_free:?int} */
    public function fetchDecodedPage(string $photoId, int $page): array
    {
        $manifest = $this->fetchReaderManifest($photoId);
        $image = $manifest['images'][$page - 1] ?? null;

        if ($image === null) {
            throw new SecurityException("页码 {$page} 超出范围 1-{$manifest['page_count']}", 400);
        }

        $cacheKey = (string) ($image['cache_key'] ?? self::decodedPageCacheKey($photoId, $page, $image));
        $cached = $this->cachedDecodedPage($cacheKey);
        if ($cached !== null) return $cached + ['singleflight' => 'hit'];

        return $this->singleFlight($cacheKey, fn(): array => $this->materializeDecodedPage($cacheKey, $image));
    }

    public function maybePrefetchPages(string $photoId, int $page, bool $enabled, ?string $nextChapterId = null): string
    {
        $pages = self::envInt('JM_PREFETCH_PAGES', JmConfig::DEFAULT_PREFETCH_PAGES, 0, 30);
        if (!$enabled) return 'disabled';
        if ($pages <= 0) return 'none';
        if (!$this->cache->isAvailable()) return 'skipped-no-apcu';
        if (!$this->prefetchWaterlineOk()) return 'skipped-low-memory';

        $nextChapterId = normalizeNextChapterHint($nextChapterId);
        $prefetchNextChapter = $this->shouldPrefetchNextChapter($photoId, $page, $nextChapterId);

        register_shutdown_function(function () use ($photoId, $page, $pages, $nextChapterId, $prefetchNextChapter): void {
            $this->prefetchDecodedPages($photoId, $page + 1, $pages);
            if ($prefetchNextChapter && $nextChapterId !== null) {
                $this->prefetchNextChapter($nextChapterId);
            }
        });

        return 'scheduled';
    }

    public function prefetchDecodedPages(string $photoId, int $startPage, int $count): void
    {
        $highCount = min($count, self::envInt('JM_PREFETCH_HIGH_PRIORITY_PAGES', JmConfig::DEFAULT_PREFETCH_HIGH_PRIORITY_PAGES, 0, 10));
        $highPriorityPages = $highCount > 0 ? range($startPage, $startPage + $highCount - 1) : [];
        $lowPriorityPages = [];
        if ($count > $highCount) {
            $lowPriorityPages = range($startPage + $highCount, $startPage + $count - 1);
        }

        foreach ($highPriorityPages as $candidatePage) {
            try {
                if ($this->isDecodedPageCached($photoId, $candidatePage)) {
                    continue;
                }
                $this->fetchDecodedPage($photoId, $candidatePage);
            } catch (SecurityException) {
                break;
            } catch (\Throwable $e) {
                error_log('[jm-api] prefetch failed: ' . $e->getMessage());
                break;
            }
        }

        foreach ($lowPriorityPages as $candidatePage) {
            try {
                if (!$this->prefetchWaterlineOk()) {
                    break;
                }
                if ($this->isDecodedPageCached($photoId, $candidatePage)) {
                    continue;
                }
                $this->fetchDecodedPage($photoId, $candidatePage);
            } catch (SecurityException) {
                break;
            } catch (\Throwable $e) {
                error_log('[jm-api] prefetch failed: ' . $e->getMessage());
                break;
            }
        }
    }

    /** @return array{chapters: JmChapter[], errors: list<array{photo_id:string, error:string}>} */
    public function fetchChapters(array $photoIds): array
    {
        $chapters = [];
        $errors   = [];
        foreach ($photoIds as $pid) {
            try {
                $scrambleId = $this->fetchScrambleId($pid);
                $chapters[] = $this->fetchChapter($pid, $scrambleId);
            } catch (\Throwable $e) {
                $errors[] = ['photo_id' => $pid, 'error' => 'Failed'];
            }
        }
        return ['chapters' => $chapters, 'errors' => $errors];
    }

    public function requestCount(): int { return $this->api->requestCount(); }

    public static function runtimeDiagnostics(MemoryCache $cache): array
    {
        return [
            'singleflight' => [
                'enabled' => $cache->isAvailable(),
                'lock_ttl_seconds' => self::envInt('JM_SINGLEFLIGHT_LOCK_TTL', JmConfig::DEFAULT_SINGLEFLIGHT_LOCK_TTL, 1, 300),
                'wait_ms' => self::envInt('JM_SINGLEFLIGHT_WAIT_MS', JmConfig::DEFAULT_SINGLEFLIGHT_WAIT_MS, 0, 30000),
            ],
            'prefetch' => [
                'default_pages' => self::envInt('JM_PREFETCH_PAGES', JmConfig::DEFAULT_PREFETCH_PAGES, 0, 30),
                'high_priority_pages' => self::envInt('JM_PREFETCH_HIGH_PRIORITY_PAGES', JmConfig::DEFAULT_PREFETCH_HIGH_PRIORITY_PAGES, 0, 10),
                'next_chapter_pages' => self::envInt('JM_NEXT_CHAPTER_PREFETCH_PAGES', JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_PAGES, 0, 5),
                'low_memory_policy' => 'skip-low-priority-then-all',
            ],
            'cache_policy' => [
                'max_item_bytes' => self::pageCacheMaxItemBytes(),
                'page_cache_min_free_bytes' => self::envInt('JM_PAGE_CACHE_MIN_FREE_BYTES', JmConfig::DEFAULT_PAGE_CACHE_MIN_FREE_BYTES, 0, 512 * 1024 * 1024),
                'page_cache_min_free_ratio' => self::envInt('JM_PAGE_CACHE_MIN_FREE_RATIO', JmConfig::DEFAULT_PAGE_CACHE_MIN_FREE_RATIO, 0, 100),
                'prefetch_min_free_bytes' => self::envInt('JM_PREFETCH_MIN_FREE_BYTES', JmConfig::DEFAULT_PREFETCH_MIN_FREE_BYTES, 0, 512 * 1024 * 1024),
                'prefetch_min_free_ratio' => self::envInt('JM_PREFETCH_MIN_FREE_RATIO', JmConfig::DEFAULT_PREFETCH_MIN_FREE_RATIO, 0, 100),
            ],
        ];
    }

    private function materializeDecodedPage(string $cacheKey, array $image): array
    {
        $segments = (int) ($image['decode_segments'] ?? 0);
        $extension = self::imageExtension((string) ($image['filename'] ?? 'page.jpg'));
        $mime = self::imageMime($extension);
        $raw = $this->api->downloadImage((string) ($image['url'] ?? ''));

        if ($extension === 'gif' || $segments === 0) {
            $result = [
                'bytes' => $raw,
                'mime' => $mime,
                'codec' => self::codecFromMime($mime),
                'cache_hit' => false,
            ];
            $result['cache_store'] = $this->cacheDecodedPage($cacheKey, $result);
            $result['apcu_free'] = $this->apcuFreeBytes();
            return $result;
        }

        $decoded = ScrambleDecoder::decodeBytesWithInfo($raw, $segments);
        $result = [
            'bytes' => $decoded['bytes'],
            'mime' => $decoded['mime'],
            'codec' => $decoded['codec'],
            'cache_hit' => false,
        ];
        $result['cache_store'] = $this->cacheDecodedPage($cacheKey, $result);
        $result['apcu_free'] = $this->apcuFreeBytes();
        return $result;
    }

    private function singleFlight(string $cacheKey, callable $producer): array
    {
        if (!$this->cache->isAvailable()) {
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

        $waitMs = self::envInt('JM_SINGLEFLIGHT_WAIT_MS', JmConfig::DEFAULT_SINGLEFLIGHT_WAIT_MS, 0, 30000);
        $waitStart = microtime(true);
        while ((int) round((microtime(true) - $waitStart) * 1000) < $waitMs) {
            usleep(random_int(50_000, 150_000));
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
        $cached = $this->cache->get($cacheKey);
        if (!is_array($cached) || !isset($cached['bytes'], $cached['mime'])) {
            return null;
        }

        return [
            'bytes' => (string) $cached['bytes'],
            'mime' => (string) $cached['mime'],
            'codec' => (string) ($cached['codec'] ?? self::codecFromMime((string) $cached['mime'])),
            'cache_hit' => true,
            'cache_store' => 'hit',
            'apcu_free' => $this->apcuFreeBytes(),
        ];
    }

    private function cacheDecodedPage(string $cacheKey, array $result): string
    {
        if (!$this->cache->isAvailable()) return 'disabled';

        $maxBytes = self::pageCacheMaxItemBytes();
        if ($maxBytes > 0 && strlen((string) ($result['bytes'] ?? '')) > $maxBytes) return 'skipped-too-large';
        if (!$this->pageCacheWaterlineOk()) return 'skipped-low-memory';

        $stored = $this->cache->set(
            $cacheKey,
            [
                'bytes' => (string) $result['bytes'],
                'mime' => (string) $result['mime'],
                'codec' => (string) ($result['codec'] ?? self::codecFromMime((string) $result['mime'])),
            ],
            self::envInt('JM_PAGE_CACHE_TTL', JmConfig::DEFAULT_PAGE_CACHE_TTL, 0, 86400)
        );

        return $stored ? 'stored' : 'disabled';
    }

    private function isDecodedPageCached(string $photoId, int $page): bool
    {
        $manifest = $this->fetchReaderManifest($photoId);
        $image = $manifest['images'][$page - 1] ?? null;

        if ($image === null) {
            throw new SecurityException("页码 {$page} 超出范围 1-{$manifest['page_count']}", 400);
        }

        return $this->cachedDecodedPage((string) ($image['cache_key'] ?? self::decodedPageCacheKey($photoId, $page, $image))) !== null;
    }

    private function shouldPrefetchNextChapter(string $photoId, int $page, ?string $nextChapterId): bool
    {
        if ($nextChapterId === null || $nextChapterId === $photoId) return false;
        if (!self::envBool('JM_NEXT_CHAPTER_PREFETCH', true)) return false;

        try {
            $manifest = $this->fetchReaderManifest($photoId);
            $pageCount = (int) ($manifest['page_count'] ?? 0);
            if ($pageCount <= 0) return false;

            $remaining = $pageCount - $page;
            $progress = (int) floor(($page * 100) / $pageCount);
            return $remaining <= self::envInt('JM_NEXT_CHAPTER_PREFETCH_REMAINING', JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_REMAINING, 0, 1000)
                || $progress >= self::envInt('JM_NEXT_CHAPTER_PREFETCH_PROGRESS', JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_PROGRESS, 1, 100);
        } catch (\Throwable) {
            return false;
        }
    }

    private function prefetchNextChapter(string $nextChapterId): void
    {
        $pages = self::envInt('JM_NEXT_CHAPTER_PREFETCH_PAGES', JmConfig::DEFAULT_NEXT_CHAPTER_PREFETCH_PAGES, 0, 5);
        for ($page = 1; $page <= $pages; $page++) {
            try {
                if (!$this->prefetchWaterlineOk()) break;
                if ($this->isDecodedPageCached($nextChapterId, $page)) continue;
                $this->fetchDecodedPage($nextChapterId, $page);
            } catch (SecurityException) {
                break;
            } catch (\Throwable $e) {
                error_log('[jm-api] next chapter prefetch failed: ' . $e->getMessage());
                break;
            }
        }
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
        return 'manifest:' . md5($photoId . ':' . $scrambleId);
    }

    private static function decodedPageCacheKey(string $photoId, int $page, array $image): string
    {
        return 'page:' . md5(implode(':', [
            $photoId,
            (string) $page,
            (string) ($image['filename'] ?? ''),
            (string) ($image['url'] ?? ''),
            (string) ($image['decode_segments'] ?? 0),
        ]));
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

    private static function lockToken(): string
    {
        return bin2hex(random_bytes(8)) . ':' . (function_exists('getmypid') ? (string) getmypid() : '0');
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
        $mapped = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $mapped[] = JmListItem::fromPayload($item);
            }
        }

        $pageSize = max(1, count($mapped));
        $loaded = ($page - 1) * $pageSize + count($mapped);
        $hasNextPage = $total > 0 ? $loaded < $total : count($mapped) > 0;

        return new JmListResult($mode, $page, $total, $hasNextPage, $mapped);
    }

    /** @return array{source_page:int, offset:int} */
    private static function sourceListWindow(int $page): array
    {
        $start = (max(1, $page) - 1) * self::LOCAL_LIST_PAGE_SIZE;
        return [
            'source_page' => intdiv($start, self::SOURCE_LIST_PAGE_SIZE),
            'offset' => $start % self::SOURCE_LIST_PAGE_SIZE,
        ];
    }

    /** @param array{source_page:int, offset:int} $window */
    private function windowedListResultFromItems(string $mode, int $page, array $items, int $total, array $window): JmListResult
    {
        $mapped = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $mapped[] = JmListItem::fromPayload($item);
            }
        }

        $sliced = array_slice($mapped, $window['offset'], self::LOCAL_LIST_PAGE_SIZE);
        $hasNextPage = $total > 0
            ? ($page * self::LOCAL_LIST_PAGE_SIZE) < $total
            : count($mapped) > $window['offset'] + self::LOCAL_LIST_PAGE_SIZE || count($mapped) >= self::SOURCE_LIST_PAGE_SIZE;

        return new JmListResult($mode, $page, $total, $hasNextPage, $sliced);
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

final class SecurityManager
{
    private RedisStore $store;
    private string $clientIp;

    public function __construct()
    {
        $this->store    = new RedisStore();
        $this->clientIp = self::realIp();
    }

    /** Get client real IP (respect proxies). */
    private static function realIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $key) {
            $val = $_SERVER[$key] ?? '';
            if ($val !== '') {
                // Take first IP if comma-separated
                $ip = trim(explode(',', $val)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '127.0.0.1';
    }

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
        $count = $this->store->incr($key, 600);

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
    public static function parseJmId(string $raw): string
    {
        $raw = trim($raw);

        // Reject overly long or obviously malicious input
        if (strlen($raw) > 200) {
            throw new SecurityException('Invalid ID format', 400);
        }

        // Strip "JM" prefix
        if (preg_match('/^JM(\d+)$/i', $raw, $m)) return self::normalizeJmId($m[1]);

        // Extract from URL
        if (preg_match('#/(?:album|photo)s?/(\d+)#i', $raw, $m)) return self::normalizeJmId($m[1]);
        if (preg_match('/[?&]id=(\d+)/i', $raw, $m)) return self::normalizeJmId($m[1]);

        // Pure digits
        if (preg_match('/^(\d+)$/', $raw, $m)) return self::normalizeJmId($m[1]);

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
            $photoIds = array_column($album->episodes, 'photo_id');
            foreach ($ids as $id) {
                if (preg_match('/^\d+$/', $id) && in_array($id, $photoIds, true)) {
                    $valid[] = $id;
                }
            }
            if (empty($valid)) throw new SecurityException('未找到有效章节 ID', 400);
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

final class JmException extends \RuntimeException
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

function requestBaseUrl(): string
{
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    if (!is_string($proto) || $proto === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    $proto = strtolower(trim(explode(',', $proto)[0]));
    if ($proto !== 'https' && $proto !== 'http') {
        $proto = 'http';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = trim(explode(',', (string) $host)[0]);
    if ($host === '') {
        $host = 'localhost';
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(dirname($scriptName), '/');
    if ($basePath === '' || $basePath === '.') {
        $basePath = '';
    }

    return "{$proto}://{$host}{$basePath}";
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

function buildCoverUrl(string $albumId, string $image): string
{
    $image = trim($image);
    if ($image !== '' && (str_starts_with($image, 'http://') || str_starts_with($image, 'https://'))) {
        return $image;
    }

    $cdn = JmConfig::CDN_DOMAINS[array_rand(JmConfig::CDN_DOMAINS)];
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
        default => throw new SecurityException('无效的列表模式', 400),
    };
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

function isDirectNumericChapterImageRequest(mixed $chapterParam, mixed $pageParam): bool
{
    if (!is_scalar($chapterParam) || !is_scalar($pageParam)) return false;
    $chapter = trim((string) $chapterParam);
    $page = trim((string) $pageParam);
    return $chapter !== '' && $page !== '' && preg_match('/^\d{1,20}$/', $chapter) === 1;
}

/** @return never */
function sendBinaryImage(string $bytes, string $mime, bool $cacheHit = false, string $codec = 'original', array $diagnostics = []): void
{
    http_response_code(200);
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
    exit;
}

/** @return never */
function sendJson(array $data, bool $minify): void
{
    $code = ($data['code'] ?? 200);
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    // Prevent framing (clickjacking)
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    if ($code === 429) {
        header('Retry-After: ' . ($data['retry_after'] ?? 60));
    }
    echo json_encode($data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | ($minify ? 0 : JSON_PRETTY_PRINT));
    exit;
}

/** @return never */
function sendError(int $code, string $msg, array $extra = []): void
{
    // Don't leak internal details in production
    $safeMsg = ($code >= 500) ? '服务器内部错误' : $msg;
    $payload = ['code' => $code, 'success' => false, 'error' => $safeMsg];
    if ($extra) $payload = array_merge($payload, $extra);
    sendJson($payload, false);
}


// ═════════════════════════════════════════════════════════════════════════════
// Entry Point
// ═════════════════════════════════════════════════════════════════════════════

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(300);
ignore_user_abort(true);
header_remove('X-Powered-By');
header('X-JM-API-Version: ' . jmApiVersion());

// Check extensions
foreach (['curl', 'openssl', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 500, 'success' => false, 'error' => 'Server misconfigured']);
        exit;
    }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Health check (no rate limit) ──

if (($_GET['health'] ?? '') === '1') {
    $memoryCache = new MemoryCache();
    $runtimeDiagnostics = JmService::runtimeDiagnostics($memoryCache);
    $report = [
        'app_version'  => jmApiVersion(),
        'php'          => PHP_VERSION,
        'apcu'         => $memoryCache->isAvailable(),
        'apcu_details' => $memoryCache->diagnostics(),
        'singleflight' => $runtimeDiagnostics['singleflight'],
        'prefetch'     => $runtimeDiagnostics['prefetch'],
        'cache_policy' => $runtimeDiagnostics['cache_policy'],
        'domains'      => DomainHealth::diagnostics(JmConfig::API_DOMAINS),
        'redis'        => (new RedisStore())->isAvailable(),
        'memory'       => memory_get_usage(true),
    ];
    sendJson(['code' => 200, 'success' => true, 'version' => jmApiVersion(), 'diagnostics' => $report], false);
}

// ── Security: rate limit first ──

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
    $service = new JmService();
    $startMs = hrtime(true);
    try {
        $page = normalizeListPage($_GET['page'] ?? '1');
        if ($searchParam !== null) {
            $query = normalizeSearchQuery($searchParam);
            $order = normalizeSearchOrder($_GET['order'] ?? $_GET['o'] ?? 'mr');
            $result = $service->searchAlbums($query, $page, $order);
        } else {
            $mode = normalizeListMode($listParam);
            $result = $mode === 'latest'
                ? $service->fetchLatestList($page)
                : $service->fetchPopularList($page);
        }

        $data = $result->toArray();
        $data['elapsed_ms'] = elapsedMs($startMs);
        $data['api_calls'] = $service->requestCount();

        sendJson(['code' => 200, 'success' => true, 'data' => $data], $minify);
    } catch (SecurityException $e) {
        sendError($e->getCode() ?: 400, $e->getMessage());
    } catch (JmException $e) {
        error_log('[jm-api] JmException: ' . $e->getMessage());
        sendError($e->getCode() ?: 502, '上游服务不可用');
    } catch (\Throwable $e) {
        error_log('[jm-api] Throwable: ' . $e::class . ': ' . $e->getMessage());
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

// ── Brute force check ──

try {
    $security->checkBruteForce($jmid);
} catch (SecurityException $e) {
    sendError(429, '请求过于频繁');
}

// ── Execute ──

$service = new JmService();
$startMs = hrtime(true);

try {
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
            normalizeNextChapterHint($_GET['next_chapter'] ?? null)
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
            $album->nextPhotoId($fetchIds[0])
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
    error_log('[jm-api] JmException: ' . $e->getMessage());
    sendError($e->getCode() ?: 502, '上游服务不可用');
} catch (\Throwable $e) {
    error_log('[jm-api] Throwable: ' . $e::class . ': ' . $e->getMessage());
    sendError(500, '服务器内部错误');
}
