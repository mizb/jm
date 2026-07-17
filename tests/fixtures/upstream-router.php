<?php
declare(strict_types=1);

const DATA_SECRET = '185Hcomic3PAPP7R';
const DOMAIN_SECRET = 'diosfjckwpqpdfjkvnqQjsik';
const DEFAULT_STATS_DIR = '/tmp/jm-fixture-stats';
const PREFETCH_PAGE_OWNER_ACQUIRE_PREFIX = 'prefetch-page-owner-acquire|';
const VALID_IMAGE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=';
const PIXEL_OVER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAACEAAAAhCAIAAADYhlU4AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAKUlEQVRIie3NMQEAAAgDIKf9O/tYwQ8KkPTUs/4OHA6Hw+FwOBwOh+MsA7AASLI2jJ8AAAAASUVORK5CYII=';

function headerValue(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

function cleanRunId(string $value): string
{
    return preg_match('/^[a-f0-9-]{8,64}$/i', $value) === 1 ? strtolower($value) : 'default';
}

function statsDirectory(): string
{
    $configured = trim((string) getenv('JM_FIXTURE_STATS_DIR'));
    return $configured !== '' ? rtrim($configured, "/\\") : DEFAULT_STATS_DIR;
}

function statsPath(string $runId): string
{
    $directory = statsDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create fixture stats directory.');
    }
    if (!chmod($directory, 0777)) {
        throw new RuntimeException('Unable to share fixture stats directory with the API test worker.');
    }
    return $directory . '/' . $runId . '.json';
}

function updateStats(string $runId, string $key): void
{
    $path = statsPath($runId);
    $fp = fopen($path, 'c+');
    if ($fp === false) throw new RuntimeException('Unable to open fixture stats.');
    if (!chmod($path, 0666)) {
        fclose($fp);
        throw new RuntimeException('Unable to share fixture stats with the API test worker.');
    }
    $locked = false;
    try {
        $locked = flock($fp, LOCK_EX);
        if (!$locked) throw new RuntimeException('Unable to lock fixture stats.');
        $raw = stream_get_contents($fp);
        if ($raw === false) throw new RuntimeException('Unable to read fixture stats.');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        $data[$key] = (int) ($data[$key] ?? 0) + 1;
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!ftruncate($fp, 0) || !rewind($fp)) throw new RuntimeException('Unable to reset fixture stats.');
        $written = fwrite($fp, $encoded);
        if ($written === false || $written !== strlen($encoded) || !fflush($fp)) {
            throw new RuntimeException('Unable to write fixture stats.');
        }
    } finally {
        if ($locked && !flock($fp, LOCK_UN)) {
            fclose($fp);
            throw new RuntimeException('Unable to unlock fixture stats.');
        }
        fclose($fp);
    }
}

function readStats(string $runId): array
{
    $path = statsPath($runId);
    if (!is_file($path)) return [];
    $fp = fopen($path, 'r');
    if ($fp === false) return [];
    $locked = false;
    try {
        $locked = flock($fp, LOCK_SH);
        if (!$locked) throw new RuntimeException('Unable to lock fixture stats for reading.');
        $raw = stream_get_contents($fp);
        if ($raw === false) throw new RuntimeException('Unable to read fixture stats.');
    } finally {
        if ($locked && !flock($fp, LOCK_UN)) {
            fclose($fp);
            throw new RuntimeException('Unable to unlock fixture stats after reading.');
        }
        fclose($fp);
    }
    $data = json_decode(is_string($raw) ? $raw : '', true);
    return is_array($data) ? $data : [];
}

