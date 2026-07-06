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
    public const APP_VERSION    = '2026.07.06.1';
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
    public const DEFAULT_PAGE_CACHE_MAX_ITEM_BYTES = 104857600;
    public const DEFAULT_PAGE_CACHE_MAX_BYTES = 104857600;
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

    public function diagnostics(): array
    {
        $details = [
            'enabled'            => $this->enabled,
            'total_memory_bytes' => null,
            'free_memory_bytes'  => null,
            'used_memory_bytes'  => null,
            'entries'            => null,
            'hits'               => null,
            'misses'             => null,
        ];

        if (!$this->enabled) return $details;

        if (function_exists('apcu_sma_info')) {
            $sma = @apcu_sma_info(true);
            if (is_array($sma)) {
                $total = null;
                if (isset($sma['num_seg'], $sma['seg_size'])) {
                    $total = (int) $sma['num_seg'] * (int) $sma['seg_size'];
                }
                $free = isset($sma['avail_mem']) ? (int) $sma['avail_mem'] : null;
                $details['total_memory_bytes'] = $total;
                $details['free_memory_bytes'] = $free;
                $details['used_memory_bytes'] = ($total !== null && $free !== null) ? max(0, $total - $free) : null;
            }
        }

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

final class JmApiClient
{
    private JmHttpClient $http;
    private int $requestCount = 0;
    private array $domains;

    public function __construct()
    {
        $this->http    = new JmHttpClient();
        $this->domains = self::resolveDomains();
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

        foreach ($this->domains as $domain) {
            $url = "https://{$domain}{$urlPath}";

            for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
                if ($retry > 0) usleep(300_000);

                [$ok, $body, $statusCode] = $this->http->get($url, $headers);
                $this->requestCount++;

                if (!$ok)                  { $lastError = 'Network error'; continue; }
                if ($statusCode >= 500)    { $lastError = "HTTP {$statusCode}"; continue; }

                $json = json_decode($body, true);
                if ($json === null)        { continue; }

                $code = $json['code'] ?? -1;
                if ($code !== 200)         { continue; }

                $enc = $json['data'] ?? '';
                if ($enc === '')           { continue; }

                try {
                    $decrypted = self::decrypt($enc, $ts);
                } catch (\Throwable)       { continue; }

                $resData = json_decode($decrypted, true);
                if ($resData === null)     { continue; }

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

        foreach ($this->domains as $domain) {
            $url = "https://{$domain}{$urlPath}";
            for ($retry = 0; $retry <= JmConfig::MAX_RETRIES; $retry++) {
                if ($retry > 0) usleep(300_000);
                [$ok, $body, $code] = $this->http->get($url, $headers);
                $this->requestCount++;
                if (!$ok || $code >= 500) continue;
                if (preg_match('/var\s+scramble_id\s*=\s*(\d+);/', $body, $m)) {
                    return $m[1];
                }
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
        foreach ($data['series'] ?? [] as $ch) {
            $episodes[] = [
                'photo_id' => (string) $ch['id'],
                'sort'     => (string) ($ch['sort'] ?? '1'),
                'title'    => $ch['name'] ?? '',
            ];
        }

        if (empty($episodes)) {
            $episodes[] = ['photo_id' => (string) $data['id'], 'sort' => '1', 'title' => $self->name];
        } else {
            usort($episodes, fn($a, $b) => (int) $a['sort'] <=> (int) $b['sort']);
            $seen = [];
            $episodes = array_values(array_filter($episodes, fn($ep) => !isset($seen[$ep['sort']]) && ($seen[$ep['sort']] = true)));
        }

        $self->episodes = $episodes;
        return $self;
    }

    /** @return list<string> */
    public function allPhotoIds(): array { return array_column($this->episodes, 'photo_id'); }

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

    public function toArray(?string $albumId = null, ?string $publicBaseUrl = null): array
    {
        $images = array_map(function (array $image) use ($albumId, $publicBaseUrl): array {
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
                $image['url'] = buildDecodedPageUrl($publicBaseUrl, $albumId, $this->photoId, $page);
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
        $sourcePage = max(0, $page - 1);
        $resp = $this->api->callJson(JmConfig::ENDPOINT_LATEST, ['page' => (string) $sourcePage]);
        $payload = is_array($resp['data']) ? $resp['data'] : [];
        return $this->listResultFromItems('latest', $page, $payload, 0);
    }

    public function fetchPopularList(int $page): JmListResult
    {
        $sourcePage = max(0, $page - 1);
        $resp = $this->api->callJson(JmConfig::ENDPOINT_CATEGORY_FILTER, [
            'page' => (string) $sourcePage,
            'c'    => 'latest',
            'o'    => 'mv',
        ]);
        $payload = is_array($resp['data']) ? $resp['data'] : [];
        $items = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : [];
        $total = self::intFromPayload($payload['total'] ?? 0);
        return $this->listResultFromItems('popular', $page, $items, $total);
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

    /** @return array{bytes:string, mime:string, codec:string, cache_hit:bool} */
    public function fetchDecodedPage(string $photoId, int $page): array
    {
        $scrambleId = $this->fetchScrambleId($photoId);
        $chapter = $this->fetchChapter($photoId, $scrambleId);
        $image = $chapter->images[$page - 1] ?? null;

        if ($image === null) {
            throw new SecurityException("页码 {$page} 超出范围 1-{$chapter->pageCount}", 400);
        }

        $segments = (int) ($image['decode_segments'] ?? 0);
        $extension = self::imageExtension((string) ($image['filename'] ?? 'page.jpg'));
        $mime = self::imageMime($extension);
        $cacheKey = self::decodedPageCacheKey($photoId, $page, $image);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['bytes'], $cached['mime'])) {
            return [
                'bytes' => (string) $cached['bytes'],
                'mime' => (string) $cached['mime'],
                'codec' => (string) ($cached['codec'] ?? self::codecFromMime((string) $cached['mime'])),
                'cache_hit' => true,
            ];
        }

        $raw = $this->api->downloadImage($image['url']);

        if ($extension === 'gif' || $segments === 0) {
            $result = [
                'bytes' => $raw,
                'mime' => $mime,
                'codec' => self::codecFromMime($mime),
                'cache_hit' => false,
            ];
            $this->cacheDecodedPage($cacheKey, $result);
            return $result;
        }

        $decoded = ScrambleDecoder::decodeBytesWithInfo($raw, $segments);
        $result = [
            'bytes' => $decoded['bytes'],
            'mime' => $decoded['mime'],
            'codec' => $decoded['codec'],
            'cache_hit' => false,
        ];
        $this->cacheDecodedPage($cacheKey, $result);
        return $result;
    }

    public function maybePrefetchPages(string $photoId, int $page, bool $enabled): void
    {
        $pages = self::envInt('JM_PREFETCH_PAGES', JmConfig::DEFAULT_PREFETCH_PAGES, 0, 30);
        if (!$enabled || $pages <= 0 || !$this->cache->isAvailable()) return;

        register_shutdown_function(function () use ($photoId, $page, $pages): void {
            $this->prefetchDecodedPages($photoId, $page + 1, $pages);
        });
    }

    public function prefetchDecodedPages(string $photoId, int $startPage, int $count): void
    {
        for ($offset = 0; $offset < $count; $offset++) {
            $candidatePage = $startPage + $offset;
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

    private function cacheDecodedPage(string $cacheKey, array $result): void
    {
        $maxBytes = self::envInt(
            'JM_PAGE_CACHE_MAX_ITEM_BYTES',
            self::envInt('JM_PAGE_CACHE_MAX_BYTES', JmConfig::DEFAULT_PAGE_CACHE_MAX_ITEM_BYTES, 0, 512 * 1024 * 1024),
            0,
            512 * 1024 * 1024
        );
        if ($maxBytes > 0 && strlen((string) ($result['bytes'] ?? '')) > $maxBytes) return;

        $this->cache->set(
            $cacheKey,
            [
                'bytes' => (string) $result['bytes'],
                'mime' => (string) $result['mime'],
                'codec' => (string) ($result['codec'] ?? self::codecFromMime((string) $result['mime'])),
            ],
            self::envInt('JM_PAGE_CACHE_TTL', JmConfig::DEFAULT_PAGE_CACHE_TTL, 0, 86400)
        );
    }

    private function isDecodedPageCached(string $photoId, int $page): bool
    {
        $scrambleId = $this->fetchScrambleId($photoId);
        $chapter = $this->fetchChapter($photoId, $scrambleId);
        $image = $chapter->images[$page - 1] ?? null;

        if ($image === null) {
            throw new SecurityException("页码 {$page} 超出范围 1-{$chapter->pageCount}", 400);
        }

        $cacheKey = self::decodedPageCacheKey($photoId, $page, $image);
        $cached = $this->cache->get($cacheKey);
        return is_array($cached) && isset($cached['bytes'], $cached['mime']);
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

    private static function envInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '') return $default;
        if (!preg_match('/^\d+$/', trim((string) $raw))) return $default;
        return min($max, max($min, (int) $raw));
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

function buildDecodedPageUrl(string $baseUrl, string $albumId, string $chapterId, int $page): string
{
    return rtrim($baseUrl, '/') . '/?' . http_build_query([
        'jmid'    => $albumId,
        'chapter' => $chapterId,
        'page'    => (string) $page,
    ]);
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

function isDirectNumericChapterImageRequest(mixed $chapterParam, mixed $pageParam): bool
{
    if (!is_scalar($chapterParam) || !is_scalar($pageParam)) return false;
    $chapter = trim((string) $chapterParam);
    $page = trim((string) $pageParam);
    return $chapter !== '' && $page !== '' && preg_match('/^\d{1,20}$/', $chapter) === 1;
}

/** @return never */
function sendBinaryImage(string $bytes, string $mime, bool $cacheHit = false, string $codec = 'original'): void
{
    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=86400');
    header('X-JM-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
    header('X-JM-Image-Codec: ' . $codec);
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
    $report = [
        'app_version'  => getenv('JM_API_VERSION') ?: JmConfig::APP_VERSION,
        'php'          => PHP_VERSION,
        'apcu'         => $memoryCache->isAvailable(),
        'apcu_details' => $memoryCache->diagnostics(),
        'redis'        => (new RedisStore())->isAvailable(),
        'memory'       => memory_get_usage(true),
    ];
    sendJson(['code' => 200, 'success' => true, 'diagnostics' => $report], false);
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

        $service->maybePrefetchPages($chapterId, $page, isPrefetchEnabled($_GET['prefetch'] ?? '1'));
        sendBinaryImage(
            $image['bytes'],
            $image['mime'],
            (bool) ($image['cache_hit'] ?? false),
            (string) ($image['codec'] ?? 'original')
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
        $service->maybePrefetchPages($fetchIds[0], $page, isPrefetchEnabled($_GET['prefetch'] ?? '1'));
        sendBinaryImage(
            $image['bytes'],
            $image['mime'],
            (bool) ($image['cache_hit'] ?? false),
            (string) ($image['codec'] ?? 'original')
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

    $response = [
        'code'    => 200,
        'success' => true,
        'data'    => [
            'album'            => $album->toArray(),
            'chapters'         => array_map(fn(JmChapter $ch) => $ch->toArray($album->id, $publicBaseUrl), $result['chapters']),
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