function prefetchOwnerSummary(array $counts): array
{
    $pages = [];
    foreach ($counts as $key => $value) {
        if (preg_match('/^prefetch-page-owner-(acquire|release|release-lost|current|peak)\|(\d{1,20})\|(\d+)$/', (string) $key, $match) !== 1) {
            continue;
        }
        $identity = $match[2] . '|' . $match[3];
        if (!isset($pages[$identity])) $pages[$identity] = ['acquire' => 0, 'release' => 0, 'release_lost' => 0, 'current' => 0, 'peak' => 0];
        $field = $match[1] === 'release-lost' ? 'release_lost' : $match[1];
        $pages[$identity][$field] = (int) $value;
    }
    ksort($pages, SORT_STRING);
    return [
        'pages' => (object) $pages,
        'slots' => [
            'acquire' => (int) ($counts['prefetch-slot-acquire'] ?? 0),
            'release' => (int) ($counts['prefetch-slot-release'] ?? 0),
            'release_lost' => (int) ($counts['prefetch-slot-release-lost'] ?? 0),
            'current' => (int) ($counts['prefetch-slot-current'] ?? 0),
            'peak' => (int) ($counts['prefetch-slot-peak'] ?? 0),
        ],
        'callbacks_started' => (int) ($counts['prefetch-callback-start'] ?? 0),
    ];
}

function pkcs7Pad(string $data): string
{
    $pad = 16 - (strlen($data) % 16);
    return $data . str_repeat(chr($pad), $pad);
}

function encryptedEnvelope(array $payload): array
{
    $tokenParam = headerValue('tokenparam');
    $ts = explode(',', $tokenParam, 2)[0] ?? (string) time();
    if (!preg_match('/^\d{9,12}$/', $ts)) $ts = (string) time();
    $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cipher = openssl_encrypt(
        pkcs7Pad($plain === false ? '{}' : $plain),
        'AES-256-ECB',
        md5($ts . DATA_SECRET),
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    );
    return ['code' => 200, 'data' => base64_encode($cipher === false ? '' : $cipher)];
}

function encryptedDomainConfig(array $domains): string
{
    $plain = json_encode(['Server' => $domains], JSON_UNESCAPED_SLASHES);
    $cipher = openssl_encrypt(
        pkcs7Pad($plain === false ? '{}' : $plain),
        'AES-256-ECB',
        md5(DOMAIN_SECRET),
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    );
    return base64_encode($cipher === false ? '' : $cipher);
}

function listItems(int $count): array
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = [
            'id' => (string) (900000 + $i),
            'name' => "Fixture Album {$i}",
            'author' => 'Fixture',
            'image' => '',
            'tags' => ['fixture'],
            'total_views' => 100 + $i,
            'likes' => 10 + $i,
        ];
    }
    return $items;
}

function weeklyListItems(string $categoryId, string $typeId): array
{
    $categoryId = preg_match('/^\d+$/', $categoryId) === 1 ? $categoryId : '0';
    $typeId = preg_match('/^\d+$/', $typeId) === 1 ? $typeId : '0';
    return [[
        'id' => 'week-' . $categoryId . '-' . $typeId,
        'name' => 'Fixture Week ' . $categoryId . '/' . $typeId,
        'author' => 'Fixture',
        'image' => '',
        'tags' => ['fixture'],
        'total_views' => 100,
        'likes' => 10,
    ]];
}

function chapterImagesForScenario(string $scenario): mixed
{
    return match ($scenario) {
        'chapter-images-objects' => array_map(
            static fn(int $i): array => ['image' => sprintf('%05d.png', $i)],
            range(1, 12),
        ),
        'chapter-images-mixed' => array_map(
            static fn(int $i): string|array => ($i % 2) === 0
                ? ['image' => sprintf('%05d.png', $i)]
                : sprintf('%05d.png', $i),
            range(1, 12),
        ),
        'chapter-images-empty' => [],
        'chapter-images-object-empty' => (object) [],
        'chapter-images-object-zero' => (object) ['0' => '00001.png'],
        'chapter-images-malformed' => [
            '00001.png',
            ['image' => null],
            '00003.png',
        ],
        default => array_map(static fn(int $i): string => sprintf('%05d.png', $i), range(1, 12)),
    };
}

function sendJsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$uriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$query = [];
parse_str((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?: ''), $query);
$runId = cleanRunId((string) ($query['run_id'] ?? headerValue('X-JM-Test-Run-Id')));

if ($uriPath === '/__reset') {
    $path = statsPath($runId);
    if (file_exists($path)) {
        if (!is_file($path)) {
            sendJsonResponse(['ok' => false, 'run_id' => $runId, 'error' => 'unable to reset fixture stats'], 500);
        }
        if (!@unlink($path)) {
            sendJsonResponse(['ok' => false, 'run_id' => $runId, 'error' => 'unable to reset fixture stats'], 500);
        }
    }
    if (file_exists($path)) {
        sendJsonResponse(['ok' => false, 'run_id' => $runId, 'error' => 'fixture stats remained after reset'], 500);
    }
    sendJsonResponse(['ok' => true, 'run_id' => $runId]);
}
if ($uriPath === '/__stats') {
    $counts = readStats($runId);
    sendJsonResponse([
        'ok' => true,
        'run_id' => $runId,
        'instance_nonce' => trim((string) getenv('JM_FIXTURE_INSTANCE_NONCE')),
        'pid' => getmypid(),
        'counts' => $counts,
        'prefetch_owners' => prefetchOwnerSummary($counts),
    ]);
}

$host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'fixture')));
$scenario = strtolower(headerValue('X-JM-Test-Scenario'));
if ($scenario === '') $scenario = 'valid';
$allowedScenarios = [
    'valid', 'valid-album', 'valid-album-a', 'valid-album-b', 'valid-album-minimal',
    'malformed-album-series', 'valid-list-80', 'valid-empty-list',
    'valid-search-redirect', 'malformed-200', 'valid-week-defaults', 'valid-weekly-list',
    'week-default-v1', 'week-default-v2', 'week-default-invalid', 'week-only-502',
    'valid-chapter-image', 'valid-image-bytes', 'prefetch-slow',
    'chapter-images-strings', 'chapter-images-objects', 'chapter-images-mixed',
    'chapter-images-empty', 'chapter-images-object-empty', 'chapter-images-object-zero',
    'chapter-images-malformed',
    'comic-read-valid', 'comic-read-objects', 'comic-read-missing-scramble',
    'comic-read-bad-json', 'comic-read-business-error', 'comic-read-not-found',
    'comic-read-timeout',
    'image-body-exact', 'image-body-over', 'image-body-no-length',
    'image-body-chunked-exact', 'image-body-chunked-over',
    'image-body-forged-length', 'image-pixel-over', 'image-invalid',
    'image-http-302', 'image-http-404', 'image-http-408', 'image-http-429',
    'timeout', '502', '502-then-valid', '429-seconds', '429-date', '429-invalid',
    'bad-json', 'bad-encrypted', 'business-error', 'scramble-valid',
    'scramble-template-missing', 'cdn-502',
];
if (!in_array($scenario, $allowedScenarios, true)) {
    sendJsonResponse(['error' => 'unknown fixture scenario', 'scenario' => $scenario], 400);
}
$page = (string) ($query['page'] ?? '');
$counterKey = implode('|', [$host, $uriPath, $page, $scenario]);
updateStats($runId, $counterKey);
if (headerValue('X-JM-Test-Prefetch') === '1' && str_starts_with($uriPath, '/media/photos/')) {
    updateStats($runId, 'prefetch-media|' . $host . '|' . $uriPath);
}

$transparentConfigHosts = [
    'rup4a04-c01.tos-ap-southeast-1.bytepluses.com',
    'rup4a04-c02.tos-cn-hongkong.bytepluses.com',
    'rup4a04-c03.tos-cn-beijing.bytepluses.com.cn',
];
if ($uriPath === '/newsvr-2025.txt' && in_array($host, $transparentConfigHosts, true)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo encryptedDomainConfig([
        'www.cdnhjk.net',
        'www.cdngwc.cc',
        'www.cdngwc.net',
        'www.cdngwc.club',
        'www.cdnutc.me',
    ]);
    exit;
}

if ($host === 'api-timeout' || $scenario === 'timeout') {
    sleep(20);
}
if ($scenario === '502-then-valid' && $host === 'api-good') {
    http_response_code(502);
    echo 'fixture primary 502';
    exit;
}
if ($scenario === 'week-only-502' && $uriPath === '/week') {
    http_response_code(502);
    echo 'fixture week-only 502';
    exit;
}
if (($host === 'api-502' && $scenario !== '502-then-valid' && !str_starts_with($scenario, '429'))
    || $host === 'cdn-fail'
    || ($scenario === 'cdn-502' && $host === '127.0.0.1' && str_starts_with($uriPath, '/media/photos/'))
    || $scenario === '502'
) {
    http_response_code(502);
    echo 'fixture 502';
    exit;
}
if (str_starts_with($scenario, '429') && $host === 'api-good') {
    http_response_code(429);
    if ($scenario === '429-seconds') header('Retry-After: 1');
    if ($scenario === '429-date') header('Retry-After: ' . gmdate('D, d M Y H:i:s', time() + 1) . ' GMT');
    if ($scenario === '429-invalid') header('Retry-After: invalid');
    echo 'rate limited';
    exit;
}
if ($scenario === 'bad-json') {
    header('Content-Type: application/json');
    echo '{bad-json';
    exit;
}
if ($scenario === 'bad-encrypted') {
    sendJsonResponse(['code' => 200, 'data' => 'not-base64!']);
}
if ($scenario === 'business-error') {
    sendJsonResponse(['code' => 403, 'message' => 'fixture business error', 'data' => '']);
}

if ($uriPath === '/comic_read') {
    if ($scenario === 'comic-read-timeout') {
        sleep(20);
    }
    if ($scenario === 'comic-read-bad-json') {
        header('Content-Type: application/json');
        echo '{bad-comic-read-json';
        exit;
    }
    if ($scenario === 'comic-read-business-error') {
        sendJsonResponse(['code' => 403, 'message' => 'fixture comic-read business error', 'data' => '']);
    }
    if ($scenario === 'comic-read-not-found') {
        sendJsonResponse(['code' => 404, 'message' => 'fixture comic-read unavailable'], 404);
    }

    $comicReadImages = $scenario === 'comic-read-objects'
        ? chapterImagesForScenario('chapter-images-objects')
        : chapterImagesForScenario('chapter-images-strings');
    $comicRead = [
        'id' => (string) ($query['id'] ?? '350234'),
        'name' => 'Fixture Comic Read',
        'series_id' => 'fixture-series',
        'total_page' => count($comicReadImages),
        'images' => $comicReadImages,
        'series' => [['id' => (string) ($query['id'] ?? '350234'), 'sort' => '1']],
    ];
    if ($scenario !== 'comic-read-missing-scramble') {
        $comicRead['scramble_id'] = '220980';
    }
    sendJsonResponse(encryptedEnvelope($comicRead));
}

if ($uriPath === '/domain-config-good') {
    header('Content-Type: text/plain; charset=utf-8');
    echo encryptedDomainConfig(['api-good', 'api-502', 'api-timeout']);
    exit;
}
if ($uriPath === '/domain-config-timeout') {
    sleep(5);
    sendJsonResponse(['Server' => []]);
}

if (str_starts_with($uriPath, '/media/photos/')) {
    if ($host === 'cdn-fail') {
        http_response_code(502);
        exit;
    }
    if ($scenario === 'image-http-302') {
        http_response_code(302);
        header('Location: /media/photos/350234/redirected.png');
        echo 'redirect body';
        exit;
    }
    if ($scenario === 'image-http-404') {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    if ($scenario === 'image-http-408' && $host === '127.0.0.1') {
        http_response_code(408);
        echo 'request timeout';
        exit;
    }
    if ($scenario === 'image-http-429') {
        http_response_code(429);
        header('Retry-After: 1');
        echo 'rate limited';
        exit;
    }
    if ($scenario === 'prefetch-slow') usleep(350_000);
    if ($scenario === 'image-invalid') {
        header('Content-Type: application/octet-stream');
        echo 'not-an-image';
        exit;
    }
    $bytes = match ($scenario) {
        'image-pixel-over' => base64_decode(PIXEL_OVER_PNG, true),
        'image-body-over', 'image-body-no-length', 'image-body-forged-length',
        'image-body-chunked-over' => base64_decode(VALID_IMAGE_PNG, true) . 'x',
        default => base64_decode(VALID_IMAGE_PNG, true),
    };
    if (!is_string($bytes)) {
        http_response_code(500);
        exit;
    }
    header('Content-Type: image/png');
    if (in_array($scenario, ['image-body-chunked-exact', 'image-body-chunked-over'], true)) {
        $frameSizes = $scenario === 'image-body-chunked-exact'
            ? [37, 43, 69]
            : [37, 43, 69, 1];
        if (array_sum($frameSizes) !== strlen($bytes)) {
            http_response_code(500);
            exit;
        }
        header_remove('Content-Length');
        header('Transfer-Encoding: chunked');
        $offset = 0;
        foreach ($frameSizes as $frameSize) {
            echo dechex($frameSize), "\r\n", substr($bytes, $offset, $frameSize), "\r\n";
            $offset += $frameSize;
            flush();
        }
        echo "0\r\n\r\n";
        exit;
    }
    if ($scenario === 'image-body-forged-length') {
        header('Content-Length: 1');
    } elseif ($scenario !== 'image-body-no-length') {
        header('Content-Length: ' . strlen($bytes));
    }
    echo $bytes;
    exit;
}

if ($uriPath === '/chapter_view_template') {
    header('Content-Type: text/html; charset=utf-8');
    echo $scenario === 'scramble-template-missing' ? '<html>missing</html>' : '<script>var scramble_id = 220980;</script>';
    exit;
}

$albumName = match ($scenario) {
    'valid-album-a' => 'Fixture Album A',
    'valid-album-b' => 'Fixture Album B',
    default => 'Fixture Album',
};
$weekDefaults = match ($scenario) {
    'week-default-v1' => ['category_id' => '11', 'type_id' => '21'],
    'week-default-v2' => ['category_id' => '12', 'type_id' => '22'],
    'week-default-invalid' => ['category_id' => 'not-an-id', 'type_id' => '22'],
    default => ['category_id' => '1', 'type_id' => '1'],
};
$albumPayload = match ($scenario) {
    'valid-album-minimal' => [
        'name' => 'Fixture Minimal Album',
        'author' => null,
        'description' => null,
        'image' => null,
        'tags' => null,
        'related_list' => null,
    ],
    'malformed-album-series' => [
        'id' => (string) ($query['id'] ?? '350234'),
        'name' => 'Fixture Malformed Series',
        'series' => [
            ['id' => '350234', 'name' => 'Valid Chapter', 'sort' => '1'],
            ['name' => 'Missing ID', 'sort' => '2'],
            'not-an-object',
        ],
    ],
    default => [
        'id' => (string) ($query['id'] ?? '350234'),
        'name' => $albumName,
        'author' => ['Fixture Author'],
        'tags' => ['fixture'],
        'series' => [
            ['id' => '350234', 'name' => 'Fixture Chapter', 'sort' => '1'],
        ],
        'description' => 'Fixture album payload',
        'likes' => 10,
        'total_views' => 100,
    ],
};

$payload = match ($uriPath) {
    '/album' => $albumPayload,
    '/chapter' => [
        'id' => (string) ($query['id'] ?? '350234'),
        'name' => 'Fixture Chapter',
        'series' => [['id' => (string) ($query['id'] ?? '350234'), 'sort' => '1']],
        'images' => chapterImagesForScenario($scenario),
    ],
    '/latest' => listItems($scenario === 'valid-empty-list' ? 0 : 80),
    '/categories/filter' => [
        'content' => $scenario === 'valid-empty-list' ? [] : listItems(80),
        'total' => $scenario === 'malformed-200' ? null : 160,
    ],
    '/search' => $scenario === 'valid-search-redirect'
        ? ['redirect_aid' => '350234']
        : [
            'content' => $scenario === 'valid-empty-list' ? [] : listItems(80),
            'total' => 160,
        ],
    '/promote' => [
        ['title' => 'Fixture', 'content' => listItems(40)],
    ],
    '/promote_list' => [
        'list' => listItems(27),
        'total' => 81,
    ],
    '/week' => [
        'categories' => [['id' => $weekDefaults['category_id'], 'name' => 'Fixture']],
        'type' => [['id' => $weekDefaults['type_id'], 'name' => 'Fixture']],
    ],
    '/week/filter' => [
        'list' => weeklyListItems((string) ($query['id'] ?? ''), (string) ($query['type'] ?? '')),
        'total' => 1,
    ],
    default => [],
};

if ($scenario === 'malformed-200') {
    $payload = ['unexpected' => true];
}

sendJsonResponse(encryptedEnvelope($payload));
