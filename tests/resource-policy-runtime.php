<?php
declare(strict_types=1);

define('JM_API_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/index.php';

function resourceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resourceAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . '\nexpected=' . var_export($expected, true) . '\nactual=' . var_export($actual, true),
        );
    }
}

function resourceContainsObjectValue(mixed $value): bool
{
    if (is_object($value)) return true;
    if (!is_array($value)) return false;
    foreach ($value as $item) {
        if (resourceContainsObjectValue($item)) return true;
    }
    return false;
}

function resourceTruncatedPngHeaderProbe(): string
{
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 1) . pack('N', 1) . "\x08";
}

function resourceTruncatedGifHeaderProbe(): string
{
    return 'GIF89a' . pack('v', 1) . pack('v', 1) . "\x80\x00\x00";
}

function resourceGeneratedImageBytes(string $format): string
{
    resourceAssert(extension_loaded('gd'), 'generated image fixture requires GD');
    $image = imagecreatetruecolor(32, 32);
    resourceAssert($image instanceof GdImage, 'generated image fixture allocation failed');
    $bufferLevel = ob_get_level();
    try {
        $background = imagecolorallocate($image, 20, 40, 60);
        $foreground = imagecolorallocate($image, 220, 180, 40);
        resourceAssert(is_int($background) && is_int($foreground), 'generated image fixture colors failed');
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 8, 8, 23, 23, $foreground);
        ob_start();
        $written = match ($format) {
            'png' => imagepng($image),
            'gif' => imagegif($image),
            'jpeg' => imagejpeg($image, null, 90),
            'webp' => function_exists('imagewebp') ? imagewebp($image, null, 90) : false,
            default => false,
        };
        $bytes = ob_get_clean();
        resourceAssert($written === true && is_string($bytes) && $bytes !== '', "generated {$format} fixture write failed");
        return $bytes;
    } finally {
        while (ob_get_level() > $bufferLevel) {
            // Only a failed fixture writer can leave its local buffer open.
            ob_end_clean();
        }
        imagedestroy($image);
    }
}

function resourceGeneratedGifLzwFixture(): string
{
    resourceAssert(extension_loaded('gd'), 'GIF LZW fixture requires GD');
    $image = imagecreatetruecolor(64, 64);
    resourceAssert($image instanceof GdImage, 'GIF LZW fixture allocation failed');
    $bufferLevel = ob_get_level();
    try {
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $color = imagecolorallocate(
                    $image,
                    ($x * 17 + $y * 3) % 256,
                    ($x * 5 + $y * 11) % 256,
                    ($x * 7 + $y * 19) % 256,
                );
                resourceAssert(is_int($color), 'GIF LZW fixture color allocation failed');
                imagesetpixel($image, $x, $y, $color);
            }
        }
        ob_start();
        $written = imagegif($image);
        $bytes = ob_get_clean();
        resourceAssert($written === true && is_string($bytes) && $bytes !== '', 'GIF LZW fixture write failed');
        return $bytes;
    } finally {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        imagedestroy($image);
    }
}

/** @return list<array{width:int,height:int,min_code_size:int,blocks:list<array{length_offset:int,data_offset:int,length:int}>,terminator_offset:int}> */
function resourceGifImageLayouts(string $bytes): array
{
    $length = strlen($bytes);
    resourceAssert($length >= 14 && str_starts_with($bytes, 'GIF'), 'GIF layout fixture header missing');
    $offset = 13;
    $packed = ord($bytes[10]);
    if (($packed & 0x80) !== 0) $offset += 3 * (2 << ($packed & 0x07));
    $frames = [];
    while ($offset < $length) {
        $type = ord($bytes[$offset]);
        if ($type === 0x3B) return $frames;
        if ($type === 0x21) {
            $offset += 2;
            while ($offset < $length) {
                $blockLength = ord($bytes[$offset++]);
                if ($blockLength === 0) break;
                resourceAssert($blockLength <= $length - $offset, 'GIF extension fixture block overflow');
                $offset += $blockLength;
            }
            continue;
        }
        resourceAssert($type === 0x2C && $offset + 10 <= $length, 'GIF image fixture descriptor missing');
        $widthValue = unpack('vvalue', substr($bytes, $offset + 5, 2));
        $heightValue = unpack('vvalue', substr($bytes, $offset + 7, 2));
        resourceAssert(is_array($widthValue) && is_array($heightValue), 'GIF image fixture dimensions missing');
        $imagePacked = ord($bytes[$offset + 9]);
        $offset += 10;
        if (($imagePacked & 0x80) !== 0) $offset += 3 * (2 << ($imagePacked & 0x07));
        resourceAssert($offset < $length, 'GIF image fixture LZW minimum code size missing');
        $minimumCodeSize = ord($bytes[$offset++]);
        $blocks = [];
        while ($offset < $length) {
            $lengthOffset = $offset;
            $blockLength = ord($bytes[$offset++]);
            if ($blockLength === 0) {
                $frames[] = [
                    'width' => (int) ($widthValue['value'] ?? 0),
                    'height' => (int) ($heightValue['value'] ?? 0),
                    'min_code_size' => $minimumCodeSize,
                    'blocks' => $blocks,
                    'terminator_offset' => $lengthOffset,
                ];
                break;
            }
            resourceAssert($blockLength <= $length - $offset, 'GIF image fixture data block overflow');
            $blocks[] = ['length_offset' => $lengthOffset, 'data_offset' => $offset, 'length' => $blockLength];
            $offset += $blockLength;
        }
    }
    return $frames;
}

function resourceGdPixelDigest(string $bytes): ?string
{
    $image = @imagecreatefromstring($bytes);
    if (!$image instanceof GdImage) return null;
    try {
        $hash = hash_init('sha256');
        for ($y = 0, $height = imagesy($image); $y < $height; $y++) {
            for ($x = 0, $width = imagesx($image); $x < $width; $x++) {
                hash_update($hash, pack('N', imagecolorat($image, $x, $y)));
            }
        }
        return hash_final($hash);
    } finally {
        imagedestroy($image);
    }
}

/** @param list<array{0:int,1:int}> $codes */
function resourcePackGifLzwCodes(array $codes): string
{
    $buffer = 0;
    $bufferBits = 0;
    $bytes = '';
    foreach ($codes as [$code, $width]) {
        resourceAssert($width >= 3 && $width <= 12, 'GIF LZW fixture code width out of range');
        resourceAssert($code >= 0 && $code < (1 << $width), 'GIF LZW fixture code does not fit width');
        $buffer |= $code << $bufferBits;
        $bufferBits += $width;
        while ($bufferBits >= 8) {
            $bytes .= chr($buffer & 0xFF);
            $buffer >>= 8;
            $bufferBits -= 8;
        }
    }
    if ($bufferBits > 0) $bytes .= chr($buffer & 0xFF);
    return $bytes;
}

/** @param list<int>|null $splitLengths */
function resourceGifLzwFrame(int $width, int $height, int $minimumCodeSize, string $payload, ?array $splitLengths = null): string
{
    resourceAssert($width >= 1 && $width <= 65535 && $height >= 1 && $height <= 65535, 'GIF LZW fixture frame dimensions invalid');
    $frame = "\x2C\x00\x00\x00\x00" . pack('v', $width) . pack('v', $height) . "\x00" . chr($minimumCodeSize);
    $offset = 0;
    $payloadLength = strlen($payload);
    $lengths = $splitLengths ?? [];
    foreach ($lengths as $blockLength) {
        resourceAssert($blockLength >= 1 && $blockLength <= 255 && $blockLength <= $payloadLength - $offset, 'GIF LZW fixture split invalid');
        $frame .= chr($blockLength) . substr($payload, $offset, $blockLength);
        $offset += $blockLength;
    }
    while ($offset < $payloadLength) {
        $blockLength = min(255, $payloadLength - $offset);
        $frame .= chr($blockLength) . substr($payload, $offset, $blockLength);
        $offset += $blockLength;
    }
    return $frame . "\x00";
}

/** @param list<string> $frames */
function resourceGifAnimation(array $frames, int $logicalWidth, int $logicalHeight): string
{
    $gif = 'GIF89a' . pack('v', $logicalWidth) . pack('v', $logicalHeight) . "\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF";
    foreach ($frames as $frame) {
        $gif .= "\x21\xF9\x04\x00\x01\x00\x00\x00" . $frame;
    }
    return $gif . "\x3B";
}

function chapterPayload(array $images, string $id = '350234'): array
{
    return [
        'id' => $id,
        'name' => 'Fixture Chapter',
        'series' => [['id' => $id, 'sort' => '1']],
        'images' => $images,
    ];
}

final class ResourceChapterTransport implements UpstreamTransport
{
    public int $calls = 0;
    /** @var list<?int> */
    public array $bodyLimits = [];

    public function __construct(private array $chapterPayload, private string $imageBytes = '') {}

    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult
    {
        $this->calls++;
        $this->bodyLimits[] = $bodyLimitBytes;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (str_starts_with($path, '/media/photos/')) {
            return new HttpResult(true, $this->imageBytes, 200, [], 0, '', ['total_ms' => 1]);
        }
        if ($path === JmConfig::ENDPOINT_SCRAMBLE) {
            return new HttpResult(
                true,
                '<script>var scramble_id = 220980;</script>',
                200,
                [],
                0,
                '',
                ['total_ms' => 1],
            );
        }
        $tokenParam = '';
        foreach ($headers as $header) {
            if (stripos($header, 'tokenparam:') === 0) {
                $tokenParam = trim(substr($header, strlen('tokenparam:')));
                break;
            }
        }
        $timestamp = explode(',', $tokenParam, 2)[0] ?? '';
        resourceAssert(preg_match('/^\d{9,12}$/', $timestamp) === 1, 'chapter fake transport did not receive tokenparam');
        $plain = json_encode($this->chapterPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $padding = 16 - (strlen($plain) % 16);
        $cipher = openssl_encrypt(
            $plain . str_repeat(chr($padding), $padding),
            'AES-256-ECB',
            md5($timestamp . JmConfig::DATA_SECRET),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        resourceAssert(is_string($cipher), 'chapter fake transport encryption failed');
        $body = json_encode(['code' => 200, 'data' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
        return new HttpResult(true, $body, 200, [], 0, '', ['total_ms' => 1]);
    }
}

final class ResourceEncryptedPayloadTransport implements UpstreamTransport
{
    public int $calls = 0;
    /** @var list<string> */
    public array $paths = [];

    public function __construct(private mixed $payload) {}

    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult
    {
        $this->calls++;
        $this->paths[] = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $tokenParam = '';
        foreach ($headers as $header) {
            if (stripos($header, 'tokenparam:') === 0) {
                $tokenParam = trim(substr($header, strlen('tokenparam:')));
                break;
            }
        }
        $timestamp = explode(',', $tokenParam, 2)[0] ?? '';
        resourceAssert(preg_match('/^\d{9,12}$/', $timestamp) === 1, 'payload fake transport did not receive tokenparam');
        $plain = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $padding = 16 - (strlen($plain) % 16);
        $cipher = openssl_encrypt(
            $plain . str_repeat(chr($padding), $padding),
            'AES-256-ECB',
            md5($timestamp . JmConfig::DATA_SECRET),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        resourceAssert(is_string($cipher), 'payload fake transport encryption failed');
        $body = json_encode(['code' => 200, 'data' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
        return new HttpResult(true, $body, 200, [], 0, '', ['total_ms' => 1]);
    }
}

final class ResourceImageDecoder implements ImageDecoder
{
    /** @var list<array{bytes:string,segments:int,max_pixels:int}> */
    public array $calls = [];

    public function __construct(private ImageDecodeResult|Throwable $outcome) {}

    public function decode(string $bytes, int $segments, int $maxPixels): ImageDecodeResult
    {
        $this->calls[] = ['bytes' => $bytes, 'segments' => $segments, 'max_pixels' => $maxPixels];
        if ($this->outcome instanceof Throwable) throw $this->outcome;
        return $this->outcome;
    }
}

final class ResourceImagePayloadValidator implements ImagePayloadValidator
{
    /** @var list<array{bytes:string,width:int,height:int}> */
    public array $calls = [];
    private ?Closure $validator;
    private ?string $lastAttestation = null;

    public function __construct(?callable $validator = null)
    {
        $this->validator = $validator === null ? null : Closure::fromCallable($validator);
    }

    public function isCompleteDecode(string $bytes, int $width, int $height): bool
    {
        $this->calls[] = ['bytes' => $bytes, 'width' => $width, 'height' => $height];
        $valid = $this->validator === null || ($this->validator)($bytes, $width, $height) === true;
        if ($valid) $this->lastAttestation = self::attestation($bytes, $width, $height);
        return $valid;
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

final class ResourceRedisAdapter implements RedisAdapter
{
    public int $connectCalls = 0;
    /** @var list<array{host:string,port:int,connect_timeout_seconds:float,read_timeout_seconds:?float}> */
    public array $connectArguments = [];
    public int $evalCalls = 0;
    /** @var list<array{script:string,args:array,num_keys:int}> */
    public array $evalArguments = [];
    public string $prefix = '';

    public function __construct(
        public bool $connectResult = true,
        public mixed $evalResult = [1, 0, 0],
        public bool $throwOnEval = false,
        public bool $throwOnCommands = false,
    ) {}

    public function connect(
        string $host,
        int $port,
        float $connectTimeoutSeconds,
        ?float $readTimeoutSeconds = null,
    ): bool
    {
        $this->connectCalls++;
        $this->connectArguments[] = [
            'host' => $host,
            'port' => $port,
            'connect_timeout_seconds' => $connectTimeoutSeconds,
            'read_timeout_seconds' => $readTimeoutSeconds,
        ];
        return $this->connectResult;
    }

    public function setPrefix(string $prefix): void { $this->prefix = $prefix; }

    public function eval(string $script, array $arguments, int $numberOfKeys): mixed
    {
        $this->evalCalls++;
        $this->evalArguments[] = ['script' => $script, 'args' => $arguments, 'num_keys' => $numberOfKeys];
        if ($this->throwOnEval) throw new RuntimeException('fixture Redis EVAL failure');
        return $this->evalResult;
    }

    public function setEx(string $key, int $seconds, string $value): bool { $this->maybeThrowCommand(); return true; }
    public function exists(string $key): int { $this->maybeThrowCommand(); return 0; }
    public function increment(string $key): int { $this->maybeThrowCommand(); return 1; }
    public function expire(string $key, int $seconds): bool { $this->maybeThrowCommand(); return true; }
    public function zRemRangeByScore(string $key, string $minimum, string $maximum): int { $this->maybeThrowCommand(); return 0; }
    public function zAdd(string $key, float $score, string $member): int|false { $this->maybeThrowCommand(); return 1; }
    public function zCard(string $key): int { $this->maybeThrowCommand(); return 1; }

    private function maybeThrowCommand(): void
    {
        if ($this->throwOnCommands) throw new RuntimeException('fixture Redis command failure');
    }
}

final class ResourceRedisFactory implements RedisAdapterFactory
{
    public int $createCalls = 0;
    /** @param list<ResourceRedisAdapter> $adapters */
    public function __construct(private array $adapters) {}

    public function create(): RedisAdapter
    {
        $this->createCalls++;
        $adapter = array_shift($this->adapters);
        if (!$adapter instanceof ResourceRedisAdapter) throw new RuntimeException('Unexpected Redis adapter factory call');
        return $adapter;
    }
}

final class ResourceSequenceTransport implements UpstreamTransport
{
    /** @var list<HttpResult> */
    private array $results;
    /** @var list<string> */
    public array $urls = [];
    /** @var list<?int> */
    public array $bodyLimits = [];

    /** @param list<HttpResult> $results */
    public function __construct(array $results)
    {
        $this->results = array_values($results);
    }

    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult
    {
        $this->urls[] = $url;
        $this->bodyLimits[] = $bodyLimitBytes;
        $result = array_shift($this->results);
        if (!$result instanceof HttpResult) {
            throw new RuntimeException('Unexpected extra resource transport call');
        }
        return $result;
    }
}

final class ResourceCdnHealthTransport implements UpstreamTransport
{
    /** @var list<string> */
    public array $urls = [];

    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult
    {
        $this->urls[] = $url;
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        return match ($host) {
            'cdn-primary.test', 'cdn-secondary.test' => resourceHttpResult(502, 'fixture CDN failure'),
            'cdn-third.test' => resourceHttpResult(200, 'third-image'),
            default => throw new RuntimeException('Unexpected CDN fixture host: ' . $host),
        };
    }
}

function resourceHttpResult(int $status, string $body = 'image-bytes', int $curlErrno = 0): HttpResult
{
    return new HttpResult(
        $curlErrno === 0,
        $body,
        $status,
        [],
        $curlErrno,
        $curlErrno === 0 ? '' : 'fixture transport failure',
        ['total_ms' => 1],
    );
}

function testChapterStringsUseOnlyValidatedRelativeMediaPaths(): void
{
    $chapter = JmChapter::fromApiResponse(
        chapterPayload([' 00001.jpg ', '00002.png']),
        '220980',
        '350234',
    );

    resourceAssertSame('350234', $chapter->photoId, 'chapter photo id changed');
    resourceAssertSame(2, $chapter->pageCount, 'chapter page count changed');
    resourceAssertSame([
        [
            'index' => 1,
            'filename' => '00001.jpg',
            'media_path' => '/media/photos/350234/00001.jpg',
            'scramble_id' => '220980',
            'decode_segments' => 2,
        ],
        [
            'index' => 2,
            'filename' => '00002.png',
            'media_path' => '/media/photos/350234/00002.png',
            'scramble_id' => '220980',
            'decode_segments' => 20,
        ],
    ], $chapter->images, 'chapter must store only normalized relative media paths');
}

function testChapterObjectAndMixedImagesNormalizeWithoutDroppingOrReorderingPages(): void
{
    $strings = JmChapter::fromApiResponse(
        chapterPayload(['00001.jpg', '00002.png', '00003.webp']),
        '220980',
        '350234',
    );
    $objects = JmChapter::fromApiResponse(
        chapterPayload([
            ['image' => '00001.jpg', 'extra' => 'metadata'],
            ['image' => '00002.png', 'width' => 32, 'height' => 32],
            ['image' => '00003.webp', 'upstream' => ['ignored' => true]],
        ]),
        '220980',
        '350234',
    );
    $mixed = JmChapter::fromApiResponse(
        chapterPayload([
            '00001.jpg',
            ['image' => '00002.png'],
            '00003.webp',
        ]),
        '220980',
        '350234',
    );

    resourceAssertSame($strings->images, $objects->images, 'object image extra metadata changed normalized pages');
    resourceAssertSame($strings->images, $mixed->images, 'mixed image shape dropped or reordered pages');
    resourceAssertSame(3, $objects->pageCount, 'object image shape changed page count');
    resourceAssertSame(3, $mixed->pageCount, 'mixed image shape changed page count');
}

function testMalformedChapterImagesFailTheWholePayloadWhileExplicitEmptyRemainsValid(): void
{
    $empty = JmChapter::fromApiResponse(chapterPayload([]), '220980', '350234');
    resourceAssertSame(0, $empty->pageCount, 'explicit empty chapter images must remain valid');
    resourceAssertSame([], $empty->images, 'explicit empty chapter images changed');
    resourceAssert(
        ChapterImagePolicy::isValidFilename('第 01 页.png'),
        'valid Unicode filename with internal spaces was rejected',
    );
    resourceAssertSame(
        '%E7%AC%AC%2001%20%E9%A1%B5.png',
        rawurlencode('第 01 页.png'),
        'valid Unicode filename cannot be encoded as one URL path segment',
    );
    resourceAssertSame(
        '/media/photos/350234/%E7%AC%AC%2001%20%E9%A1%B5.png',
        ChapterImagePolicy::canonicalMediaPath('350234', '第 01 页.png'),
        'valid Unicode filename was not canonicalized as one encoded media-path segment',
    );

    $malformed = [
        'missing-images' => null,
        'outer-null' => ['images' => null],
        'outer-string' => ['images' => '00001.jpg'],
        'outer-object' => ['images' => ['image' => '00001.jpg']],
        'object-missing-image' => ['images' => [['name' => '00001.jpg']]],
        'object-empty-image' => ['images' => [['image' => '']]],
        'object-space-image' => ['images' => [['image' => '   ']]],
        'object-null-image' => ['images' => [['image' => null]]],
        'object-number-image' => ['images' => [['image' => 1]]],
        'object-boolean-image' => ['images' => [['image' => true]]],
        'object-array-image' => ['images' => [['image' => ['00001.jpg']]]],
        'object-nested-image' => ['images' => [['image' => ['image' => '00001.jpg']]]],
        'number-item' => ['images' => [1]],
        'boolean-item' => ['images' => [false]],
        'array-item' => ['images' => [[]]],
        'control-filename' => ['images' => ["00001\x00.jpg"]],
        'leading-nul-filename' => ['images' => ["\x00" . '00001.jpg']],
        'leading-lf-filename' => ['images' => ["\n00001.jpg"]],
        'trailing-tab-filename' => ['images' => ["00001.jpg\t"]],
        'object-leading-nul-filename' => ['images' => [['image' => "\x00" . '00001.jpg']]],
        'object-leading-lf-filename' => ['images' => [['image' => "\n00001.jpg"]]],
        'object-trailing-tab-filename' => ['images' => [['image' => "00001.jpg\t"]]],
        'unicode-c1-control-filename' => ['images' => ["00001\u{0085}.jpg"]],
        'object-unicode-c1-control-filename' => ['images' => [['image' => "00001\u{0085}.jpg"]]],
        'slash-filename' => ['images' => ['nested/00001.jpg']],
        'backslash-filename' => ['images' => ['nested\\00001.jpg']],
        'dotdot-filename' => ['images' => ['00001..jpg']],
        'percent-encoded-traversal' => ['images' => ['%2e%2e%2fsecret.jpg']],
        'percent-encoded-mixed-case' => ['images' => ['%2E%2e%5csecret.jpg']],
        'percent-double-encoded-traversal' => ['images' => ['%252e%252e%252fsecret.jpg']],
        'percent-encoded-control' => ['images' => ['00001%00.jpg']],
        'query-filename' => ['images' => ['00001.jpg?token=abc']],
        'fragment-filename' => ['images' => ['00001.jpg#fragment']],
        'invalid-overlong-utf8-traversal' => ['images' => ["\xC0\xAE\xC0\xAE\xC0\xAFsecret.jpg"]],
    ];

    foreach ($malformed as $name => $override) {
        $payload = chapterPayload(['sentinel.jpg']);
        if ($override === null) {
            unset($payload['images']);
        } else {
            $payload = array_replace($payload, $override);
        }

        $thrown = null;
        try {
            JmChapter::fromApiResponse($payload, '220980', '350234');
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
            "malformed chapter image case {$name} did not fail as a 502 validation error",
        );
    }
}

function testChapterJsonObjectContainersFailThroughEncryptedProductionChainWithoutCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'chapter JSON-container test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    try {
        $cases = [
            'empty-object' => (object) [],
            'numeric-zero-object' => (object) ['0' => '00001.jpg'],
        ];
        foreach ($cases as $name => $images) {
            apcu_clear_cache();
            $payload = chapterPayload(['sentinel.jpg']);
            $payload['images'] = $images;
            $context = RequestContext::forTest('chapter-json-object-' . $name, 12000, 6);
            $transport = new ResourceChapterTransport($payload);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $service->fetchReaderManifest('350234');
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
                    "JSON images {$name} attempt {$attempt} was accepted after associative decoding",
                );
            }

            resourceAssertSame(3, $transport->calls, "JSON images {$name} was cached instead of refetched");
            $namespace = $context->testCacheNamespace();
            $chapterKey = $namespace . 'chapter:v2:' . hash('sha256', '350234:220980');
            $manifestKey = $namespace . 'manifest:v2:' . hash('sha256', '350234:220980');
            resourceAssertSame(null, $cache->get($chapterKey), "JSON images {$name} entered chapter v2 cache");
            resourceAssertSame(null, $cache->get($manifestKey), "JSON images {$name} entered manifest v2 cache");
        }
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testRootJsonListProvenanceRejectsObjectsAndCachesOnlyTrueEmptyLists(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'root JSON-list provenance test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_LIST_CACHE_TTL=60');

    $item = ['id' => '900001', 'name' => 'Fixture'];
    $cases = [
        'latest-empty-object' => [(object) [], static fn(JmService $service): JmListResult => $service->fetchLatestList(1)],
        'latest-numeric-object' => [(object) ['0' => $item], static fn(JmService $service): JmListResult => $service->fetchLatestList(1)],
        'promote-empty-object' => [(object) [], static fn(JmService $service): JmListResult => $service->fetchPromoteList(1)],
        'promote-numeric-object' => [
            (object) ['0' => ['title' => 'Fixture', 'content' => []]],
            static fn(JmService $service): JmListResult => $service->fetchPromoteList(1),
        ],
    ];

    try {
        foreach ($cases as $name => [$payload, $invoke]) {
            apcu_clear_cache();
            $context = RequestContext::forTest('json-root-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport($payload);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $invoke($service);
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof JmException && $thrown->getCode() === 502,
                    "{$name} attempt {$attempt} was accepted as a JSON list",
                );
            }
            resourceAssertSame(2, $transport->calls, "{$name} malformed payload entered source cache");
        }

        foreach ([
            'latest-empty-list' => static fn(JmService $service): JmListResult => $service->fetchLatestList(1),
            'promote-empty-list' => static fn(JmService $service): JmListResult => $service->fetchPromoteList(1),
        ] as $name => $invoke) {
            apcu_clear_cache();
            $context = RequestContext::forTest('json-root-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport([]);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);
            resourceAssertSame([], $invoke($service)->items, "{$name} changed the legal empty result");
            resourceAssertSame([], $invoke($service)->items, "{$name} cache hit changed the legal empty result");
            resourceAssertSame(1, $transport->calls, "{$name} was not cached as a legal empty list");
        }
    } finally {
        putenv('JM_LIST_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testNestedJsonListProvenanceRejectsObjectsAndCachesOnlyTrueEmptyLists(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'nested JSON-list provenance test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_LIST_CACHE_TTL=60');
    putenv('JM_SEARCH_CACHE_TTL=60');
    putenv('JM_WEEKLY_LIST_CACHE_TTL=60');

    $item = ['id' => '900001', 'name' => 'Fixture'];
    $cases = [
        'content-empty-object' => [
            ['content' => (object) [], 'total' => 0],
            static fn(JmService $service): JmListResult => $service->fetchPopularList(1),
        ],
        'content-numeric-object' => [
            ['content' => (object) ['0' => $item], 'total' => 1],
            static fn(JmService $service): JmListResult => $service->searchAlbums('fixture', 1),
        ],
        'list-empty-object' => [
            ['list' => (object) [], 'total' => 0],
            static fn(JmService $service): JmListResult => $service->fetchWeeklyList(1, '1', '1'),
        ],
        'list-numeric-object' => [
            ['list' => (object) ['0' => $item], 'total' => 1],
            static fn(JmService $service): JmListResult => $service->fetchPromoteList(1, 1),
        ],
        'promote-content-empty-object' => [
            [['title' => 'Fixture', 'content' => (object) []]],
            static fn(JmService $service): JmListResult => $service->fetchPromoteList(1),
        ],
        'promote-content-numeric-object' => [
            [['title' => 'Fixture', 'content' => (object) ['0' => $item]]],
            static fn(JmService $service): JmListResult => $service->fetchPromoteList(1),
        ],
    ];

    try {
        foreach ($cases as $name => [$payload, $invoke]) {
            apcu_clear_cache();
            $context = RequestContext::forTest('json-nested-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport($payload);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $invoke($service);
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof JmException && $thrown->getCode() === 502,
                    "{$name} attempt {$attempt} was accepted as a nested JSON list",
                );
            }
            resourceAssertSame(2, $transport->calls, "{$name} malformed payload entered source cache");
        }

        $valid = [
            'content-empty-list' => [
                ['content' => [], 'total' => 0],
                static fn(JmService $service): JmListResult => $service->fetchPopularList(1),
            ],
            'list-empty-list' => [
                ['list' => [], 'total' => 0],
                static fn(JmService $service): JmListResult => $service->fetchWeeklyList(1, '1', '1'),
            ],
            'promote-content-empty-list' => [
                [['title' => 'Fixture', 'content' => []]],
                static fn(JmService $service): JmListResult => $service->fetchPromoteList(1),
            ],
        ];
        foreach ($valid as $name => [$payload, $invoke]) {
            apcu_clear_cache();
            $context = RequestContext::forTest('json-nested-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport($payload);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);
            resourceAssertSame([], $invoke($service)->items, "{$name} changed the legal empty result");
            resourceAssertSame([], $invoke($service)->items, "{$name} cache hit changed the legal empty result");
            resourceAssertSame(1, $transport->calls, "{$name} was not cached as a legal empty list");
        }
    } finally {
        foreach (['JM_WEEKLY_LIST_CACHE_TTL', 'JM_SEARCH_CACHE_TTL', 'JM_LIST_CACHE_TTL', 'JM_TEST_ALLOWED_HOSTS'] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testListItemJsonListFieldsRejectObjectsBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'list-item JSON-list provenance test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_LIST_CACHE_TTL=60');

    try {
        $cases = [
            'tags-empty-object' => ['tags' => (object) []],
            'tags-numeric-zero-object' => ['tags' => (object) ['0' => 'Tag']],
            'tags-numeric-one-object' => ['tags' => (object) ['1' => 'Tag']],
            'works-numeric-object' => ['works' => (object) ['0' => 'Work']],
            'actors-numeric-object' => ['actors' => (object) ['0' => 'Actor']],
        ];
        foreach ($cases as $name => $fields) {
            apcu_clear_cache();
            $payload = [array_merge(['id' => '900001', 'name' => 'Fixture'], $fields)];
            $context = RequestContext::forTest('json-list-item-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport($payload);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $service->fetchLatestList(1);
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof JmException && $thrown->getCode() === 502,
                    "list item {$name} attempt {$attempt} accepted a JSON object as a list",
                );
            }
            resourceAssertSame(2, $transport->calls, "list item {$name} malformed payload entered source cache");
        }

        apcu_clear_cache();
        $context = RequestContext::forTest('json-list-item-valid', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport([[
            'id' => '900001',
            'name' => 'Fixture',
            'tags' => [],
            'metadata' => (object) ['label' => 'allowed-object'],
        ]]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        resourceAssertSame([], $service->fetchLatestList(1)->items[0]->tags ?? null, 'true list item tag list changed');
        resourceAssertSame([], $service->fetchLatestList(1)->items[0]->tags ?? null, 'cached true list item tag list changed');
        resourceAssertSame(1, $transport->calls, 'valid list item payload was not cached');
    } finally {
        putenv('JM_LIST_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testListItemIdsMustBeCanonicalJmIdsBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'list-item ID validation test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_LIST_CACHE_TTL=60');
    putenv('JM_SEARCH_CACHE_TTL=60');

    try {
        apcu_clear_cache();
        $context = RequestContext::forTest('list-item-id-invalid', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport([[
            'id' => '900001oops',
            'name' => 'Malformed list item',
        ]]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $thrown = null;
            try {
                $service->fetchLatestList(1);
            } catch (Throwable $error) {
                $thrown = $error;
            }
            resourceAssert(
                $thrown instanceof JmException && $thrown->getCode() === 502,
                "non-canonical list item ID attempt {$attempt} was exposed to clients",
            );
        }
        resourceAssertSame(2, $transport->calls, 'non-canonical list item ID entered source cache');

        apcu_clear_cache();
        $context = RequestContext::forTest('search-redirect-id-invalid', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport(['redirect_aid' => '900001oops']);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $thrown = null;
            try {
                $service->searchAlbums('malformed redirect', 1);
            } catch (Throwable $error) {
                $thrown = $error;
            }
            resourceAssert(
                $thrown instanceof JmException && $thrown->getCode() === 502,
                "non-canonical search redirect ID attempt {$attempt} was exposed to clients",
            );
        }
        resourceAssertSame(2, $transport->calls, 'non-canonical search redirect ID entered source cache');

        apcu_clear_cache();
        $context = RequestContext::forTest('list-item-id-valid', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport([[
            'id' => '12345678901234567890',
            'name' => 'Boundary list item',
        ]]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        resourceAssertSame(
            '12345678901234567890',
            $service->fetchLatestList(1)->items[0]->id ?? null,
            'valid 20-digit list item ID changed',
        );
    } finally {
        putenv('JM_SEARCH_CACHE_TTL');
        putenv('JM_LIST_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testAlbumJsonListFieldsRejectObjectsBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'album JSON-list provenance test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_ALBUM_CACHE_TTL=60');

    $basePayload = ['id' => '900001', 'name' => 'Fixture Album'];
    $cases = [];
    foreach (['author', 'tags', 'works', 'actors'] as $field) {
        $cases[$field . '-empty-object'] = [$field => (object) []];
        $cases[$field . '-numeric-zero-object'] = [$field => (object) ['0' => ucfirst($field)]];
    }
    $cases['tags-numeric-one-object'] = ['tags' => (object) ['1' => 'Tag']];

    try {
        foreach ($cases as $name => $fields) {
            apcu_clear_cache();
            $context = RequestContext::forTest('json-album-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport(array_merge($basePayload, $fields));
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $service->fetchAlbum('900001');
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof JmException && $thrown->getCode() === 502,
                    "album {$name} attempt {$attempt} accepted a JSON object as a list",
                );
            }
            resourceAssertSame(2, $transport->calls, "album {$name} malformed payload entered metadata cache");
            $cacheKey = $context->testCacheNamespace() . 'album:v1:' . hash('sha256', '900001');
            resourceAssertSame(null, $cache->get($cacheKey), "album {$name} entered metadata cache");
        }

        apcu_clear_cache();
        $context = RequestContext::forTest('json-album-valid', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport($basePayload + [
            'author' => 'Fixture Author',
            'tags' => [],
            'works' => [],
            'actors' => [],
            'related_list' => [],
            'series' => [],
        ]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        resourceAssertSame(['Fixture Author'], $service->fetchAlbum('900001')->author, 'valid scalar album author changed');
        resourceAssertSame([], $service->fetchAlbum('900001')->tags, 'cached true album tag list changed');
        resourceAssertSame(1, $transport->calls, 'valid album list fields were not cached');
    } finally {
        putenv('JM_ALBUM_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testChapterRootAndSeriesJsonContainersFailSafelyBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'chapter root/series JSON-container test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    $seriesEmpty = chapterPayload(['00001.jpg']);
    $seriesEmpty['series'] = (object) [];
    $seriesZero = chapterPayload(['00001.jpg']);
    $seriesZero['series'] = (object) ['0' => ['id' => '350234', 'sort' => '2']];
    $seriesOne = chapterPayload(['00001.jpg']);
    $seriesOne['series'] = (object) ['1' => ['id' => '350234', 'sort' => '2']];
    $cases = [
        'root-empty-object' => (object) [],
        'root-numeric-object' => (object) ['0' => chapterPayload(['00001.jpg'])],
        'series-empty-object' => $seriesEmpty,
        'series-numeric-zero-object' => $seriesZero,
        'series-numeric-one-object' => $seriesOne,
    ];

    try {
        foreach ($cases as $name => $payload) {
            apcu_clear_cache();
            $context = RequestContext::forTest('json-chapter-' . $name, 12000, 10);
            $transport = new ResourceEncryptedPayloadTransport($payload);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $service->fetchChapter('350234', '220980');
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
                    "chapter {$name} attempt {$attempt} was not rejected as a safe 502",
                );
            }
            resourceAssertSame(2, $transport->calls, "chapter {$name} malformed payload entered chapter cache");
            $cacheKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:220980');
            resourceAssertSame(null, $cache->get($cacheKey), "chapter {$name} entered chapter cache");
        }

        apcu_clear_cache();
        $validPayload = chapterPayload(['00001.jpg']);
        $validPayload['series'] = [];
        $context = RequestContext::forTest('json-chapter-valid-series', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport($validPayload);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        resourceAssertSame('1', $service->fetchChapter('350234', '220980')->sort, 'true empty chapter series changed');
        resourceAssertSame('1', $service->fetchChapter('350234', '220980')->sort, 'cached true empty chapter series changed');
        resourceAssertSame(1, $transport->calls, 'valid chapter series was not cached');
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testChapterImageUnknownJsonMetadataIsIgnoredBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'chapter metadata projection test requires APCu CLI');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    $manifestProjection = static function (array $manifest): array {
        if (isset($manifest['images']) && is_array($manifest['images'])) {
            foreach ($manifest['images'] as &$image) {
                if (is_array($image)) unset($image['cache_key']);
            }
            unset($image);
        }
        return $manifest;
    };

    $run = static function (array $images, string $scenario) use ($cache): array {
        apcu_clear_cache();
        $context = RequestContext::forTest($scenario, 12000, 10);
        $transport = new ResourceChapterTransport(chapterPayload($images));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);

        $thrown = null;
        $chapter = null;
        $manifest = null;
        try {
            $chapter = $service->fetchChapter('350234', '220980');
            $manifest = $service->fetchReaderManifest('350234');
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown === null, "{$scenario} rejected ignored chapter image metadata");
        resourceAssert($chapter instanceof JmChapter && is_array($manifest), "{$scenario} did not produce chapter and manifest models");
        resourceAssertSame(
            $chapter->toCachePayload(),
            $service->fetchChapter('350234', '220980')->toCachePayload(),
            "{$scenario} chapter cache hit changed the normalized payload",
        );
        resourceAssertSame(
            $manifest,
            $service->fetchReaderManifest('350234'),
            "{$scenario} manifest cache hit changed the normalized payload",
        );
        resourceAssertSame(2, $transport->calls, "{$scenario} repeated request bypassed chapter/manifest caches");

        $namespace = $context->testCacheNamespace();
        $chapterKey = $namespace . 'chapter:v2:' . hash('sha256', '350234:220980');
        $manifestKey = $namespace . 'manifest:v2:' . hash('sha256', '350234:220980');
        return [
            'chapter' => $chapter->toCachePayload(),
            'manifest' => $manifest,
            'chapter_cache' => $cache->get($chapterKey),
            'manifest_cache' => $cache->get($manifestKey),
        ];
    };

    try {
        $baseline = $run([
            ['image' => '00001.jpg'],
        ], 'json-chapter-image-metadata-baseline');
        $withMetadata = $run([[
            'image' => '00001.jpg',
            'extra_metadata' => (object) [],
            'numeric_metadata' => (object) ['0' => 'ignored'],
        ]], 'json-chapter-image-metadata-extra');

        resourceAssertSame($baseline['chapter'], $withMetadata['chapter'], 'chapter image metadata changed normalized chapter output');
        resourceAssertSame(
            $manifestProjection($baseline['manifest']),
            $manifestProjection($withMetadata['manifest']),
            'chapter image metadata changed normalized manifest output',
        );
        resourceAssertSame($withMetadata['chapter'], $withMetadata['chapter_cache'], 'chapter metadata leaked outside the strict chapter cache projection');
        resourceAssertSame($withMetadata['manifest'], $withMetadata['manifest_cache'], 'chapter metadata leaked outside the strict manifest cache projection');
        resourceAssert(!resourceContainsObjectValue($withMetadata['chapter_cache']), 'chapter cache retained an object provenance marker');
        resourceAssert(!resourceContainsObjectValue($withMetadata['manifest_cache']), 'manifest cache retained an object provenance marker');
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testListItemUnknownJsonMetadataIsProjectedOutBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'list-item metadata projection test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_LIST_CACHE_TTL=60');

    try {
        $payload = [[
            'id' => '900001',
            'name' => 'Fixture',
            'author' => 'Fixture Author',
            'description' => 'Fixture Description',
            'image' => 'cover.jpg',
            'tags' => ['Tag'],
            'works' => [],
            'actors' => [],
            'likes' => '7',
            'total_views' => '11',
            'updated_at' => '13',
            'metadata' => (object) [],
            'numeric_metadata' => (object) ['0' => 'ignored'],
        ]];
        $context = RequestContext::forTest('json-list-item-metadata-projection', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport($payload);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);

        $thrown = null;
        $first = null;
        try {
            $first = $service->fetchLatestList(1);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown === null, 'unknown list-item JSON metadata was rejected');
        resourceAssert($first instanceof JmListResult && isset($first->items[0]), 'list-item metadata fixture produced no public item');
        $second = $service->fetchLatestList(1);
        resourceAssertSame($first->items[0]->toArray(), $second->items[0]->toArray(), 'list-item metadata cache hit changed public output');
        resourceAssertSame(1, $transport->calls, 'list-item metadata payload was not served from source cache on repeat');

        $keyFields = ['endpoint' => JmConfig::ENDPOINT_LATEST, 'source_page' => 0];
        $sourceKey = $context->testCacheNamespace() . 'list-source:v1:' . hash('sha256', json_encode(
            $keyFields,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
        $cached = $cache->get($sourceKey);
        resourceAssert(is_array($cached) && isset($cached[0]) && is_array($cached[0]), 'list-item metadata source cache was missing');
        resourceAssertSame([
            'id', 'name', 'author', 'description', 'image', 'tags', 'works', 'actors',
            'likes', 'total_views', 'updated_at',
        ], array_keys($cached[0]), 'list-item source cache retained unknown or noncanonical fields');
        resourceAssertSame([
            'id' => '900001',
            'name' => 'Fixture',
            'author' => 'Fixture Author',
            'description' => 'Fixture Description',
            'image' => 'cover.jpg',
            'tags' => ['Tag'],
            'works' => [],
            'actors' => [],
            'likes' => 7,
            'total_views' => 11,
            'updated_at' => 13,
        ], $cached[0], 'list-item source cache changed known projected fields');
        resourceAssert(!resourceContainsObjectValue($cached), 'list-item source cache retained an object provenance marker');
        resourceAssertSame([
            'id', 'name', 'author', 'description', 'image', 'tags', 'likes', 'total_views', 'updated_at',
        ], array_keys($first->items[0]->toArray()), 'public list item leaked unknown metadata fields');
    } finally {
        putenv('JM_LIST_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testAlbumUnknownJsonMetadataIsProjectedOutBeforeCaching(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'album metadata projection test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_ALBUM_CACHE_TTL=60');

    try {
        $payload = [
            'id' => '900001',
            'name' => 'Fixture Album',
            'author' => 'Fixture Author',
            'tags' => [],
            'works' => [],
            'actors' => [],
            'related_list' => [],
            'series' => [],
            'metadata' => (object) [],
            'numeric_metadata' => (object) ['0' => 'ignored'],
        ];
        $context = RequestContext::forTest('json-album-metadata-projection', 12000, 10);
        $transport = new ResourceEncryptedPayloadTransport($payload);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);

        $thrown = null;
        $first = null;
        try {
            $first = $service->fetchAlbum('900001');
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown === null, 'unknown album JSON metadata was rejected');
        resourceAssert($first instanceof JmAlbum, 'album metadata fixture produced no public model');
        $second = $service->fetchAlbum('900001');
        resourceAssertSame($first->toArray(), $second->toArray(), 'album metadata cache hit changed public output');
        resourceAssertSame(1, $transport->calls, 'album metadata payload was not served from metadata cache on repeat');

        $cacheKey = $context->testCacheNamespace() . 'album:v1:' . hash('sha256', '900001');
        $cached = $cache->get($cacheKey);
        resourceAssertSame([
            'id' => '900001',
            'name' => 'Fixture Album',
            'author' => ['Fixture Author'],
            'description' => '',
            'image' => '',
            'total_views' => '0',
            'likes' => '0',
            'comment_total' => '0',
            'tags' => [],
            'works' => [],
            'actors' => [],
            'related_list' => [],
            'series' => [],
        ], $cached, 'album metadata cache retained unknown fields or changed the canonical projection');
        resourceAssert(!resourceContainsObjectValue($cached), 'album metadata cache retained an object provenance marker');
        resourceAssertSame([
            'album_id', 'name', 'image', 'author', 'description', 'total_views', 'likes',
            'comments', 'tags', 'works', 'actors', 'related', 'chapters',
        ], array_keys($first->toArray()), 'public album leaked unknown metadata fields');
    } finally {
        putenv('JM_ALBUM_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testChapterPhotoIdMustBeOneToTwentyDigitsAndMatchTheRequestedId(): void
{
    foreach (['1', str_repeat('9', 20)] as $validId) {
        $chapter = JmChapter::fromApiResponse(chapterPayload([], $validId), '220980', $validId);
        resourceAssertSame($validId, $chapter->photoId, 'valid chapter id boundary changed');
    }

    $numericPayload = chapterPayload([]);
    $numericPayload['id'] = 350234;
    $numericChapter = JmChapter::fromApiResponse($numericPayload, '220980', '350234');
    resourceAssertSame('350234', $numericChapter->photoId, 'integer upstream chapter id was not normalized safely');

    foreach (['missing', 'null', 'empty', 'space'] as $fallbackCase) {
        $payload = chapterPayload([]);
        if ($fallbackCase === 'missing') unset($payload['id']);
        if ($fallbackCase === 'null') $payload['id'] = null;
        if ($fallbackCase === 'empty') $payload['id'] = '';
        if ($fallbackCase === 'space') $payload['id'] = '   ';
        $chapter = JmChapter::fromApiResponse($payload, '220980', '350234');
        resourceAssertSame('350234', $chapter->photoId, "chapter {$fallbackCase} id did not fall back to requested id");
    }

    $invalid = [
        'twenty-one-digits' => str_repeat('9', 21),
        'letters' => '35a234',
        'float' => 350234.0,
        'boolean' => true,
        'array' => ['350234'],
    ];
    foreach ($invalid as $name => $id) {
        $payload = chapterPayload([]);
        $payload['id'] = $id;
        $thrown = null;
        try {
            JmChapter::fromApiResponse($payload, '220980', '350234');
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
            "invalid chapter id case {$name} was accepted",
        );
    }

    $thrown = null;
    try {
        JmChapter::fromApiResponse(chapterPayload([], '350235'), '220980', '350234');
    } catch (Throwable $error) {
        $thrown = $error;
    }
    resourceAssert(
        $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
        'chapter response id did not have to match the requested id',
    );
}

function testInvalidScrambleIdsNeverEnterChapterOrManifestCaches(): void
{
    foreach (['', 'bad', '220980 ', str_repeat('9', 21)] as $invalidScrambleId) {
        $thrown = null;
        try {
            JmChapter::fromApiResponse(chapterPayload(['00001.jpg']), $invalidScrambleId, '350234');
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
            'invalid scramble id entered a chapter model: ' . var_export($invalidScrambleId, true),
        );
    }

    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'scramble cache poison test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');

    $context = RequestContext::forTest('scramble-cache-poison', 12000, 6);
    $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg']));
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $service = new JmService(context: $context, api: $api, cache: $cache);
    $scrambleKey = $context->testCacheNamespace() . 'scramble:' . md5('350234');
    $cache->set($scrambleKey, 'bad', 60);

    $manifest = $service->fetchReaderManifest('350234');
    resourceAssertSame('220980', $manifest['scramble_id'] ?? null, 'poisoned scramble cache value reached the manifest');
    resourceAssertSame(2, $manifest['images'][0]['decode_segments'] ?? null, 'poisoned scramble cache changed decode segments');
    resourceAssertSame('220980', $cache->get($scrambleKey), 'poisoned scramble cache value was not replaced');
    resourceAssertSame(2, $transport->calls, 'poisoned scramble cache did not refetch scramble and chapter exactly once');
    $poisonedChapterKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:bad');
    resourceAssertSame(null, $cache->get($poisonedChapterKey), 'poisoned scramble id entered the chapter cache');
}

function testChapterPublicJsonMaterializesStableAbsoluteSourceWithoutLeakingMediaPath(): void
{
    putenv('JM_TEST_MODE=0');
    $chapter = JmChapter::fromApiResponse(chapterPayload(['00001.jpg']), '220980', '350234');
    $expected = [
        'photo_id' => '350234',
        'title' => 'Fixture Chapter',
        'sort' => '1',
        'page_count' => 1,
        'images' => [[
            'index' => 1,
            'filename' => '00001.jpg',
            'url' => 'https://api.example.test/base/?jmid=350200&chapter=350234&page=1&next_chapter=350235',
            'source_url' => 'https://cdn-msp.jmapiproxy1.cc/media/photos/350234/00001.jpg',
            'mime' => ScrambleDecoder::preferredDecodedMime(),
            'scramble_id' => '220980',
            'decode_segments' => 2,
        ]],
    ];

    $first = $chapter->toArray('350200', 'https://api.example.test/base', '350235');
    $second = $chapter->toArray('350200', 'https://api.example.test/base', '350235');
    resourceAssertSame($expected, $first, 'chapter public JSON fields or absolute URLs changed');
    resourceAssertSame($first, $second, 'chapter source URL was not stable for the same input');
    resourceAssert(!array_key_exists('media_path', $first['images'][0]), 'internal media_path leaked into public JSON');
}

function testCoverCdnSelectionIsStableAndEpochControlledWhileAbsoluteUrlsArePreserved(): void
{
    $absolute = 'https://images.example.test/upstream.jpg?x=1';
    resourceAssertSame($absolute, buildCoverUrl('350234', $absolute), 'absolute upstream cover URL changed');
    $absoluteHttp = 'http://images.example.test/upstream.jpg';
    resourceAssertSame($absoluteHttp, buildCoverUrl('350234', $absoluteHttp), 'compatible HTTP upstream cover URL changed');

    putenv('JM_CDN_EPOCH=1');
    $fallbackIndex = ((int) sprintf('%u', crc32('350234:1'))) % count(JmConfig::CDN_DOMAINS);
    $safeFallback = 'https://' . JmConfig::CDN_DOMAINS[$fallbackIndex] . '/media/albums/350234_3x4.jpg';
    foreach ([
        'https://user:pass@images.example.test/upstream.jpg',
        "\x00https://images.example.test/upstream.jpg",
        "\thttps://images.example.test/upstream.jpg",
        "https://images.example.test/upstream.jpg\n",
        "https://images.example.test/a\x01b.jpg",
        "\t/media/albums/custom.jpg",
        "/media/albums/a\x01b.jpg",
        "/media/albums/a\u{0085}b.jpg",
        "/media/albums/custom.jpg\t",
        "/media/albums/a\xC3\x28.jpg",
        'https://',
        'https://images.example.test:70000/upstream.jpg',
        'https://images.example.test/upstream.jpg#fragment',
    ] as $unsafeAbsoluteCover) {
        resourceAssertSame($safeFallback, buildCoverUrl('350234', $unsafeAbsoluteCover), 'unsafe absolute cover URL was preserved');
    }

    foreach (['1', 'next-epoch'] as $epoch) {
        putenv('JM_CDN_EPOCH=' . $epoch);
        $index = ((int) sprintf('%u', crc32('350234:' . $epoch))) % count(JmConfig::CDN_DOMAINS);
        $base = 'https://' . JmConfig::CDN_DOMAINS[$index];
        $expectedDefault = $base . '/media/albums/350234_3x4.jpg';
        $expectedRelative = $base . '/media/albums/custom.jpg';

        for ($i = 0; $i < 20; $i++) {
            resourceAssertSame($expectedDefault, buildCoverUrl('350234', ''), 'default cover CDN was not stable');
            resourceAssertSame($expectedRelative, buildCoverUrl('350234', '/media/albums/custom.jpg'), 'relative cover CDN was not stable');
            resourceAssertSame($expectedRelative, buildCoverUrl('350234', 'media/albums/custom.jpg'), 'relative cover normalization changed');
        }
    }
    putenv('JM_CDN_EPOCH');
}

function testChapterV2CachePayloadRoundTripsOnlyValidatedRelativePaths(): void
{
    $chapter = JmChapter::fromApiResponse(
        chapterPayload(['00001.jpg', ['image' => '00002.png']]),
        '220980',
        '350234',
    );
    $payload = $chapter->toCachePayload();

    resourceAssertSame('chapter-v2', $payload['schema'] ?? null, 'chapter cache schema changed');
    resourceAssertSame($chapter->images, $payload['images'] ?? null, 'chapter v2 cache did not preserve relative images');
    resourceAssert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'https://'), 'chapter cache stored an absolute CDN URL');
    resourceAssert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'source_url'), 'chapter cache stored a public source_url');

    $roundTrip = JmChapter::fromCachePayload($payload, '350234', '220980');
    resourceAssert($roundTrip instanceof JmChapter, 'valid chapter v2 cache payload was rejected');
    resourceAssertSame($chapter->toCachePayload(), $roundTrip->toCachePayload(), 'chapter v2 cache round trip changed payload');

    $malformedPayloads = [];
    $wrongSchema = $payload;
    $wrongSchema['schema'] = 'chapter-v1';
    $malformedPayloads['old-schema'] = $wrongSchema;
    $wrongId = $payload;
    $wrongId['photo_id'] = '350235';
    $malformedPayloads['wrong-id'] = $wrongId;
    $wrongCount = $payload;
    $wrongCount['page_count'] = 1;
    $malformedPayloads['wrong-count'] = $wrongCount;
    $absolutePath = $payload;
    $absolutePath['images'][0]['media_path'] = 'https://evil.example/00001.jpg';
    $malformedPayloads['absolute-path'] = $absolutePath;
    $wrongSegments = $payload;
    $wrongSegments['images'][0]['decode_segments']++;
    $malformedPayloads['wrong-decode-segments'] = $wrongSegments;
    $extraField = $payload;
    $extraField['images'][0]['url'] = 'https://evil.example/00001.jpg';
    $malformedPayloads['extra-image-field'] = $extraField;
    $unicodeControl = $payload;
    $unicodeControlFilename = "00001\u{0085}.jpg";
    $unicodeControl['images'][0]['filename'] = $unicodeControlFilename;
    $unicodeControl['images'][0]['media_path'] = '/media/photos/350234/' . rawurlencode($unicodeControlFilename);
    $unicodeControl['images'][0]['decode_segments'] = ScrambleDecoder::segments('220980', '350234', $unicodeControlFilename);
    $malformedPayloads['unicode-c1-control'] = $unicodeControl;

    foreach ($malformedPayloads as $name => $malformed) {
        resourceAssertSame(
            null,
            JmChapter::fromCachePayload($malformed, '350234', '220980'),
            "malformed chapter v2 cache payload {$name} was accepted",
        );
    }
}

function testChapterServiceUsesOnlyStrictV2CacheKeysAndPayloads(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'chapter service cache test requires APCu CLI');
    apcu_clear_cache();

    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    $context = RequestContext::forTest('chapter-cache-v2', 12000, 6);
    $numericChapterPayload = chapterPayload(['00001.jpg']);
    $numericChapterPayload['id'] = 350234;
    $numericChapterPayload['series'][0]['id'] = 350234;
    $transport = new ResourceChapterTransport($numericChapterPayload);
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $service = new JmService(context: $context, api: $api, cache: $cache);
    $oldKey = $context->testCacheNamespace() . 'chapter:' . md5('350234:220980');
    $cache->set($oldKey, JmChapter::fromApiResponse(chapterPayload(['legacy.jpg']), '220980', '350234'), 60);

    $first = $service->fetchChapter('350234', '220980');
    resourceAssertSame(1, $transport->calls, 'legacy chapter cache key was read');
    resourceAssertSame('350234', $first->photoId, 'encrypted integer chapter id was not normalized through the service');
    resourceAssertSame('00001.jpg', $first->images[0]['filename'] ?? null, 'fresh chapter payload changed');

    $expectedKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:220980');
    $raw = $cache->get($expectedKey);
    resourceAssert(is_array($raw), 'chapter v2 cache payload was not stored as an array');
    resourceAssertSame('chapter-v2', $raw['schema'] ?? null, 'chapter v2 cache schema changed');

    $second = $service->fetchChapter('350234', '220980');
    resourceAssertSame(1, $transport->calls, 'valid chapter v2 cache did not prevent a second upstream call');
    resourceAssertSame($first->toCachePayload(), $second->toCachePayload(), 'chapter v2 cache hit changed the model');
}

function testTestModeWithoutRunIdNeverSharesTheProductionCacheNamespace(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'test namespace isolation requires APCu CLI');
    apcu_clear_cache();

    $savedGet = $_GET;
    try {
        putenv('JM_TEST_MODE=1');
        $_GET = [];
        $testContext = RequestContext::fromGlobals('namespace-isolation-test');
        $testNamespace = $testContext->testCacheNamespace();
        resourceAssert($testNamespace !== '', 'test mode without run id fell back to the production cache namespace');

        $suffix = 'chapter:v2:' . hash('sha256', 'namespace-isolation-audit');
        $cache->set($testNamespace . $suffix, ['origin' => 'test'], 60);

        putenv('JM_TEST_MODE=0');
        $productionContext = RequestContext::fromGlobals('namespace-isolation-production');
        resourceAssertSame('', $productionContext->testCacheNamespace(), 'production cache namespace changed');
        resourceAssertSame(null, $cache->get($suffix), 'test-mode cache payload leaked into the production namespace');
    } finally {
        $_GET = $savedGet;
        putenv('JM_TEST_MODE');
        apcu_clear_cache();
    }
}

function testReaderManifestUsesStrictV2RelativePathSchemaAndIgnoresLegacyEntries(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'reader manifest cache test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');

    $context = RequestContext::forTest('manifest-cache-v2', 12000, 6);
    $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg', '00002.png']));
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $service = new JmService(context: $context, api: $api, cache: $cache);
    $legacyKey = $context->testCacheNamespace() . 'manifest:' . md5('350234:220980');
    $cache->set($legacyKey, [
        'photo_id' => '350234',
        'images' => [['url' => 'https://evil.example/legacy.jpg']],
    ], 60);

    $manifest = $service->fetchReaderManifest('350234');
    resourceAssertSame(2, $transport->calls, 'legacy manifest key bypassed scramble/chapter upstream work');
    resourceAssertSame('reader-manifest-v2', $manifest['schema'] ?? null, 'reader manifest v2 schema changed');
    resourceAssertSame(2, $manifest['page_count'] ?? null, 'reader manifest page count changed');
    resourceAssertSame('/media/photos/350234/00001.jpg', $manifest['images'][0]['media_path'] ?? null, 'reader manifest lost relative media path');
    $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
    resourceAssert(!str_contains($encoded, 'https://'), 'reader manifest stored an absolute URL');
    resourceAssert(!str_contains($encoded, 'source_url'), 'reader manifest stored public source_url');

    $expectedKey = $context->testCacheNamespace() . 'manifest:v2:' . hash('sha256', '350234:220980');
    resourceAssertSame($manifest, $cache->get($expectedKey), 'reader manifest v2 cache key or payload changed');

    $chapterKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:220980');
    $cache->delete($chapterKey);
    resourceAssertSame($manifest, $service->fetchReaderManifest('350234'), 'valid reader manifest cache payload was rejected');
    resourceAssertSame(2, $transport->calls, 'valid reader manifest cache payload refetched chapter upstream data');
    $cache->set(
        $chapterKey,
        JmChapter::fromApiResponse(chapterPayload(['00001.jpg', '00002.png']), '220980', '350234')->toCachePayload(),
        60,
    );

    $malformed = $manifest;
    $malformed['images'][0]['url'] = 'https://evil.example/injected.jpg';
    $cache->set($expectedKey, $malformed, 60);
    $rebuilt = $service->fetchReaderManifest('350234');
    resourceAssertSame($manifest, $rebuilt, 'malformed reader manifest cache entry was accepted');
    resourceAssertSame(2, $transport->calls, 'reader manifest rebuild unnecessarily refetched upstream chapter data');
}

function testReaderManifestRejectsForgedImageIdentityAndDerivedFields(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'reader manifest forgery test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');

    $context = RequestContext::forTest('manifest-cache-forgery', 12000, 6);
    $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg', '00002.png', '00003.gif']));
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $service = new JmService(context: $context, api: $api, cache: $cache);
    $valid = $service->fetchReaderManifest('350234');
    $manifestKey = $context->testCacheNamespace() . 'manifest:v2:' . hash('sha256', '350234:220980');

    $withMatchingCacheKey = static function (array $candidate, int $page) use ($context): array {
        $offset = $page - 1;
        $image = $candidate['images'][$offset];
        $identity = implode(':', [
            (string) $candidate['photo_id'],
            (string) $page,
            (string) ($image['filename'] ?? ''),
            (string) ($image['media_path'] ?? ''),
            (string) ($image['decode_segments'] ?? 0),
        ]);
        $candidate['images'][$offset]['cache_key'] = $context->testCacheNamespace()
            . 'page:v3:'
            . hash('sha256', $identity);
        return $candidate;
    };

    $malformed = [];
    foreach ([
        'path-traversal' => '../00001.jpg',
        'nested-path' => 'nested/00001.jpg',
        'backslash-path' => 'nested\\00001.jpg',
        'surrounding-space' => ' 00001.jpg',
        'control-character' => "00001\x00.jpg",
        'percent-encoded-traversal' => '%2e%2e%2fsecret.jpg',
        'percent-encoded-mixed-case' => '%2E%2e%5csecret.jpg',
        'percent-double-encoded-traversal' => '%252e%252e%252fsecret.jpg',
        'percent-encoded-control' => '00001%00.jpg',
        'query-filename' => '00001.jpg?token=abc',
        'fragment-filename' => '00001.jpg#fragment',
        'invalid-overlong-utf8-traversal' => "\xC0\xAE\xC0\xAE\xC0\xAFsecret.jpg",
        'invalid-overlong-utf8-backslash' => "\xC1\x9Csecret.jpg",
    ] as $name => $filename) {
        $candidate = $valid;
        $candidate['images'][0]['filename'] = $filename;
        $candidate['images'][0]['media_path'] = "/media/photos/350234/{$filename}";
        $candidate['images'][0]['decode_segments'] = ScrambleDecoder::segments('220980', '350234', $filename);
        $candidate['images'][0]['mime'] = ScrambleDecoder::preferredDecodedMime();
        $malformed["forged-filename-{$name}"] = $withMatchingCacheKey($candidate, 1);
    }

    $candidate = $valid;
    $candidate['images'][0]['media_path'] = '/media/photos/350234/not-the-filename.jpg';
    $malformed['forged-media-path'] = $withMatchingCacheKey($candidate, 1);

    $candidate = $valid;
    $candidate['images'][0]['decode_segments']++;
    $malformed['forged-decode-segments'] = $withMatchingCacheKey($candidate, 1);

    $candidate = $valid;
    $candidate['images'][0]['mime'] = 'application/x-php';
    $malformed['unsupported-mime'] = $withMatchingCacheKey($candidate, 1);

    $candidate = $valid;
    $candidate['images'][0]['mime'] = 'image/png';
    $malformed['supported-but-wrong-mime'] = $withMatchingCacheKey($candidate, 1);

    $candidate = $valid;
    $candidate['images'][2]['mime'] = ScrambleDecoder::preferredDecodedMime();
    $malformed['gif-decoded-mime'] = $withMatchingCacheKey($candidate, 3);

    $candidate = $valid;
    $candidate['images'][0]['scramble_id'] = '220981';
    $malformed['wrong-image-scramble'] = $withMatchingCacheKey($candidate, 1);

    $candidate = $valid;
    $candidate['images'][0]['index'] = 0;
    $malformed['wrong-image-index'] = $withMatchingCacheKey($candidate, 1);

    $candidate = $valid;
    $candidate['images'][0]['cache_key'] = $context->testCacheNamespace() . 'page:v3:attacker-controlled';
    $malformed['wrong-cache-key'] = $candidate;

    $candidate = $valid;
    $unicodeControlFilename = "00001\u{0085}.jpg";
    $candidate['images'][0]['filename'] = $unicodeControlFilename;
    $candidate['images'][0]['media_path'] = '/media/photos/350234/' . rawurlencode($unicodeControlFilename);
    $candidate['images'][0]['decode_segments'] = ScrambleDecoder::segments('220980', '350234', $unicodeControlFilename);
    $candidate['images'][0]['mime'] = ScrambleDecoder::preferredDecodedMime();
    $malformed['unicode-c1-control'] = $withMatchingCacheKey($candidate, 1);

    foreach ($malformed as $name => $candidate) {
        $cache->set($manifestKey, $candidate, 60);
        resourceAssertSame(
            $valid,
            $service->fetchReaderManifest('350234'),
            "forged reader manifest {$name} was accepted",
        );
        resourceAssertSame($valid, $cache->get($manifestKey), "forged reader manifest {$name} was not replaced");
    }
    resourceAssertSame(2, $transport->calls, 'forged reader manifests refetched valid cached chapter data');
}

function testReaderManifestValidatesUnsegmentedMimeFromTheOriginalFilename(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'unsegmented manifest MIME test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');

    $context = RequestContext::forTest('manifest-cache-unsegmented-mime', 12000, 6);
    $transport = new ResourceChapterTransport(chapterPayload(['00001.png'], '200000'));
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $service = new JmService(context: $context, api: $api, cache: $cache);
    $valid = $service->fetchReaderManifest('200000');
    resourceAssertSame(0, $valid['images'][0]['decode_segments'] ?? null, 'unsegmented manifest fixture changed');
    resourceAssertSame('image/png', $valid['images'][0]['mime'] ?? null, 'unsegmented PNG manifest MIME changed');

    $manifestKey = $context->testCacheNamespace() . 'manifest:v2:' . hash('sha256', '200000:220980');
    $forged = $valid;
    $forged['images'][0]['mime'] = 'image/jpeg';
    $cache->set($manifestKey, $forged, 60);

    resourceAssertSame(
        $valid,
        $service->fetchReaderManifest('200000'),
        'supported but wrong unsegmented manifest MIME was accepted',
    );
    resourceAssertSame($valid, $cache->get($manifestKey), 'wrong unsegmented manifest MIME was not replaced');
    resourceAssertSame(2, $transport->calls, 'wrong unsegmented manifest MIME refetched valid cached chapter data');
}

function testMalformedChapterPayloadIsNeverSwallowedOrCachedAsPartialSuccess(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'malformed chapter cache test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');

    $context = RequestContext::forTest('chapter-malformed-cache', 12000, 6);
    $transport = new ResourceChapterTransport(chapterPayload([
        '00001.jpg',
        ['image' => null],
        '00003.jpg',
    ]));
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $service = new JmService(context: $context, api: $api, cache: $cache);

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $thrown = null;
        try {
            $service->fetchChapters(['350234']);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
            "malformed chapter attempt {$attempt} was swallowed as partial HTTP-200 data",
        );
    }

    resourceAssertSame(3, $transport->calls, 'malformed chapter payload was cached instead of refetched');
    $chapterKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:220980');
    $manifestKey = $context->testCacheNamespace() . 'manifest:v2:' . hash('sha256', '350234:220980');
    resourceAssertSame(null, $cache->get($chapterKey), 'malformed chapter entered chapter v2 cache');
    resourceAssertSame(null, $cache->get($manifestKey), 'malformed chapter entered manifest v2 cache');
}

function testCompleteChapterFailureIsNotReturnedAsSuccessfulEmptyData(): void
{
    $cache = new MemoryCache();
    if ($cache->isAvailable()) apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=0');

    try {
        $context = RequestContext::forTest('chapter-complete-failure', 12000, 4);
        $transport = new ResourceSequenceTransport([
            resourceHttpResult(502, 'scramble failure 1'),
            resourceHttpResult(502, 'scramble failure 2'),
            resourceHttpResult(502, 'chapter failure 1'),
            resourceHttpResult(502, 'chapter failure 2'),
        ]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);

        $thrown = null;
        try {
            $service->fetchChapters(['350234']);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof JmException && $thrown->getCode() === 502,
            'complete single-chapter failure was returned as successful empty chapter data',
        );
        resourceAssertSame(4, count($transport->urls), 'complete chapter failure did not use the bounded attempt policy');
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        if ($cache->isAvailable()) apcu_clear_cache();
    }
}

function testChapterTtlZeroBypassesExistingCacheAndNeverWritesFreshData(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'chapter TTL-zero test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=0');

    try {
        $context = RequestContext::forTest('chapter-ttl-zero-bypass', 12000, 6);
        $cacheKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:220980');
        $poisoned = JmChapter::fromApiResponse(chapterPayload(['poisoned.jpg']), '220980', '350234')->toCachePayload();
        $cache->set($cacheKey, $poisoned, 60);

        $transport = new ResourceChapterTransport(chapterPayload(['fresh.jpg']));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);

        foreach ([1, 2] as $attempt) {
            $chapter = $service->fetchChapter('350234', '220980');
            resourceAssertSame('fresh.jpg', $chapter->images[0]['filename'] ?? null, "chapter TTL-zero attempt {$attempt} read poisoned cache");
            resourceAssertSame($attempt, $transport->calls, "chapter TTL-zero attempt {$attempt} did not run the producer");
            resourceAssertSame($poisoned, $cache->get($cacheKey), "chapter TTL-zero attempt {$attempt} wrote cache data");
        }
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testChapterTtlZeroNeverFallsBackToExistingCacheWhenProducerFails(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'chapter TTL-zero failure test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=0');

    try {
        $context = RequestContext::forTest('chapter-ttl-zero-failure', 12000, 6);
        $cacheKey = $context->testCacheNamespace() . 'chapter:v2:' . hash('sha256', '350234:220980');
        $existing = JmChapter::fromApiResponse(chapterPayload(['existing.jpg']), '220980', '350234')->toCachePayload();
        $cache->set($cacheKey, $existing, 60);

        $transport = new ResourceChapterTransport(chapterPayload([['image' => null]]));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        $thrown = null;
        try {
            $service->fetchChapter('350234', '220980');
        } catch (Throwable $error) {
            $thrown = $error;
        }

        resourceAssert($thrown instanceof MalformedChapterException, 'chapter TTL-zero producer failure fell back to existing cache');
        resourceAssertSame(1, $transport->calls, 'chapter TTL-zero failure did not run the producer');
        resourceAssertSame($existing, $cache->get($cacheKey), 'chapter TTL-zero failure mutated existing cache');
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testManifestTtlZeroBypassesExistingManifestAndChapterCaches(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'manifest TTL-zero test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    try {
        $context = RequestContext::forTest('manifest-ttl-zero-bypass', 12000, 6);
        $seedTransport = new ResourceChapterTransport(chapterPayload(['existing.jpg']));
        $seedApi = JmApiClient::forTest($context, $seedTransport, ['https://api.example.test']);
        $seedService = new JmService(context: $context, api: $seedApi, cache: $cache);
        $existing = $seedService->fetchReaderManifest('350234');
        $manifestKey = $context->testCacheNamespace() . 'manifest:v2:' . hash('sha256', '350234:220980');

        putenv('JM_CHAPTER_CACHE_TTL=0');
        $transport = new ResourceChapterTransport(chapterPayload(['fresh.jpg']));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        $fresh = $service->fetchReaderManifest('350234');

        resourceAssertSame('fresh.jpg', $fresh['images'][0]['filename'] ?? null, 'manifest TTL-zero request read an existing manifest/chapter cache');
        resourceAssertSame(1, $transport->calls, 'manifest TTL-zero request did not run the chapter producer');
        resourceAssertSame($existing, $cache->get($manifestKey), 'manifest TTL-zero request wrote cache data');
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testManifestTtlZeroNeverFallsBackToExistingCacheWhenProducerFails(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'manifest TTL-zero failure test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    try {
        $context = RequestContext::forTest('manifest-ttl-zero-failure', 12000, 6);
        $seedTransport = new ResourceChapterTransport(chapterPayload(['existing.jpg']));
        $seedApi = JmApiClient::forTest($context, $seedTransport, ['https://api.example.test']);
        $seedService = new JmService(context: $context, api: $seedApi, cache: $cache);
        $existing = $seedService->fetchReaderManifest('350234');
        $manifestKey = $context->testCacheNamespace() . 'manifest:v2:' . hash('sha256', '350234:220980');

        putenv('JM_CHAPTER_CACHE_TTL=0');
        $transport = new ResourceChapterTransport(chapterPayload([['image' => null]]));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        $thrown = null;
        try {
            $service->fetchReaderManifest('350234');
        } catch (Throwable $error) {
            $thrown = $error;
        }

        resourceAssert($thrown instanceof MalformedChapterException, 'manifest TTL-zero producer failure fell back to existing cache');
        resourceAssertSame(1, $transport->calls, 'manifest TTL-zero failure did not run the chapter producer');
        resourceAssertSame($existing, $cache->get($manifestKey), 'manifest TTL-zero failure mutated existing cache');
    } finally {
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testPageTtlZeroBypassesMaliciousDecodedCacheAndNeverWrites(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'page TTL-zero test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    try {
        $context = RequestContext::forTest('page-ttl-zero-bypass', 12000, 6);
        $seedTransport = new ResourceChapterTransport(chapterPayload(['00001.jpg']));
        $seedApi = JmApiClient::forTest($context, $seedTransport, ['https://api.example.test']);
        $seedService = new JmService(context: $context, api: $seedApi, cache: $cache);
        $manifest = $seedService->fetchReaderManifest('350234');
        $pageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
        resourceAssert($pageKey !== '', 'page TTL-zero fixture did not expose a cache key');
        $malicious = ['bytes' => 'malicious-decoded', 'mime' => 'image/jpeg', 'codec' => 'jpeg'];
        $cache->set($pageKey, $malicious, 60);

        putenv('JM_PAGE_CACHE_TTL=0');
        $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg']), 'fresh-raw');
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $freshDecoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($freshDecoded), 'page TTL-zero decoded PNG fixture failed');
        $decoder = new ResourceImageDecoder(new ImageDecodeResult(
            $freshDecoded, 'image/png', 'png', 9, 32, 32, 1024, 1, 1, 4096,
        ));
        $validator = new ResourceImagePayloadValidator(static fn(string $bytes, int $width, int $height): bool =>
            hash_equals($freshDecoded, $bytes) && $width === 32 && $height === 32
        );
        $markerKey = $context->testCacheNamespace()
            . 'decoded-page-attestation:v2:'
            . hash('sha256', $freshDecoded);
        $markerSentinel = ['sentinel' => 'ttl-zero-must-not-write-marker'];
        $cache->set($markerKey, $markerSentinel, 60);
        $service = new JmService(
            context: $context,
            api: $api,
            cache: $cache,
            imageDecoder: $decoder,
            imagePayloadValidator: $validator,
        );

        foreach ([1, 2] as $attempt) {
            $page = $service->fetchDecodedPage('350234', 1);
            resourceAssertSame($freshDecoded, $page['bytes'] ?? null, "page TTL-zero attempt {$attempt} read malicious decoded cache");
            resourceAssertSame(false, $page['cache_hit'] ?? null, "page TTL-zero attempt {$attempt} was reported as a cache hit");
            resourceAssertSame('disabled', $page['cache_store'] ?? null, "page TTL-zero attempt {$attempt} wrote decoded cache");
            resourceAssertSame($attempt, $transport->calls, "page TTL-zero attempt {$attempt} did not run the producer");
            resourceAssertSame(1, count($validator->calls), "page TTL-zero attempt {$attempt} did not retain exactly one request-scoped full validation");
            resourceAssertSame($malicious, $cache->get($pageKey), "page TTL-zero attempt {$attempt} mutated existing decoded cache");
            resourceAssertSame($markerSentinel, $cache->get($markerKey), "page TTL-zero attempt {$attempt} wrote an attestation marker");
        }
        resourceAssertSame(2, count($decoder->calls), 'page TTL-zero requests did not decode every producer result');
    } finally {
        putenv('JM_PAGE_CACHE_TTL');
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_CDN_BASE_URLS');
        putenv('JM_TEST_ALLOWED_HOSTS');
        putenv('JM_TEST_MODE');
        apcu_clear_cache();
    }
}

function testPageTtlZeroNeverFallsBackToDecodedCacheWhenProducerFails(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'page TTL-zero failure test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');

    try {
        $context = RequestContext::forTest('page-ttl-zero-failure', 12000, 6);
        $seedTransport = new ResourceChapterTransport(chapterPayload(['00001.jpg']));
        $seedApi = JmApiClient::forTest($context, $seedTransport, ['https://api.example.test']);
        $seedService = new JmService(context: $context, api: $seedApi, cache: $cache);
        $manifest = $seedService->fetchReaderManifest('350234');
        $pageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
        $existing = ['bytes' => 'existing-decoded', 'mime' => 'image/jpeg', 'codec' => 'jpeg'];
        $cache->set($pageKey, $existing, 60);

        putenv('JM_PAGE_CACHE_TTL=0');
        $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg']), 'fresh-raw');
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $freshDecoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($freshDecoded), 'page TTL-zero invalid output PNG fixture failed');
        $decoder = new ResourceImageDecoder(new ImageDecodeResult(
            $freshDecoded, 'image/png', 'png', 9, 32, 32, 1024, 1, 0, 4096,
        ));
        $validator = new ResourceImagePayloadValidator(static fn(): bool => false);
        $markerKey = $context->testCacheNamespace()
            . 'decoded-page-attestation:v2:'
            . hash('sha256', $freshDecoded);
        $markerSentinel = ['sentinel' => 'ttl-zero-invalid-must-not-write-marker'];
        $cache->set($markerKey, $markerSentinel, 60);
        $service = new JmService(
            context: $context,
            api: $api,
            cache: $cache,
            imageDecoder: $decoder,
            imagePayloadValidator: $validator,
        );
        $thrown = null;
        try {
            $service->fetchDecodedPage('350234', 1);
        } catch (Throwable $error) {
            $thrown = $error;
        }

        resourceAssert($thrown instanceof JmException && $thrown->getCode() === 502, 'page TTL-zero invalid custom output fell back to decoded cache');
        resourceAssertSame(1, $transport->calls, 'page TTL-zero failure did not run the image producer');
        resourceAssertSame(1, count($decoder->calls), 'page TTL-zero failure did not call the decoder');
        resourceAssertSame(1, count($validator->calls), 'page TTL-zero invalid custom output was not fully validated exactly once');
        resourceAssertSame($existing, $cache->get($pageKey), 'page TTL-zero failure mutated existing decoded cache');
        resourceAssertSame($markerSentinel, $cache->get($markerKey), 'page TTL-zero failure wrote an attestation marker');
    } finally {
        putenv('JM_PAGE_CACHE_TTL');
        putenv('JM_CHAPTER_CACHE_TTL');
        putenv('JM_TEST_CDN_BASE_URLS');
        putenv('JM_TEST_ALLOWED_HOSTS');
        putenv('JM_TEST_MODE');
        apcu_clear_cache();
    }
}

function testDecodedPageCacheRejectsMalformedEnvelopesAndRebuilds(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'decoded cache poison test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $context = RequestContext::forTest('decoded-cache-poison', 12000, 20);
        $seedTransport = new ResourceChapterTransport(chapterPayload(['00001.png'], '200000'));
        $seedApi = JmApiClient::forTest($context, $seedTransport, ['https://api.example.test']);
        $seedService = new JmService(context: $context, api: $seedApi, cache: $cache);
        $manifest = $seedService->fetchReaderManifest('200000');
        $pageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
        resourceAssert($pageKey !== '', 'decoded cache poison fixture did not expose a cache key');

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($png), 'decoded cache poison PNG fixture failed');
        $truncatedPng = resourceTruncatedPngHeaderProbe();
        $truncatedGif = resourceTruncatedGifHeaderProbe();
        $expectedEnvelope = [
            'schema' => 'decoded-page-v3',
            'bytes' => $png,
            'bytes_sha256' => hash('sha256', $png),
            'mime' => 'image/png',
            'codec' => 'png',
            'width' => 32,
            'height' => 32,
            'pixels' => 1024,
        ];
        $malformed = [
            'html-script-extra' => [
                'bytes' => '<script>alert(1)</script>',
                'mime' => 'text/html',
                'codec' => 'script',
                'extra' => true,
            ],
            'non-image-allowed-mime' => [
                'bytes' => 'not-an-image',
                'mime' => 'image/png',
                'codec' => 'png',
            ],
            'extra-field' => $expectedEnvelope + ['extra' => 'attacker-controlled'],
            'forged-metrics' => array_replace($expectedEnvelope, ['width' => 31, 'pixels' => 992]),
            'non-string-bytes' => array_replace($expectedEnvelope, ['bytes' => ['not', 'bytes']]),
            'truncated-png-header-probe' => [
                'schema' => 'decoded-page-v3',
                'bytes' => $truncatedPng,
                'bytes_sha256' => hash('sha256', $truncatedPng),
                'mime' => 'image/png',
                'codec' => 'png',
                'width' => 1,
                'height' => 1,
                'pixels' => 1,
            ],
            'truncated-gif-header-probe' => [
                'schema' => 'decoded-page-v3',
                'bytes' => $truncatedGif,
                'bytes_sha256' => hash('sha256', $truncatedGif),
                'mime' => 'image/gif',
                'codec' => 'gif',
                'width' => 1,
                'height' => 1,
                'pixels' => 1,
            ],
        ];

        foreach ($malformed as $name => $poison) {
            $cache->set($pageKey, $poison, 60);
            $transport = new ResourceChapterTransport(chapterPayload(['00001.png'], '200000'), 'fresh-raw');
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $decoder = new ResourceImageDecoder(new ImageDecodeResult(
                $png, 'image/png', 'png', 9, 32, 32, 1024, 1, 0, 4096,
            ));
            $service = new JmService(context: $context, api: $api, cache: $cache, imageDecoder: $decoder);
            $page = $service->fetchDecodedPage('200000', 1);

            resourceAssertSame($png, $page['bytes'] ?? null, "decoded cache poison {$name} was returned as a HIT");
            resourceAssertSame(false, $page['cache_hit'] ?? null, "decoded cache poison {$name} was reported as a HIT");
            resourceAssertSame(1, $transport->calls, "decoded cache poison {$name} bypassed the producer");
            resourceAssertSame(1, count($decoder->calls), "decoded cache poison {$name} bypassed image validation");
            resourceAssertSame($expectedEnvelope, $cache->get($pageKey), "decoded cache poison {$name} was not replaced by an exact envelope");
        }
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testDecodedPageAttestationAvoidsRepeatedFullDecodeAndRebuildsAfterMarkerLoss(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'decoded page attestation test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($png), 'decoded page attestation PNG fixture failed');

        $context = RequestContext::forTest('decoded-page-attestation', 12000, 8);
        $transport = new ResourceChapterTransport(chapterPayload(['00001.png'], '200000'), 'raw-image');
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $decoder = new ResourceImageDecoder(new ImageDecodeResult(
            $png, 'image/png', 'png', 9, 32, 32, 1024, 1, 0, 4096,
        ));
        $validator = new ResourceImagePayloadValidator();
        $service = new JmService(
            context: $context,
            api: $api,
            cache: $cache,
            imageDecoder: $decoder,
            imagePayloadValidator: $validator,
        );

        $miss = $service->fetchDecodedPage('200000', 1);
        $pageKey = (string) ($miss['prefetch_manifest']['images'][0]['cache_key'] ?? '');
        $digest = hash('sha256', $png);
        $markerKey = $context->testCacheNamespace() . 'decoded-page-attestation:v2:' . $digest;
        $expectedMarker = ['schema' => 'decoded-page-attestation-v2', 'bytes_sha256' => $digest];
        resourceAssert($pageKey !== '' && str_contains($pageKey, 'page:v3:'), 'decoded page attestation did not use the v3 page key');
        resourceAssertSame(false, $miss['cache_hit'] ?? null, 'initial decoded page materialization was reported as a HIT');
        resourceAssertSame(1, count($validator->calls), 'decoded page write performed more or less than one full validation');
        resourceAssertSame('decoded-page-v3', $cache->get($pageKey)['schema'] ?? null, 'decoded page write did not store a v3 envelope');
        resourceAssertSame($digest, $cache->get($pageKey)['bytes_sha256'] ?? null, 'decoded page write did not bind its bytes digest');
        resourceAssertSame($expectedMarker, $cache->get($markerKey), 'decoded page write did not store an exact attestation marker');

        foreach ([1, 2] as $hitNumber) {
            $hit = $service->fetchDecodedPage('200000', 1);
            resourceAssertSame(true, $hit['cache_hit'] ?? null, "decoded page HIT {$hitNumber} missed the cache");
            resourceAssertSame($png, $hit['bytes'] ?? null, "decoded page HIT {$hitNumber} changed bytes");
        }
        resourceAssertSame(1, count($validator->calls), 'two attested HITs repeated full image decode');
        resourceAssertSame(1, count($decoder->calls), 'two attested HITs reran the image decoder');

        $cache->delete($markerKey);
        $reattested = $service->fetchDecodedPage('200000', 1);
        resourceAssertSame(true, $reattested['cache_hit'] ?? null, 'marker expunge discarded a valid decoded page');
        resourceAssertSame(2, count($validator->calls), 'marker expunge did not trigger exactly one full revalidation');
        resourceAssertSame($expectedMarker, $cache->get($markerKey), 'marker expunge did not rebuild the attestation');

        $legacyMarkerKey = $context->testCacheNamespace() . 'decoded-page-attestation:v1:' . $digest;
        $legacyMarker = ['schema' => 'decoded-page-attestation-v1', 'bytes_sha256' => $digest];
        $cache->delete($markerKey);
        $cache->set($legacyMarkerKey, $legacyMarker, 60);
        $beforeLegacyMarker = count($validator->calls);
        $legacyRevalidated = $service->fetchDecodedPage('200000', 1);
        resourceAssertSame(true, $legacyRevalidated['cache_hit'] ?? null, 'legacy v1 marker discarded a fully revalidated page');
        resourceAssertSame(
            $beforeLegacyMarker + 1,
            count($validator->calls),
            'legacy v1 marker authorized the page without one full validation',
        );
        resourceAssertSame($expectedMarker, $cache->get($markerKey), 'legacy v1 marker did not rebuild the v2 marker');
        resourceAssertSame($legacyMarker, $cache->get($legacyMarkerKey), 'legacy v1 marker was read or rewritten');

        $malformedMarkers = [
            'wrong-schema' => ['schema' => 'attacker-controlled', 'bytes_sha256' => $digest],
            'correct-schema-wrong-digest' => [
                'schema' => 'decoded-page-attestation-v2',
                'bytes_sha256' => str_repeat('0', 64),
            ],
            'extra-key' => $expectedMarker + ['extra' => 'attacker-controlled'],
            'missing-digest' => ['schema' => 'decoded-page-attestation-v2'],
            'wrong-digest-type' => [
                'schema' => 'decoded-page-attestation-v2',
                'bytes_sha256' => [$digest],
            ],
        ];
        foreach ($malformedMarkers as $name => $malformedMarker) {
            $cache->set($markerKey, $malformedMarker, 60);
            $beforeValidation = count($validator->calls);
            $repaired = $service->fetchDecodedPage('200000', 1);
            resourceAssertSame(true, $repaired['cache_hit'] ?? null, "malformed marker {$name} discarded a fully revalidated page");
            resourceAssertSame(
                $beforeValidation + 1,
                count($validator->calls),
                "malformed marker {$name} bypassed or repeated full validation",
            );
            resourceAssertSame(
                $expectedMarker,
                $cache->get($markerKey),
                "malformed marker {$name} was not replaced by the exact marker",
            );
        }
        resourceAssertSame(1, count($decoder->calls), 'marker recovery reran the upstream image decoder');
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testProductionDecodedPageWritePerformsOneCompleteValidation(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'production decoded page write test requires APCu CLI');
    resourceAssert(extension_loaded('gd'), 'production decoded page write test requires GD');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $png = resourceGeneratedImageBytes('png');
        foreach ([
            'segments-zero' => ['photo_id' => '200000', 'expect_segmented' => false],
            'segments-positive' => ['photo_id' => '350234', 'expect_segmented' => true],
        ] as $name => $case) {
            apcu_clear_cache();
            $context = RequestContext::forTest("production-write-once-{$name}", 12000, 8);
            $transport = new ResourceChapterTransport(
                chapterPayload(['00001.png'], $case['photo_id']),
                $png,
            );
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $gdValidator = new GdImagePayloadValidator();
            $validator = new ResourceImagePayloadValidator([$gdValidator, 'isCompleteDecode']);
            $decoder = new GdImageDecoder(payloadValidator: $validator);
            $service = new JmService(
                context: $context,
                api: $api,
                cache: $cache,
                imageDecoder: $decoder,
                imagePayloadValidator: $validator,
            );

            $page = $service->fetchDecodedPage($case['photo_id'], 1);
            $segments = (int) ($page['prefetch_manifest']['images'][0]['decode_segments'] ?? -1);
            resourceAssert(
                $case['expect_segmented'] ? $segments > 0 : $segments === 0,
                "{$name} did not exercise its intended decoder branch",
            );
            resourceAssertSame(false, $page['cache_hit'] ?? null, "{$name} production write was not a MISS");
            resourceAssertSame('stored', $page['cache_store'] ?? null, "{$name} production write was not cached");
            resourceAssertSame(1, count($validator->calls), "{$name} production write repeated complete validation");
            resourceAssert(
                ImageContainerPolicy::isComplete((string) ($page['bytes'] ?? ''), (string) ($page['mime'] ?? '')),
                "{$name} production output failed the container policy",
            );
            $hit = $service->fetchDecodedPage($case['photo_id'], 1);
            resourceAssertSame(true, $hit['cache_hit'] ?? null, "{$name} follow-up request was not a HIT");
            resourceAssertSame($page['bytes'] ?? null, $hit['bytes'] ?? null, "{$name} HIT changed output bytes");
            resourceAssertSame(1, count($validator->calls), "{$name} HIT repeated complete validation");
        }
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testDecodedPageAttestationBindsActualBytesRejectsTruncationAndIgnoresV2(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'decoded page digest binding test requires APCu CLI');
    resourceAssert(extension_loaded('gd'), 'decoded page digest binding test requires GD');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($png), 'decoded page digest binding PNG fixture failed');
        $truncated = resourceTruncatedPngHeaderProbe();
        $context = RequestContext::forTest('decoded-page-digest-binding', 12000, 20);
        $transport = new ResourceChapterTransport(chapterPayload(['00001.png'], '200000'), 'fresh-raw');
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $decoder = new ResourceImageDecoder(new ImageDecodeResult(
            $png, 'image/png', 'png', 9, 32, 32, 1024, 1, 0, 4096,
        ));
        $gdValidator = new GdImagePayloadValidator();
        $validator = new ResourceImagePayloadValidator([$gdValidator, 'isCompleteDecode']);
        $service = new JmService(
            context: $context,
            api: $api,
            cache: $cache,
            imageDecoder: $decoder,
            imagePayloadValidator: $validator,
        );
        $first = $service->fetchDecodedPage('200000', 1);
        $pageKey = (string) ($first['prefetch_manifest']['images'][0]['cache_key'] ?? '');
        $validEntry = $cache->get($pageKey);
        resourceAssert(is_array($validEntry), 'decoded page digest binding fixture was not cached');
        $validDigest = hash('sha256', $png);
        $validMarkerKey = $context->testCacheNamespace() . 'decoded-page-attestation:v2:' . $validDigest;
        resourceAssertSame(1, count($validator->calls), 'initial digest-bound write was not validated exactly once');

        $borrowedDigest = $validEntry;
        $borrowedDigest['bytes'] = $truncated;
        $cache->set($pageKey, $borrowedDigest, 60);
        $beforeBorrow = count($validator->calls);
        $borrowRejected = $service->fetchDecodedPage('200000', 1);
        resourceAssertSame(false, $borrowRejected['cache_hit'] ?? null, 'changed bytes borrowed the old digest marker');
        resourceAssertSame($beforeBorrow, count($validator->calls), 'digest mismatch reached full validation or lost the request-scoped producer proof');
        resourceAssertSame($validDigest, $cache->get($pageKey)['bytes_sha256'] ?? null, 'changed bytes were not replaced by the valid producer');

        $fakeDigest = $cache->get($pageKey);
        resourceAssert(is_array($fakeDigest), 'fake digest fixture lost the decoded page');
        $fakeDigest['bytes_sha256'] = str_repeat('0', 64);
        $cache->set($pageKey, $fakeDigest, 60);
        $beforeFakeDigest = count($validator->calls);
        $fakeRejected = $service->fetchDecodedPage('200000', 1);
        resourceAssertSame(false, $fakeRejected['cache_hit'] ?? null, 'forged digest was accepted with an unrelated marker');
        resourceAssertSame($beforeFakeDigest, count($validator->calls), 'forged digest reached full validation or lost the request-scoped producer proof');

        $truncatedDigest = hash('sha256', $truncated);
        $truncatedMarkerKey = $context->testCacheNamespace() . 'decoded-page-attestation:v2:' . $truncatedDigest;
        $cache->delete($truncatedMarkerKey);
        $cache->set($pageKey, [
            'schema' => 'decoded-page-v3',
            'bytes' => $truncated,
            'bytes_sha256' => $truncatedDigest,
            'mime' => 'image/png',
            'codec' => 'png',
            'width' => 1,
            'height' => 1,
            'pixels' => 1,
        ], 60);
        $beforeTruncated = count($validator->calls);
        $truncatedRejected = $service->fetchDecodedPage('200000', 1);
        resourceAssertSame(false, $truncatedRejected['cache_hit'] ?? null, 'digest-correct truncated bytes bypassed complete decode validation');
        resourceAssertSame($beforeTruncated, count($validator->calls), 'structurally truncated HIT reached full decode or valid rebuild lost its request proof');
        resourceAssertSame(null, $cache->get($truncatedMarkerKey), 'failed truncated validation created an attestation marker');
        resourceAssertSame($validMarkerKey, $context->testCacheNamespace() . 'decoded-page-attestation:v2:' . ($cache->get($pageKey)['bytes_sha256'] ?? ''), 'truncated page was not replaced by valid bytes');

        $identity = implode(':', ['200000', '1', '00001.png', '/media/photos/200000/00001.png', '0']);
        $oldKey = $context->testCacheNamespace() . 'page:v2:' . hash('sha256', $identity);
        $oldEnvelope = [
            'schema' => 'decoded-page-v2',
            'bytes' => $png,
            'mime' => 'image/png',
            'codec' => 'png',
            'width' => 32,
            'height' => 32,
            'pixels' => 1024,
        ];
        $cache->set($oldKey, $oldEnvelope, 60);
        $cache->set($pageKey, $oldEnvelope, 60);
        $beforeV2 = count($validator->calls);
        $v2Rejected = $service->fetchDecodedPage('200000', 1);
        resourceAssertSame(false, $v2Rejected['cache_hit'] ?? null, 'legacy v2 key or envelope was read as a HIT');
        resourceAssertSame($beforeV2, count($validator->calls), 'legacy v2 rejection reached full validation or lost the request-scoped producer proof');
        resourceAssertSame($oldEnvelope, $cache->get($oldKey), 'legacy v2 key was unexpectedly used or rewritten');
        resourceAssertSame('decoded-page-v3', $cache->get($pageKey)['schema'] ?? null, 'legacy v2 envelope was not replaced at the v3 key');
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testDisabledDecodedPageCacheNeverValidatesOrWritesAttestation(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'disabled decoded page cache test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_PAGE_CACHE_TTL=0');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');

    try {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($png), 'disabled decoded page cache PNG fixture failed');
        $context = RequestContext::forTest('decoded-page-attestation-disabled', 12000, 6);
        $transport = new ResourceChapterTransport(chapterPayload(['00001.png'], '200000'));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $validator = new ResourceImagePayloadValidator();
        $service = new JmService(context: $context, api: $api, cache: $cache, imagePayloadValidator: $validator);
        $digest = hash('sha256', $png);
        $markerKey = $context->testCacheNamespace() . 'decoded-page-attestation:v2:' . $digest;
        $sentinel = ['sentinel' => 'must-not-be-overwritten'];
        $cache->set($markerKey, $sentinel, 60);
        $method = new ReflectionMethod(JmService::class, 'cacheDecodedPage');
        $status = $method->invoke($service, $context->testCacheNamespace() . 'page:v3:disabled', [
            'bytes' => $png,
            'mime' => 'image/png',
            'codec' => 'png',
            'width' => 32,
            'height' => 32,
            'pixels' => 1024,
        ], $digest);
        resourceAssertSame('disabled', $status, 'page TTL zero did not disable decoded page storage');
        resourceAssertSame([], $validator->calls, 'page TTL zero performed a full attestation validation');
        resourceAssertSame($sentinel, $cache->get($markerKey), 'page TTL zero wrote or replaced an attestation marker');
    } finally {
        putenv('JM_PAGE_CACHE_TTL');
        putenv('JM_TEST_ALLOWED_HOSTS');
        apcu_clear_cache();
    }
}

function testUnavailableApcuNeverValidatesOrWritesDecodedPageAttestation(): void
{
    $indexPath = realpath(dirname(__DIR__) . '/index.php');
    resourceAssert(is_string($indexPath), 'unavailable APCu probe could not resolve index.php');
    $extensionDirectory = realpath((string) ini_get('extension_dir'));
    resourceAssert(is_string($extensionDirectory), 'unavailable APCu probe could not resolve PHP extension_dir');
    $probe = <<<'PHP'
define('JM_API_LIBRARY_ONLY', true);
require $argv[1];

final class UnavailableApcuTransport implements UpstreamTransport {
    public int $calls = 0;
    public function __construct(private string $imageBytes) {}
    public function get(string $url, array $headers, int $timeoutMs, ?int $bodyLimitBytes = null): HttpResult {
        $this->calls++;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (str_starts_with($path, '/media/photos/')) {
            return new HttpResult(true, $this->imageBytes, 200, [], 0, '', ['total_ms' => 1]);
        }
        if ($path === JmConfig::ENDPOINT_SCRAMBLE) {
            return new HttpResult(true, '<script>var scramble_id = 220980;</script>', 200, [], 0, '', ['total_ms' => 1]);
        }

        $tokenParam = '';
        foreach ($headers as $header) {
            if (stripos($header, 'tokenparam:') === 0) {
                $tokenParam = trim(substr($header, strlen('tokenparam:')));
                break;
            }
        }
        $timestamp = explode(',', $tokenParam, 2)[0] ?? '';
        if (preg_match('/^\d{9,12}$/', $timestamp) !== 1) {
            throw new RuntimeException('unavailable APCu transport did not receive tokenparam');
        }
        $payload = [
            'id' => '200000',
            'name' => 'Unavailable APCu Chapter',
            'series' => [['id' => '200000', 'sort' => '1']],
            'images' => ['00001.png'],
        ];
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $padding = 16 - (strlen($plain) % 16);
        $cipher = openssl_encrypt(
            $plain . str_repeat(chr($padding), $padding),
            'AES-256-ECB',
            md5($timestamp . JmConfig::DATA_SECRET),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        if (!is_string($cipher)) throw new RuntimeException('unavailable APCu transport encryption failed');
        $body = json_encode(['code' => 200, 'data' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
        return new HttpResult(true, $body, 200, [], 0, '', ['total_ms' => 1]);
    }
}

final class UnavailableApcuDecoder implements ImageDecoder {
    public int $calls = 0;
    public function __construct(private string $decodedBytes) {}
    public function decode(string $bytes, int $segments, int $maxPixels): ImageDecodeResult {
        $this->calls++;
        return new ImageDecodeResult(
            $this->decodedBytes,
            'image/png',
            'png',
            strlen($bytes),
            32,
            32,
            1024,
            1,
            0,
            4096,
        );
    }
}

final class UnavailableApcuValidator implements ImagePayloadValidator {
    public int $calls = 0;
    public function __construct(private string $expectedBytes) {}
    public function isCompleteDecode(string $bytes, int $width, int $height): bool {
        $this->calls++;
        return hash_equals($this->expectedBytes, $bytes) && $width === 32 && $height === 32;
    }
    public function hasCompleteDecodeAttestation(string $bytes, int $width, int $height): bool { return false; }
}

putenv('JM_TEST_MODE=1');
putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
putenv('JM_CHAPTER_CACHE_TTL=60');
putenv('JM_PAGE_CACHE_TTL=60');
$cache = new MemoryCache();
if ($cache->isAvailable()) throw new RuntimeException('APCu unexpectedly available under php -n');
$context = RequestContext::forTest('unavailable-apcu-attestation', 12000, 6);
$decodedBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
    true,
);
if (!is_string($decodedBytes)) throw new RuntimeException('unavailable APCu PNG fixture failed');
$transport = new UnavailableApcuTransport('fresh-raw');
$api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
$decoder = new UnavailableApcuDecoder($decodedBytes);
$validator = new UnavailableApcuValidator($decodedBytes);
$service = new JmService(
    context: $context,
    api: $api,
    cache: $cache,
    imageDecoder: $decoder,
    imagePayloadValidator: $validator,
);
$page = $service->fetchDecodedPage('200000', 1);
if (($page['bytes'] ?? null) !== $decodedBytes
    || ($page['cache_hit'] ?? null) !== false
    || ($page['cache_store'] ?? null) !== 'disabled'
    || ($page['singleflight'] ?? null) !== 'disabled'
    || $transport->calls !== 3
    || $decoder->calls !== 1
    || $validator->calls !== 1
) {
    throw new RuntimeException('unavailable APCu full fetch did not validate exactly once without storage');
}
fwrite(STDOUT, "UNAVAILABLE_APCU_FULL_FETCH_VALIDATED\n");
PHP;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([
        PHP_BINARY,
        '-n',
        '-d',
        'extension_dir=' . $extensionDirectory,
        '-d',
        'extension=php_openssl.dll',
        '-d',
        'extension=php_gd.dll',
        '-r',
        $probe,
        $indexPath,
    ], $descriptors, $pipes);
    resourceAssert(is_resource($process), 'unavailable APCu probe process did not start');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    resourceAssertSame(0, $exitCode, 'unavailable APCu probe failed: ' . trim((string) $stderr));
    resourceAssertSame("UNAVAILABLE_APCU_FULL_FETCH_VALIDATED\n", $stdout, 'unavailable APCu probe did not complete a validated disabled-cache fetch');
}

function testUnicodeControlCachePoisonCannotReachDecodedPage(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'Unicode control cache poison test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $photoId = '200000';
        $scrambleId = '220980';
        $filename = "00001\u{0085}.png";
        $mediaPath = "/media/photos/{$photoId}/" . rawurlencode($filename);
        $segments = ScrambleDecoder::segments($scrambleId, $photoId, $filename);
        resourceAssertSame(0, $segments, 'Unicode cache poison fixture unexpectedly requires scrambling');

        $context = RequestContext::forTest('unicode-control-cache-poison', 12000, 8);
        $namespace = $context->testCacheNamespace();
        $pageKey = $namespace . 'page:v3:' . hash('sha256', implode(':', [
            $photoId, '1', $filename, $mediaPath, (string) $segments,
        ]));
        $chapterKey = $namespace . 'chapter:v2:' . hash('sha256', $photoId . ':' . $scrambleId);
        $manifestKey = $namespace . 'manifest:v2:' . hash('sha256', $photoId . ':' . $scrambleId);
        $cache->set($namespace . 'scramble:' . md5($photoId), $scrambleId, 60);
        $cache->set($chapterKey, [
            'schema' => 'chapter-v2',
            'photo_id' => $photoId,
            'title' => 'Poisoned Chapter',
            'sort' => '1',
            'page_count' => 1,
            'images' => [[
                'index' => 1,
                'filename' => $filename,
                'media_path' => $mediaPath,
                'scramble_id' => $scrambleId,
                'decode_segments' => $segments,
            ]],
        ], 60);
        $cache->set($manifestKey, [
            'schema' => 'reader-manifest-v2',
            'photo_id' => $photoId,
            'scramble_id' => $scrambleId,
            'page_count' => 1,
            'images' => [[
                'index' => 1,
                'filename' => $filename,
                'media_path' => $mediaPath,
                'mime' => 'image/png',
                'scramble_id' => $scrambleId,
                'decode_segments' => $segments,
                'cache_key' => $pageKey,
            ]],
        ], 60);

        $poisonedPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        $freshPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACEAAAAhCAIAAADYhlU4AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAKUlEQVRIie3NMQEAAAgDIKf9O/tYwQ8KkPTUs/4OHA6Hw+FwOBwOh+MsA7AASLI2jJ8AAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($poisonedPng) && is_string($freshPng), 'Unicode cache poison PNG fixtures failed');
        $cache->set($pageKey, [
            'schema' => 'decoded-page-v3',
            'bytes' => $poisonedPng,
            'bytes_sha256' => hash('sha256', $poisonedPng),
            'mime' => 'image/png',
            'codec' => 'png',
            'width' => 32,
            'height' => 32,
            'pixels' => 1024,
        ], 60);

        $transport = new ResourceChapterTransport(chapterPayload(['fresh.png'], $photoId), 'fresh-raw');
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $decoder = new ResourceImageDecoder(new ImageDecodeResult(
            $freshPng, 'image/png', 'png', 9, 33, 33, 1089, 1, 0, 4096,
        ));
        $service = new JmService(context: $context, api: $api, cache: $cache, imageDecoder: $decoder);
        $page = $service->fetchDecodedPage($photoId, 1);

        resourceAssertSame($freshPng, $page['bytes'] ?? null, 'Unicode control cache poison reached the decoded page');
        resourceAssertSame(false, $page['cache_hit'] ?? null, 'Unicode control cache poison was reported as a page HIT');
        resourceAssertSame(2, $transport->calls, 'Unicode control cache poison bypassed fresh chapter/image producers');
        resourceAssertSame(1, count($decoder->calls), 'Unicode control cache poison bypassed fresh image validation');
        resourceAssertSame('fresh.png', $cache->get($chapterKey)['images'][0]['filename'] ?? null, 'Unicode chapter cache poison was retained');
        resourceAssertSame('fresh.png', $cache->get($manifestKey)['images'][0]['filename'] ?? null, 'Unicode manifest cache poison was retained');
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testPageTtlZeroDisablesPrefetchAndCacheDependentDiagnostics(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'page TTL-zero prefetch test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test');
    putenv('JM_PAGE_CACHE_TTL=0');
    putenv('JM_PREFETCH_PAGES=1');
    putenv('JM_PREFETCH_MAX_ACTIVE=1');
    putenv('JM_PREFETCH_WALL_BUDGET_MS=100');
    putenv('JM_PREFETCH_BYTE_BUDGET=1024');
    putenv('JM_PREFETCH_MIN_FREE_BYTES=0');
    putenv('JM_PREFETCH_MIN_FREE_RATIO=0');
    putenv('JM_NEXT_CHAPTER_PREFETCH=0');

    try {
        $context = RequestContext::forTest('page-ttl-zero-prefetch', 12000, 6);
        $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg']));
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        $manifest = [
            'photo_id' => '350234',
            'page_count' => 1,
            'images' => [],
        ];

        resourceAssertSame(
            'disabled',
            $service->maybePrefetchPages('350234', 1, true, null, $manifest),
            'page TTL zero scheduled prefetch work that cannot be stored',
        );
        $disabled = JmService::runtimeDiagnostics($cache, $context);
        resourceAssertSame(false, $disabled['singleflight']['enabled'] ?? null, 'page TTL zero reported cache-dependent single-flight as enabled');
        resourceAssertSame(false, $disabled['cache_policy']['page_cache_enabled'] ?? null, 'page TTL zero diagnostics reported the page cache as enabled');
        resourceAssertSame(0, $disabled['cache_policy']['page_cache_ttl_seconds'] ?? null, 'page TTL zero diagnostics changed the configured TTL');

        putenv('JM_PAGE_CACHE_TTL=60');
        $enabled = JmService::runtimeDiagnostics($cache, $context);
        resourceAssertSame(true, $enabled['singleflight']['enabled'] ?? null, 'positive page TTL did not re-enable single-flight diagnostics');
        resourceAssertSame(true, $enabled['cache_policy']['page_cache_enabled'] ?? null, 'positive page TTL did not re-enable cache diagnostics');
        resourceAssertSame(60, $enabled['cache_policy']['page_cache_ttl_seconds'] ?? null, 'positive page TTL diagnostics changed the configured TTL');
    } finally {
        foreach ([
            'JM_NEXT_CHAPTER_PREFETCH', 'JM_PREFETCH_MIN_FREE_RATIO', 'JM_PREFETCH_MIN_FREE_BYTES',
            'JM_PREFETCH_BYTE_BUDGET', 'JM_PREFETCH_WALL_BUDGET_MS',
            'JM_PREFETCH_MAX_ACTIVE', 'JM_PREFETCH_PAGES', 'JM_PAGE_CACHE_TTL', 'JM_TEST_ALLOWED_HOSTS',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testCdnPrimaryIsStableAllowlistedAndSuccessfulRequestsNeverTouchSecondary(): void
{
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test,cdn-secondary.test,cdn-third.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test,https://cdn-secondary.test,https://cdn-third.test');

    $context = RequestContext::forTest('cdn-primary', 12000, 6);
    $transport = new ResourceSequenceTransport([resourceHttpResult(200, 'primary-image')]);
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $mediaPath = '/media/photos/350234/00001.jpg';

    foreach ([
        '/media/photos/350234/%2e%2e%2fsecret.jpg',
        '/media/photos/350234/%2E%2e%5csecret.jpg',
        '/media/photos/350234/%252e%252e%252fsecret.jpg',
        '/media/photos/350234/00001%00.jpg',
        '/media/photos/350234/' . rawurlencode("\xC0\xAE\xC0\xAE\xC0\xAFsecret.jpg"),
        '/media/photos/350234/' . rawurlencode("\xC1\x9Csecret.jpg"),
    ] as $encodedTraversalPath) {
        $thrown = null;
        try {
            $api->downloadImage($encodedTraversalPath);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof MalformedChapterException && $thrown->getCode() === 502,
            "encoded CDN path {$encodedTraversalPath} was accepted",
        );
    }
    resourceAssertSame([], $transport->urls, 'encoded traversal path reached the CDN transport');

    resourceAssertSame('primary-image', $api->downloadImage($mediaPath), 'primary CDN image bytes changed');
    resourceAssertSame(
        ['https://cdn-primary.test/media/photos/350234/00001.jpg'],
        $transport->urls,
        'primary success touched a secondary CDN or materialized a non-allowlisted URL',
    );
    resourceAssertSame(
        [
            'https://cdn-primary.test/media/photos/350234/00001.jpg',
            'https://cdn-secondary.test/media/photos/350234/00001.jpg',
        ],
        CdnPolicy::candidateUrls($mediaPath, $context),
        'test CDN candidates were not stable primary + one secondary',
    );
}

function testCdnFailoverOccursExactlyOnceOnlyForNetworkOrHttp5xxFailures(): void
{
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test,cdn-secondary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test,https://cdn-secondary.test');
    $mediaPath = '/media/photos/350234/00001.jpg';
    $expectedUrls = [
        'https://cdn-primary.test/media/photos/350234/00001.jpg',
        'https://cdn-secondary.test/media/photos/350234/00001.jpg',
    ];

    $retryable = [
        'network' => resourceHttpResult(0, '', 7),
        'http-500' => resourceHttpResult(500, 'server error'),
        'http-599' => resourceHttpResult(599, 'server error'),
    ];
    foreach ($retryable as $name => $firstResult) {
        $context = RequestContext::forTest('cdn-' . $name, 12000, 6);
        $transport = new ResourceSequenceTransport([$firstResult, resourceHttpResult(200, 'secondary-image')]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        resourceAssertSame('secondary-image', $api->downloadImage($mediaPath), "{$name} did not use one secondary CDN");
        resourceAssertSame($expectedUrls, $transport->urls, "{$name} did not stop after exactly one CDN failover");
    }

    $nonRetryable = [
        'http-302' => resourceHttpResult(302, 'redirect body'),
        'http-408' => resourceHttpResult(408, 'request timeout'),
        'http-404' => resourceHttpResult(404, 'not found'),
        'http-429' => resourceHttpResult(429, 'rate limited'),
        'empty-success-body' => resourceHttpResult(200, ''),
    ];
    foreach ($nonRetryable as $name => $firstResult) {
        $context = RequestContext::forTest('cdn-' . $name, 12000, 6);
        $transport = new ResourceSequenceTransport([$firstResult, resourceHttpResult(200, 'must-not-be-used')]);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $thrown = null;
        try {
            $api->downloadImage($mediaPath);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown instanceof JmException && $thrown->getCode() === 502, "{$name} did not fail safely");
        resourceAssertSame([$expectedUrls[0]], $transport->urls, "{$name} incorrectly triggered CDN failover");
    }
}

function testCdnSecondaryUsesExistingHealthAcrossAllAllowlistedCandidates(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'CDN health selection test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test,cdn-secondary.test,cdn-third.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test,https://cdn-secondary.test,https://cdn-third.test');
    putenv('JM_DOMAIN_COOLDOWN_SECONDS=30');

    try {
        $context = RequestContext::forTest('cdn-health-secondary', 12000, 10);
        $transport = new ResourceCdnHealthTransport();
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $mediaPath = '/media/photos/350234/00001.jpg';
        $primaryUrl = 'https://cdn-primary.test' . $mediaPath;
        $secondaryUrl = 'https://cdn-secondary.test' . $mediaPath;
        $thirdUrl = 'https://cdn-third.test' . $mediaPath;

        $firstThrown = null;
        try {
            $api->downloadImage($mediaPath);
        } catch (Throwable $error) {
            $firstThrown = $error;
        }
        resourceAssert(
            $firstThrown instanceof JmException && $firstThrown->getCode() === 502,
            'first two failing CDNs did not fail safely after exactly two attempts',
        );
        resourceAssertSame([$primaryUrl, $secondaryUrl], $transport->urls, 'initial CDN tie-break was not stable primary + secondary');

        $secondThrown = null;
        $secondBytes = null;
        try {
            $secondBytes = $api->downloadImage($mediaPath);
        } catch (Throwable $error) {
            $secondThrown = $error;
        }
        resourceAssert($secondThrown === null, 'existing CDN health did not promote the third allowlisted secondary');
        resourceAssertSame('third-image', $secondBytes, 'health-selected third CDN bytes changed');
        resourceAssertSame(
            [$primaryUrl, $secondaryUrl, $primaryUrl, $thirdUrl],
            $transport->urls,
            'CDN health selection changed stable primary or exceeded one fallback per request',
        );
    } finally {
        foreach (['JM_DOMAIN_COOLDOWN_SECONDS', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE'] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testCompressedBodyCollectorEnforcesExactForgedAndMissingLengthBoundariesBeforeAppend(): void
{
    $exact = new ResponseBodyCollector(4);
    resourceAssertSame(strlen("Content-Length: 4\r\n"), $exact->consumeHeaderLine("Content-Length: 4\r\n"), 'exact Content-Length was rejected');
    resourceAssertSame(2, $exact->consumeChunk('ab'), 'first exact-limit chunk was rejected');
    resourceAssertSame(2, $exact->consumeChunk('cd'), 'second exact-limit chunk was rejected');
    resourceAssertSame('abcd', $exact->body(), 'exact-limit body changed');
    resourceAssertSame(false, $exact->limitExceeded(), 'exact-limit body was marked exceeded');

    $early = new ResponseBodyCollector(4);
    resourceAssertSame(0, $early->consumeHeaderLine("Content-Length: 5\r\n"), 'limit+1 Content-Length was not rejected early');
    resourceAssertSame(true, $early->limitExceeded(), 'early Content-Length rejection did not set limit status');
    resourceAssertSame('', $early->body(), 'early Content-Length rejection buffered bytes');

    $forgedLow = new ResponseBodyCollector(4);
    resourceAssertSame(strlen("Content-Length: 1\r\n"), $forgedLow->consumeHeaderLine("Content-Length: 1\r\n"), 'forged low Content-Length was rejected before bytes proved it false');
    resourceAssertSame(4, $forgedLow->consumeChunk('abcd'), 'forged-length body rejected exact bytes');
    resourceAssertSame(0, $forgedLow->consumeChunk('e'), 'forged-length limit+1 byte was appended');
    resourceAssertSame('abcd', $forgedLow->body(), 'forged-length overflow changed the bounded buffer');
    resourceAssertSame(true, $forgedLow->limitExceeded(), 'forged-length overflow did not set limit status');

    $noLength = new ResponseBodyCollector(4);
    resourceAssertSame(3, $noLength->consumeChunk('abc'), 'no-length first chunk failed');
    resourceAssertSame(0, $noLength->consumeChunk('de'), 'no-length body exceeded the callback limit');
    resourceAssertSame('abc', $noLength->body(), 'no-length overflow appended before checking limit');

    $disabled = new ResponseBodyCollector(0);
    resourceAssertSame(5, $disabled->consumeChunk('abcde'), 'zero compressed-byte limit did not mean disabled');
    resourceAssertSame('abcde', $disabled->body(), 'disabled compressed-byte limit changed body');
}

function testCompressedBodyLimitHasDistinctStatusAndNeverTriggersCdnFailover(): void
{
    resourceAssertSame(33554432, JmConfig::DEFAULT_IMAGE_MAX_COMPRESSED_BYTES, 'compressed image default must remain 32 MiB');
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test,cdn-secondary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test,https://cdn-secondary.test');
    putenv('JM_IMAGE_MAX_COMPRESSED_BYTES=4');

    $limitedResult = new HttpResult(
        false,
        'abcd',
        200,
        ['content-length' => '5'],
        23,
        'Failure writing output to destination',
        ['total_ms' => 1],
        bodyLimitExceeded: true,
        receivedBytes: 5,
    );
    $context = RequestContext::forTest('image-body-limit', 12000, 6);
    $transport = new ResourceSequenceTransport([$limitedResult, resourceHttpResult(200, 'must-not-be-used')]);
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $thrown = null;
    try {
        $api->downloadImage('/media/photos/350234/00001.jpg');
    } catch (Throwable $error) {
        $thrown = $error;
    }
    resourceAssert(
        $thrown instanceof JmException && $thrown->getCode() === 502,
        'compressed-byte cap did not return a safe 502; actual=' . ($thrown === null
            ? 'none'
            : $thrown::class . ':' . $thrown->getCode() . ':' . $thrown->getMessage()),
    );
    resourceAssertSame(1, count($transport->urls), 'compressed-byte cap was misclassified as network failover');
    resourceAssertSame([4], $transport->bodyLimits, 'image transport did not receive the configured compressed-byte cap');

    $budgetContext = RequestContext::forTest('prefetch-image-body-limit', 12000, 6);
    $budgetTransport = new ResourceSequenceTransport([$limitedResult, resourceHttpResult(200, 'must-not-be-used')]);
    $budgetApi = JmApiClient::forTest($budgetContext, $budgetTransport, ['https://api.example.test']);
    $budgetThrown = null;
    try {
        $budgetApi->downloadImage('/media/photos/350234/00001.jpg', 3);
    } catch (Throwable $error) {
        $budgetThrown = $error;
    }
    resourceAssert(
        $budgetThrown instanceof UpstreamBudgetExhaustedException
            && $budgetThrown->reason() === UpstreamBudgetExhaustedException::REASON_BYTES,
        'prefetch byte budget did not stop the in-flight image download with a typed reason',
    );
    resourceAssertSame(1, count($budgetTransport->urls), 'prefetch byte exhaustion incorrectly triggered CDN failover');
    resourceAssertSame([3], $budgetTransport->bodyLimits, 'image transport did not receive the remaining prefetch byte budget');

    putenv('JM_IMAGE_MAX_COMPRESSED_BYTES=0');
    $zeroContext = RequestContext::forTest('image-body-limit-zero', 12000, 6);
    $zeroTransport = new ResourceSequenceTransport([resourceHttpResult(200, 'bounded-image')]);
    $zeroApi = JmApiClient::forTest($zeroContext, $zeroTransport, ['https://api.example.test']);
    resourceAssertSame('bounded-image', $zeroApi->downloadImage('/media/photos/350234/00001.jpg'), 'zero compressed-byte configuration changed a bounded image');
    resourceAssertSame(
        [JmConfig::DEFAULT_IMAGE_MAX_COMPRESSED_BYTES],
        $zeroTransport->bodyLimits,
        'zero compressed-byte configuration disabled the mandatory safety cap',
    );
    putenv('JM_IMAGE_MAX_COMPRESSED_BYTES');
}

function testImageDecoderValidatesDimensionsAndPixelCapsBeforeEveryBypassOrGdPath(): void
{
    resourceAssert(extension_loaded('gd'), 'image decoder test requires GD');
    resourceAssertSame(80000000, JmConfig::DEFAULT_IMAGE_MAX_PIXELS, 'image pixel default must remain 80 MP');
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
        true,
    );
    resourceAssert(is_string($png), 'image decoder fixture failed to decode');
    $decoder = new GdImageDecoder();

    $exact = $decoder->decode($png, 0, 1024);
    resourceAssert($exact instanceof ImageDecodeResult, 'image decoder did not return a typed result');
    resourceAssertSame($png, $exact->bytes, 'segments=0 branch unexpectedly re-encoded bytes');
    resourceAssertSame('image/png', $exact->mime, 'segments=0 branch MIME changed');
    resourceAssertSame('png', $exact->codec, 'segments=0 branch codec changed');
    resourceAssertSame(strlen($png), $exact->inputBytes, 'image input-byte metric changed');
    resourceAssertSame(32, $exact->width, 'image width metric changed');
    resourceAssertSame(32, $exact->height, 'image height metric changed');
    resourceAssertSame(1024, $exact->pixels, 'image pixel metric changed');
    resourceAssert($exact->decodeMs >= 0 && $exact->encodeMs === 0, 'bypass timing metrics changed');
    resourceAssert($exact->peakMemoryBytes > 0, 'image peak-memory metric missing');

    $zeroConfigured = $decoder->decode($png, 0, 0);
    resourceAssertSame(1024, $zeroConfigured->pixels, 'zero pixel configuration changed an image below the safe default');
    $zeroCapError = null;
    try {
        ImagePixelPolicy::checkedPixels(1, JmConfig::DEFAULT_IMAGE_MAX_PIXELS + 1, 0);
    } catch (Throwable $error) {
        $zeroCapError = $error;
    }
    resourceAssert($zeroCapError instanceof ImageProcessingException, 'zero pixel configuration disabled the mandatory default cap');

    $pixelError = null;
    try {
        $decoder->decode($png, 0, 1023);
    } catch (Throwable $error) {
        $pixelError = $error;
    }
    resourceAssert($pixelError instanceof ImageProcessingException, 'pixel limit+1 did not fail before bypass');

    $invalidError = null;
    try {
        $decoder->decode('not-an-image', 0, 0);
    } catch (Throwable $error) {
        $invalidError = $error;
    }
    resourceAssert($invalidError instanceof ImageProcessingException, 'invalid image payload did not fail dimension validation');

    $overflowError = null;
    try {
        ImagePixelPolicy::checkedPixels(2, PHP_INT_MAX, 0);
    } catch (Throwable $error) {
        $overflowError = $error;
    }
    resourceAssert($overflowError instanceof ImageProcessingException, 'pixel multiplication overflow was not rejected by division check');

    $negativeGifError = null;
    try {
        $decoder->decode(resourceGeneratedImageBytes('gif'), -1, 1024);
    } catch (Throwable $error) {
        $negativeGifError = $error;
    }
    resourceAssert(
        $negativeGifError instanceof ImageProcessingException,
        'negative segments bypassed validation for GIF',
    );
}

function testImageDecoderFullyValidatesTruncatedPngAndGifPassthroughs(): void
{
    resourceAssert(extension_loaded('gd'), 'truncated passthrough test requires GD');
    $truncatedPng = resourceTruncatedPngHeaderProbe();
    $truncatedGif = resourceTruncatedGifHeaderProbe();
    resourceAssertSame(25, strlen($truncatedPng), 'truncated PNG fixture is no longer the minimal 25-byte header probe');

    $cases = [
        'segments-zero-png' => [$truncatedPng, 0, 'image/png'],
        'arbitrary-segments-gif' => [$truncatedGif, 17, 'image/gif'],
    ];
    foreach ($cases as $name => [$bytes, $segments, $mime]) {
        $info = @getimagesizefromstring($bytes);
        resourceAssert(is_array($info) && ($info['mime'] ?? null) === $mime, "{$name} fixture no longer passes header identification");
        $probe = @imagecreatefromstring($bytes);
        if ($probe instanceof GdImage) imagedestroy($probe);
        resourceAssert(!$probe instanceof GdImage, "{$name} fixture unexpectedly became fully decodable");

        $thrown = null;
        try {
            (new GdImageDecoder())->decode($bytes, $segments, 1);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown instanceof ImageProcessingException, "{$name} bypassed complete decode validation");
    }
}

function testDecodedPageRejectsEveryTailTruncatedPngAndGifBeforeAttestation(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'tail-truncation attestation test requires APCu CLI');
    resourceAssert(extension_loaded('gd'), 'tail-truncation attestation test requires GD');
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $cases = [
            'png' => ['photo_id' => '200000', 'filename' => '00001.png', 'bytes' => resourceGeneratedImageBytes('png')],
            'gif' => ['photo_id' => '350234', 'filename' => '00001.gif', 'bytes' => resourceGeneratedImageBytes('gif')],
        ];
        resourceAssert(str_ends_with($cases['png']['bytes'], "\x00\x00\x00\x00IEND\xAE\x42\x60\x82"), 'generated PNG omitted its complete IEND chunk');
        resourceAssert(str_ends_with($cases['gif']['bytes'], "\x3B"), 'generated GIF omitted its trailer');

        $gdAcceptedTruncatedGif = @imagecreatefromstring(substr($cases['gif']['bytes'], 0, -1));
        resourceAssert($gdAcceptedTruncatedGif instanceof GdImage, 'GD no longer demonstrates the missing-GIF-trailer gap');
        imagedestroy($gdAcceptedTruncatedGif);

        foreach ($cases as $format => $case) {
            apcu_clear_cache();
            $context = RequestContext::forTest("complete-container-{$format}", 12000, 8);
            $transport = new ResourceChapterTransport(
                chapterPayload([$case['filename']], $case['photo_id']),
                $case['bytes'],
            );
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);
            $first = $service->fetchDecodedPage($case['photo_id'], 1);
            $pageKey = (string) ($first['prefetch_manifest']['images'][0]['cache_key'] ?? '');
            $digest = hash('sha256', $case['bytes']);
            $markerKey = $context->testCacheNamespace() . 'decoded-page-attestation:v2:' . $digest;
            resourceAssertSame(false, $first['cache_hit'] ?? null, "complete {$format} was not materialized as a MISS");
            resourceAssertSame('stored', $first['cache_store'] ?? null, "complete {$format} did not enter the page cache");
            resourceAssertSame($case['bytes'], $first['bytes'] ?? null, "complete {$format} bytes changed");
            resourceAssertSame('decoded-page-v3', $cache->get($pageKey)['schema'] ?? null, "complete {$format} page envelope missing");
            resourceAssertSame(
                ['schema' => 'decoded-page-attestation-v2', 'bytes_sha256' => $digest],
                $cache->get($markerKey),
                "complete {$format} marker missing",
            );
            resourceAssertSame(true, $service->fetchDecodedPage($case['photo_id'], 1)['cache_hit'] ?? null, "complete {$format} did not produce a cache HIT");

            for ($cut = 1, $length = strlen($case['bytes']); $cut < $length; $cut++) {
                apcu_clear_cache();
                $truncated = substr($case['bytes'], 0, $length - $cut);
                $truncatedContext = RequestContext::forTest("tail-{$format}-{$cut}", 12000, 8);
                $truncatedTransport = new ResourceChapterTransport(
                    chapterPayload([$case['filename']], $case['photo_id']),
                    $truncated,
                );
                $truncatedApi = JmApiClient::forTest(
                    $truncatedContext,
                    $truncatedTransport,
                    ['https://api.example.test'],
                );
                $truncatedService = new JmService(
                    context: $truncatedContext,
                    api: $truncatedApi,
                    cache: $cache,
                );
                $manifest = $truncatedService->fetchReaderManifest($case['photo_id']);
                $truncatedPageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
                $truncatedMarkerKey = $truncatedContext->testCacheNamespace()
                    . 'decoded-page-attestation:v2:'
                    . hash('sha256', $truncated);
                $thrown = null;
                try {
                    $truncatedService->fetchDecodedPage($case['photo_id'], 1);
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof JmException && $thrown->getCode() === 502,
                    "{$format} tail cut {$cut} was accepted instead of a safe 502",
                );
                resourceAssertSame(null, $cache->get($truncatedPageKey), "{$format} tail cut {$cut} entered the page cache");
                resourceAssertSame(null, $cache->get($truncatedMarkerKey), "{$format} tail cut {$cut} created an attestation marker");
                resourceAssertSame(null, $cache->get('lock:' . $truncatedPageKey), "{$format} tail cut {$cut} left a single-flight lock");
            }
        }
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testGifLzwTruncationCannotPoisonDecodedPageCache(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'GIF LZW cache test requires APCu CLI');
    resourceAssert(extension_loaded('gd'), 'GIF LZW cache test requires GD');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $complete = resourceGeneratedGifLzwFixture();
        $layouts = resourceGifImageLayouts($complete);
        resourceAssertSame(1, count($layouts), 'GIF LZW fixture did not contain exactly one frame');
        $frame = $layouts[0];
        resourceAssertSame(64, $frame['width'], 'GIF LZW fixture width changed');
        resourceAssertSame(64, $frame['height'], 'GIF LZW fixture height changed');
        resourceAssertSame(8, $frame['min_code_size'], 'GIF LZW fixture minimum code size changed');
        $lastBlock = $frame['blocks'][array_key_last($frame['blocks'])] ?? null;
        resourceAssert(is_array($lastBlock), 'GIF LZW fixture final data block missing');
        resourceAssertSame(106, $lastBlock['length'], 'GIF LZW fixture final data block length changed');
        resourceAssertSame($lastBlock['data_offset'] + 106, $frame['terminator_offset'], 'GIF LZW fixture terminator moved');

        $mutated = substr($complete, 0, $lastBlock['length_offset'])
            . chr(6)
            . substr($complete, $lastBlock['data_offset'], 6)
            . substr($complete, $frame['terminator_offset']);
        resourceAssertSame(strlen($complete) - 100, strlen($mutated), 'GIF LZW mutation did not delete exactly 100 payload bytes');
        resourceAssert(str_ends_with($mutated, "\x00\x3B"), 'GIF LZW mutation did not preserve terminator and trailer');
        $completePixels = resourceGdPixelDigest($complete);
        $mutatedPixels = resourceGdPixelDigest($mutated);
        resourceAssert(is_string($completePixels) && is_string($mutatedPixels), 'GD no longer accepts both GIF LZW fixtures');
        resourceAssert(!hash_equals($completePixels, $mutatedPixels), 'GIF LZW mutation no longer changes decoded pixels');

        $policyAccepted = ImageContainerPolicy::isComplete($mutated, 'image/gif');
        $validatorAccepted = (new GdImagePayloadValidator())->isCompleteDecode($mutated, 64, 64);
        $context = RequestContext::forTest('gif-lzw-truncated-sub-block', 12000, 8);
        $transport = new ResourceChapterTransport(chapterPayload(['00001.gif'], '350234'), $mutated);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        $manifest = $service->fetchReaderManifest('350234');
        $pageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
        $markerKey = $context->testCacheNamespace()
            . 'decoded-page-attestation:v2:'
            . hash('sha256', $mutated);
        $thrown = null;
        $page = null;
        $followUp = null;
        try {
            $page = $service->fetchDecodedPage('350234', 1);
            $followUp = $service->fetchDecodedPage('350234', 1);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        $pageCache = $cache->get($pageKey);
        $marker = $cache->get($markerKey);
        $safe = $policyAccepted === false
            && $validatorAccepted === false
            && $thrown instanceof JmException
            && $thrown->getCode() === 502
            && $pageCache === null
            && $marker === null
            && $cache->get('lock:' . $pageKey) === null;
        resourceAssert($safe, 'truncated GIF LZW stream was trusted; evidence=' . json_encode([
            'complete_sha256' => hash('sha256', $complete),
            'mutated_sha256' => hash('sha256', $mutated),
            'policy_accepted' => $policyAccepted,
            'validator_accepted' => $validatorAccepted,
            'service_error' => $thrown === null ? null : [$thrown::class, $thrown->getCode()],
            'first_cache_store' => is_array($page) ? ($page['cache_store'] ?? null) : null,
            'follow_up_hit' => is_array($followUp) ? ($followUp['cache_hit'] ?? null) : null,
            'page_schema' => is_array($pageCache) ? ($pageCache['schema'] ?? null) : null,
            'marker_schema' => is_array($marker) ? ($marker['schema'] ?? null) : null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testGifLzwCodeBoundariesAndAnimationFrames(): void
{
    $onePixel = resourcePackGifLzwCodes([[4, 3], [0, 3], [5, 3]]);
    $onePixelWithPadding = $onePixel;
    $onePixelWithPadding[strlen($onePixelWithPadding) - 1] = chr(ord($onePixelWithPadding[strlen($onePixelWithPadding) - 1]) | 0xFE);
    $clearMidstream = resourcePackGifLzwCodes([[4, 3], [0, 3], [4, 3], [1, 3], [5, 3]]);
    $kwKwK = resourcePackGifLzwCodes([[4, 3], [0, 3], [6, 3], [5, 3]]);
    $growthThreeToFour = resourcePackGifLzwCodes([[4, 3], [0, 3], [1, 3], [2, 3], [3, 4], [5, 4]]);

    $fullDictionaryCodes = [[4, 3]];
    $nextCode = 6;
    $codeWidth = 3;
    $hasPrevious = false;
    for ($index = 0; $index < 4092; $index++) {
        $fullDictionaryCodes[] = [0, $codeWidth];
        if ($hasPrevious && $nextCode < 4096) {
            $nextCode++;
            if ($codeWidth < 12 && $nextCode === (1 << $codeWidth)) $codeWidth++;
        } else {
            $hasPrevious = true;
        }
    }
    resourceAssertSame(4096, $nextCode, 'GIF LZW fixture did not fill the dictionary exactly');
    resourceAssertSame(12, $codeWidth, 'GIF LZW fixture did not reach twelve-bit codes');
    $fullDictionaryCodes[] = [4, 12];
    $fullDictionaryCodes[] = [1, 3];
    $fullDictionaryCodes[] = [5, 3];
    $fullDictionary = resourcePackGifLzwCodes($fullDictionaryCodes);

    $frameZero = resourceGifLzwFrame(1, 1, 2, $onePixel);
    $frameOne = resourceGifLzwFrame(1, 1, 2, resourcePackGifLzwCodes([[4, 3], [1, 3], [5, 3]]));
    $validAnimation = resourceGifAnimation([$frameZero, $frameOne], 1, 1);
    $valid = [
        'one-pixel-padding' => resourceGifAnimation([resourceGifLzwFrame(1, 1, 2, $onePixelWithPadding)], 1, 1),
        'clear-midstream' => resourceGifAnimation([resourceGifLzwFrame(2, 1, 2, $clearMidstream)], 2, 1),
        'kwkwk-next-code' => resourceGifAnimation([resourceGifLzwFrame(3, 1, 2, $kwKwK)], 3, 1),
        'three-to-four-bit-growth' => resourceGifAnimation([resourceGifLzwFrame(4, 1, 2, $growthThreeToFour)], 4, 1),
        'split-sub-block-byte-boundary' => resourceGifAnimation([resourceGifLzwFrame(1, 1, 2, $onePixel, [1, 1])], 1, 1),
        'two-frame-animation' => $validAnimation,
        'dictionary-full-freeze-clear-reset' => resourceGifAnimation([resourceGifLzwFrame(4093, 1, 2, $fullDictionary)], 4093, 1),
    ];
    foreach ($valid as $name => $gif) {
        resourceAssert(ImageContainerPolicy::isComplete($gif, 'image/gif'), "valid GIF LZW vector {$name} was rejected");
    }
    resourceAssert((new GdImagePayloadValidator())->isCompleteDecode($validAnimation, 1, 1), 'valid two-frame GIF failed complete validation');

    $missingEoi = resourceGifLzwFrame(1, 1, 2, resourcePackGifLzwCodes([[4, 3], [0, 3]]));
    $invalid = [
        'missing-eoi' => resourceGifAnimation([$missingEoi], 1, 1),
        'eoi-underflow' => resourceGifAnimation([resourceGifLzwFrame(2, 1, 2, $onePixel)], 2, 1),
        'output-overflow' => resourceGifAnimation([resourceGifLzwFrame(1, 1, 2, resourcePackGifLzwCodes([[4, 3], [0, 3], [1, 3], [5, 3]]))], 1, 1),
        'code-beyond-next' => resourceGifAnimation([resourceGifLzwFrame(1, 1, 2, resourcePackGifLzwCodes([[4, 3], [0, 3], [7, 3], [5, 3]]))], 1, 1),
        'payload-byte-after-eoi' => resourceGifAnimation([resourceGifLzwFrame(1, 1, 2, $onePixel . "\x00")], 1, 1),
        'animation-second-frame-missing-eoi' => resourceGifAnimation([$frameZero, $missingEoi], 1, 1),
    ];
    foreach ($invalid as $name => $gif) {
        resourceAssert(!ImageContainerPolicy::isComplete($gif, 'image/gif'), "invalid GIF LZW vector {$name} was accepted");
    }
}

function testGifFramesStayInsideLogicalScreen(): void
{
    $onePixel = resourcePackGifLzwCodes([[4, 3], [0, 3], [5, 3]]);
    $twoPixels = resourcePackGifLzwCodes([[4, 3], [0, 3], [1, 3], [5, 3]]);
    $fourPixels = resourcePackGifLzwCodes([[4, 3], [0, 3], [1, 3], [2, 3], [3, 4], [5, 4]]);
    $oneByOne = resourceGifLzwFrame(1, 1, 2, $onePixel);
    $leftOutside = substr_replace($oneByOne, pack('v', 1), 1, 2);
    $topOutside = substr_replace($oneByOne, pack('v', 1), 3, 2);
    $cases = [
        'left-outside' => resourceGifAnimation([$leftOutside], 1, 1),
        'top-outside' => resourceGifAnimation([$topOutside], 1, 1),
        'width-outside' => resourceGifAnimation([resourceGifLzwFrame(2, 1, 2, $twoPixels)], 1, 1),
        'height-outside' => resourceGifAnimation([resourceGifLzwFrame(1, 2, 2, $twoPixels)], 1, 1),
        'two-by-two-outside' => resourceGifAnimation([resourceGifLzwFrame(2, 2, 2, $fourPixels)], 1, 1),
    ];
    foreach ($cases as $name => $gif) {
        resourceAssert(!ImageContainerPolicy::isComplete($gif, 'image/gif'), "GIF frame {$name} escaped the logical screen");
    }
    $validAnimation = resourceGifAnimation([$oneByOne, $oneByOne], 1, 1);
    resourceAssert(ImageContainerPolicy::isComplete($validAnimation, 'image/gif'), 'in-bounds GIF animation was rejected');
}

function testGifOneByteSubBlocksAreRejectedWithoutAmplifiedMemory(): void
{
    $indexPath = realpath(dirname(__DIR__) . '/index.php');
    resourceAssert(is_string($indexPath), 'GIF streaming memory probe could not resolve index.php');
    $probe = <<<'PHP'
define('JM_API_LIBRARY_ONLY', true);
require $argv[1];
$gif = 'GIF89a'
    . pack('v', 1) . pack('v', 1)
    . "\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF"
    . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02"
    . str_repeat("\x01\x00", 200000)
    . "\x00\x3B";
memory_reset_peak_usage();
$before = memory_get_usage(false);
$accepted = ImageContainerPolicy::isComplete($gif, 'image/gif');
$delta = memory_get_peak_usage(false) - $before;
fwrite(STDOUT, json_encode([
    'accepted' => $accepted,
    'bytes' => strlen($gif),
    'peak_delta' => $delta,
], JSON_THROW_ON_ERROR) . "\n");
PHP;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, '-n', '-r', $probe, $indexPath], $descriptors, $pipes);
    resourceAssert(is_resource($process), 'GIF streaming memory probe did not start');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    resourceAssertSame(0, $exitCode, 'GIF streaming memory probe failed: ' . trim((string) $stderr));
    $result = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
    resourceAssert(is_array($result), 'GIF streaming memory probe returned no result');
    resourceAssertSame(false, $result['accepted'] ?? null, 'GIF one-byte sub-block fixture was accepted');
    resourceAssert(
        is_int($result['peak_delta'] ?? null) && $result['peak_delta'] <= 4 * 1024 * 1024,
        'GIF one-byte sub-block parsing amplified memory; evidence=' . trim((string) $stdout),
    );
}

function testSegmentedDecoderRejectsIncompleteRawContainerBeforeGdReencode(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'segmented raw-container test requires APCu CLI');
    resourceAssert(extension_loaded('gd'), 'segmented raw-container test requires GD');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $complete = resourceGeneratedImageBytes('jpeg');
        resourceAssert(str_ends_with($complete, "\xFF\xD9"), 'generated JPEG omitted EOI');
        $truncated = substr($complete, 0, -2);
        $gdProbe = @imagecreatefromstring($truncated);
        resourceAssert($gdProbe instanceof GdImage, 'GD no longer demonstrates segmented JPEG tail recovery');
        imagedestroy($gdProbe);

        $context = RequestContext::forTest('segmented-incomplete-raw-container', 12000, 8);
        $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg'], '350234'), $truncated);
        $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
        $service = new JmService(context: $context, api: $api, cache: $cache);
        $manifest = $service->fetchReaderManifest('350234');
        resourceAssert((int) ($manifest['images'][0]['decode_segments'] ?? 0) > 0, 'raw-container test did not enter the segmented branch');
        $pageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
        $markerKey = $context->testCacheNamespace()
            . 'decoded-page-attestation:v2:'
            . hash('sha256', $truncated);
        $thrown = null;
        try {
            $service->fetchDecodedPage('350234', 1);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert(
            $thrown instanceof JmException && $thrown->getCode() === 502,
            'segmented incomplete raw JPEG was re-encoded and trusted instead of rejected',
        );
        resourceAssertSame(null, $cache->get($pageKey), 'segmented incomplete raw JPEG entered the page cache');
        resourceAssertSame(null, $cache->get($markerKey), 'segmented incomplete raw JPEG created an attestation marker');
        resourceAssertSame(null, $cache->get('lock:' . $pageKey), 'segmented incomplete raw JPEG left a single-flight lock');
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testImageContainerPolicyRejectsMalformedStructureAndTrailingDataAcrossFormats(): void
{
    resourceAssert(extension_loaded('gd'), 'container structure test requires GD');
    $complete = [
        'image/jpeg' => resourceGeneratedImageBytes('jpeg'),
        'image/png' => resourceGeneratedImageBytes('png'),
        'image/gif' => resourceGeneratedImageBytes('gif'),
        'image/webp' => resourceGeneratedImageBytes('webp'),
    ];
    foreach ($complete as $mime => $bytes) {
        resourceAssert(ImageContainerPolicy::isComplete($bytes, $mime), "complete {$mime} failed container validation");
        resourceAssert(!ImageContainerPolicy::isComplete($bytes . "\x00", $mime), "{$mime} trailing data was accepted");
    }

    $jpeg = $complete['image/jpeg'];
    $malformedJpeg = substr($jpeg, 0, 2) . "\x00" . substr($jpeg, 2);
    resourceAssert(!ImageContainerPolicy::isComplete($malformedJpeg, 'image/jpeg'), 'JPEG invalid marker stream was accepted');

    $validSof = "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00";
    $validSos = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";
    resourceAssert(
        !ImageContainerPolicy::isComplete("\xFF\xD8" . $validSos . "\xFF\xD9", 'image/jpeg'),
        'JPEG scan without a frame header was accepted',
    );
    resourceAssert(
        !ImageContainerPolicy::isComplete("\xFF\xD8\xFF\xC0\x00\x02" . $validSos . "\xFF\xD9", 'image/jpeg'),
        'JPEG malformed frame header was accepted',
    );
    resourceAssert(
        !ImageContainerPolicy::isComplete("\xFF\xD8" . $validSof . "\xFF\xDA\x00\x02\xFF\xD9", 'image/jpeg'),
        'JPEG malformed scan header was accepted',
    );

    $png = $complete['image/png'];
    $firstChunkLength = unpack('Nlength', substr($png, 8, 4));
    resourceAssert(is_array($firstChunkLength) && is_int($firstChunkLength['length'] ?? null), 'PNG fixture first chunk length missing');
    $firstChunkCrcOffset = 8 + 8 + $firstChunkLength['length'];
    $malformedPng = $png;
    $malformedPng[$firstChunkCrcOffset] = chr(ord($malformedPng[$firstChunkCrcOffset]) ^ 0x01);
    resourceAssert(!ImageContainerPolicy::isComplete($malformedPng, 'image/png'), 'PNG chunk CRC corruption was accepted');

    $gif = $complete['image/gif'];
    $malformedGif = substr($gif, 0, -1) . "\xFF\x3B";
    resourceAssert(!ImageContainerPolicy::isComplete($malformedGif, 'image/gif'), 'GIF invalid top-level block was accepted');

    $webp = $complete['image/webp'];
    resourceAssert(strlen($webp) >= 20, 'WebP fixture omitted its first chunk header');
    $malformedWebp = substr_replace($webp, pack('V', strlen($webp)), 16, 4);
    resourceAssert(!ImageContainerPolicy::isComplete($malformedWebp, 'image/webp'), 'WebP invalid chunk size was accepted');

}

function testImageContainerPolicyValidatesAnimatedWebpFramePayloads(): void
{
    resourceAssert(extension_loaded('gd'), 'animated WebP structure test requires GD');
    $webp = resourceGeneratedImageBytes('webp');
    $vp8xPayload = "\x02\x00\x00\x00\x1F\x00\x00\x1F\x00\x00";
    $emptyAnmfChunks = 'VP8X' . pack('V', strlen($vp8xPayload)) . $vp8xPayload
        . 'ANMF' . pack('V', 0);
    $emptyAnmf = 'RIFF' . pack('V', 4 + strlen($emptyAnmfChunks)) . 'WEBP' . $emptyAnmfChunks;
    resourceAssert(
        !ImageContainerPolicy::isComplete($emptyAnmf, 'image/webp'),
        'WebP empty ANMF chunk was accepted as image payload',
    );

    $headerOnlyAnmfPayload = str_repeat("\x00", 16);
    $headerOnlyAnmfChunks = 'VP8X' . pack('V', strlen($vp8xPayload)) . $vp8xPayload
        . 'ANMF' . pack('V', strlen($headerOnlyAnmfPayload)) . $headerOnlyAnmfPayload;
    $headerOnlyAnmf = 'RIFF' . pack('V', 4 + strlen($headerOnlyAnmfChunks)) . 'WEBP' . $headerOnlyAnmfChunks;
    resourceAssert(
        !ImageContainerPolicy::isComplete($headerOnlyAnmf, 'image/webp'),
        'WebP ANMF without a nested image chunk was accepted',
    );

    $innerImageChunk = substr($webp, 12);
    resourceAssert(
        in_array(substr($innerImageChunk, 0, 4), ['VP8 ', 'VP8L'], true),
        'generated WebP did not expose a reusable image chunk',
    );
    $anmfPayload = str_repeat("\x00", 16) . $innerImageChunk;
    $animatedChunks = 'VP8X' . pack('V', strlen($vp8xPayload)) . $vp8xPayload
        . 'ANMF' . pack('V', strlen($anmfPayload)) . $anmfPayload;
    if ((strlen($anmfPayload) & 1) === 1) $animatedChunks .= "\x00";
    $animatedWebp = 'RIFF' . pack('V', 4 + strlen($animatedChunks)) . 'WEBP' . $animatedChunks;
    resourceAssert(
        ImageContainerPolicy::isComplete($animatedWebp, 'image/webp'),
        'structurally complete animated WebP failed container validation',
    );
}

function testTruncatedPassthroughFailuresReturn502AndNeverWriteDecodedCache(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'truncated passthrough cache test requires APCu CLI');
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test');
    putenv('JM_CHAPTER_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_TTL=60');
    putenv('JM_PAGE_CACHE_MIN_FREE_BYTES=0');
    putenv('JM_PAGE_CACHE_MIN_FREE_RATIO=0');

    try {
        $cases = [
            'segments-zero-png' => ['200000', '00001.png', resourceTruncatedPngHeaderProbe(), false],
            'scrambled-gif' => ['350234', '00001.gif', resourceTruncatedGifHeaderProbe(), true],
        ];
        foreach ($cases as $name => [$photoId, $filename, $bytes, $expectSegments]) {
            apcu_clear_cache();
            $context = RequestContext::forTest('truncated-' . $name, 12000, 8);
            $transport = new ResourceChapterTransport(chapterPayload([$filename], $photoId), $bytes);
            $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
            $service = new JmService(context: $context, api: $api, cache: $cache);
            $manifest = $service->fetchReaderManifest($photoId);
            $segments = (int) ($manifest['images'][0]['decode_segments'] ?? -1);
            resourceAssert($expectSegments ? $segments > 0 : $segments === 0, "{$name} did not exercise the intended passthrough branch");
            $pageKey = (string) ($manifest['images'][0]['cache_key'] ?? '');
            resourceAssert($pageKey !== '', "{$name} did not expose a decoded cache key");

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $thrown = null;
                try {
                    $service->fetchDecodedPage($photoId, 1);
                } catch (Throwable $error) {
                    $thrown = $error;
                }
                resourceAssert(
                    $thrown instanceof JmException && $thrown->getCode() === 502,
                    "{$name} attempt {$attempt} was not converted to a safe 502",
                );
            }
            resourceAssertSame(null, $cache->get($pageKey), "{$name} entered the decoded page cache");
            resourceAssertSame(null, $cache->get('lock:' . $pageKey), "{$name} left a single-flight lock");
            resourceAssertSame(4, $transport->calls, "{$name} was cached instead of retried from the image producer");
        }
    } finally {
        foreach ([
            'JM_PAGE_CACHE_MIN_FREE_RATIO', 'JM_PAGE_CACHE_MIN_FREE_BYTES', 'JM_PAGE_CACHE_TTL',
            'JM_CHAPTER_CACHE_TTL', 'JM_TEST_CDN_BASE_URLS', 'JM_TEST_ALLOWED_HOSTS', 'JM_TEST_MODE',
        ] as $name) {
            putenv($name);
        }
        apcu_clear_cache();
    }
}

function testDecodedPageUsesInjectedDecoderAndRecordsBoundedImageMetrics(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'decoded-page injection test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test,cdn-secondary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test,https://cdn-secondary.test');
    putenv('JM_IMAGE_MAX_COMPRESSED_BYTES=64');
    putenv('JM_IMAGE_MAX_PIXELS=1024');

    $context = RequestContext::forTest('image-decoder-injection', 12000, 6);
    $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg']), 'raw-image');
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $decodedPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
        true,
    );
    resourceAssert(is_string($decodedPng), 'decoded-page injection PNG fixture failed');
    $decodeResult = new ImageDecodeResult(
        $decodedPng,
        'image/png',
        'png',
        9,
        32,
        32,
        1024,
        2,
        3,
        4096,
    );
    $decoder = new ResourceImageDecoder($decodeResult);
    $service = new JmService(context: $context, api: $api, cache: $cache, imageDecoder: $decoder);

    $image = $service->fetchDecodedPage('350234', 1);
    resourceAssertSame($decodedPng, $image['bytes'] ?? null, 'decoded page bytes changed');
    resourceAssertSame('image/png', $image['mime'] ?? null, 'decoded page MIME changed');
    resourceAssertSame('png', $image['codec'] ?? null, 'decoded page codec changed');
    resourceAssertSame(9, $image['upstream_bytes'] ?? null, 'decoded page input-byte metric changed');
    resourceAssertSame([
        ['bytes' => 'raw-image', 'segments' => 2, 'max_pixels' => 1024],
    ], $decoder->calls, 'decoded page did not call the injected decoder with bounded inputs');
    resourceAssertSame([null, null, 64], $transport->bodyLimits, 'only image download should receive the compressed-byte cap');

    apcu_clear_cache();
    putenv('JM_IMAGE_MAX_PIXELS=0');
    $zeroContext = RequestContext::forTest('image-decoder-zero-cap', 12000, 6);
    $zeroTransport = new ResourceChapterTransport(chapterPayload(['00001.jpg']), 'raw-image');
    $zeroApi = JmApiClient::forTest($zeroContext, $zeroTransport, ['https://api.example.test']);
    $zeroDecoder = new ResourceImageDecoder($decodeResult);
    $zeroService = new JmService(context: $zeroContext, api: $zeroApi, cache: $cache, imageDecoder: $zeroDecoder);
    $zeroService->fetchDecodedPage('350234', 1);
    resourceAssertSame([
        ['bytes' => 'raw-image', 'segments' => 2, 'max_pixels' => JmConfig::DEFAULT_IMAGE_MAX_PIXELS],
    ], $zeroDecoder->calls, 'zero pixel configuration was passed through as an unbounded decoder cap');

    apcu_clear_cache();
    putenv('JM_IMAGE_MAX_PIXELS=1024');
    $invalidContext = RequestContext::forTest('image-decoder-invalid-result', 12000, 6);
    $invalidTransport = new ResourceChapterTransport(chapterPayload(['00001.jpg']), 'raw-image');
    $invalidApi = JmApiClient::forTest($invalidContext, $invalidTransport, ['https://api.example.test']);
    $invalidDecoder = new ResourceImageDecoder(new ImageDecodeResult(
        'not-an-image', 'image/png', 'png', 12, 32, 32, 1024, 1, 0, 4096,
    ));
    $invalidService = new JmService(
        context: $invalidContext,
        api: $invalidApi,
        cache: $cache,
        imageDecoder: $invalidDecoder,
    );
    $invalidManifest = $invalidService->fetchReaderManifest('350234');
    $invalidPageKey = (string) ($invalidManifest['images'][0]['cache_key'] ?? '');
    $invalidThrown = null;
    try {
        $invalidService->fetchDecodedPage('350234', 1);
    } catch (Throwable $error) {
        $invalidThrown = $error;
    }
    resourceAssert(
        $invalidThrown instanceof JmException && $invalidThrown->getCode() === 502,
        'invalid injected decoder result was trusted as a decoded page',
    );
    resourceAssertSame(null, $cache->get($invalidPageKey), 'invalid injected decoder result entered the page cache');
    resourceAssertSame(
        null,
        $cache->get($invalidContext->testCacheNamespace() . 'decoded-page-attestation:v2:' . hash('sha256', 'not-an-image')),
        'invalid injected decoder result created an attestation marker',
    );

    putenv('JM_IMAGE_MAX_COMPRESSED_BYTES');
    putenv('JM_IMAGE_MAX_PIXELS');
    putenv('JM_TEST_CDN_BASE_URLS');
    putenv('JM_TEST_ALLOWED_HOSTS');
    putenv('JM_TEST_MODE');
    apcu_clear_cache();
}

function testGdEncoderFalseOrExceptionFailsSafelyAndNeverLeaksOutputBuffers(): void
{
    resourceAssert(extension_loaded('gd'), 'GD encoder failure test requires GD');
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
        true,
    );
    resourceAssert(is_string($png), 'GD encoder fixture failed to decode');

    $failureModes = [
        'false' => static function (GdImage $image, int $quality): bool {
            echo 'partial-output-must-be-discarded';
            return false;
        },
        'exception' => static function (GdImage $image, int $quality): bool {
            echo 'partial-output-must-be-discarded';
            throw new RuntimeException('fixture encoder exception');
        },
    ];
    foreach ($failureModes as $name => $writer) {
        $encoder = new GdImageOutputEncoder(webpWriter: $writer, jpegWriter: $writer);
        $decoder = new GdImageDecoder(outputEncoder: $encoder);
        $beforeLevel = ob_get_level();
        $thrown = null;
        try {
            $decoder->decode($png, 2, 1024);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown instanceof ImageProcessingException, "{$name} encoder failure escaped unsafe");
        resourceAssertSame($beforeLevel, ob_get_level(), "{$name} encoder failure leaked an output buffer");
    }

    $decoded = (new GdImageDecoder())->decode($png, 2, 1024);
    resourceAssert(@getimagesizefromstring($decoded->bytes) !== false, 'normal scrambled output cannot be decoded again');
}

function testGdPostPreflightEncoderFailuresCarryCompleteMetricsAndPreviousChain(): void
{
    resourceAssert(extension_loaded('gd'), 'GD encoder metrics test requires GD');
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
        true,
    );
    resourceAssert(is_string($png), 'GD encoder metrics fixture failed to decode');

    $failureModes = [
        'false' => static function (GdImage $image, int $quality): bool {
            echo 'partial-output-must-be-discarded';
            usleep(30_000);
            return false;
        },
        'exception' => static function (GdImage $image, int $quality): bool {
            echo 'partial-output-must-be-discarded';
            usleep(30_000);
            throw new RuntimeException('fixture encoder metrics exception');
        },
    ];
    foreach ($failureModes as $name => $writer) {
        $decoder = new GdImageDecoder(outputEncoder: new GdImageOutputEncoder(
            webpWriter: $writer,
            jpegWriter: $writer,
        ));
        $beforeLevel = ob_get_level();
        $thrown = null;
        try {
            $decoder->decode($png, 2, 1024);
        } catch (Throwable $error) {
            $thrown = $error;
        }

        resourceAssert($thrown instanceof ImageProcessingException, "{$name} encoder metrics failure escaped unsafe");
        $metrics = $thrown->metrics();
        foreach ([
            'input_bytes', 'width', 'height', 'pixels',
            'decode_ms', 'encode_ms', 'peak_memory_bytes',
        ] as $metric) {
            resourceAssert(
                array_key_exists($metric, $metrics) && is_int($metrics[$metric]) && $metrics[$metric] >= 0,
                "{$name} encoder failure omitted post-preflight metric {$metric}",
            );
        }
        resourceAssertSame(strlen($png), $metrics['input_bytes'], "{$name} encoder input-byte metric changed");
        resourceAssertSame(32, $metrics['width'], "{$name} encoder width metric changed");
        resourceAssertSame(32, $metrics['height'], "{$name} encoder height metric changed");
        resourceAssertSame(1024, $metrics['pixels'], "{$name} encoder pixel metric changed");
        resourceAssert($metrics['encode_ms'] >= 20, "{$name} encoder elapsed time was not measured");
        resourceAssert(
            $metrics['decode_ms'] < $metrics['encode_ms'],
            "{$name} encoder elapsed time leaked into decode_ms",
        );
        resourceAssert($metrics['peak_memory_bytes'] > 0, "{$name} encoder peak-memory metric was empty");
        resourceAssert(
            $thrown->getPrevious() instanceof ImageProcessingException,
            "{$name} encoder failure lost the decoder previous exception",
        );
        if ($name === 'exception') {
            resourceAssert(
                $thrown->getPrevious()?->getPrevious() instanceof RuntimeException
                    && $thrown->getPrevious()?->getPrevious()?->getMessage() === 'fixture encoder metrics exception',
                'throwing encoder lost its original previous exception',
            );
        }
        resourceAssertSame($beforeLevel, ob_get_level(), "{$name} encoder metrics failure leaked an output buffer");
    }

    $decoded = (new GdImageDecoder())->decode($png, 2, 1024);
    resourceAssert(@getimagesizefromstring($decoded->bytes) !== false, 'GD cleanup regression broke decoding after encoder failures');
}

function testDecoderFailureReturns502WithoutCacheOrSingleflightResidue(): void
{
    $cache = new MemoryCache();
    resourceAssert($cache->isAvailable(), 'decoder failure cache test requires APCu CLI');
    apcu_clear_cache();
    putenv('JM_TEST_MODE=1');
    putenv('JM_TEST_ALLOWED_HOSTS=api.example.test,cdn-primary.test,cdn-secondary.test');
    putenv('JM_TEST_CDN_BASE_URLS=https://cdn-primary.test,https://cdn-secondary.test');

    $context = RequestContext::forTest('image-decoder-failure', 12000, 8);
    $transport = new ResourceChapterTransport(chapterPayload(['00001.jpg']), 'bad-image');
    $api = JmApiClient::forTest($context, $transport, ['https://api.example.test']);
    $decoder = new ResourceImageDecoder(new ImageProcessingException('fixture decode failure', [
        'input_bytes' => 9,
        'width' => 32,
        'height' => 32,
        'pixels' => 1024,
        'decode_ms' => 1,
    ]));
    $service = new JmService(context: $context, api: $api, cache: $cache, imageDecoder: $decoder);

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $thrown = null;
        try {
            $service->fetchDecodedPage('350234', 1);
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown instanceof JmException && $thrown->getCode() === 502, "decoder failure {$attempt} was not a safe 502");
    }
    resourceAssertSame(2, count($decoder->calls), 'decoder failure was cached instead of retried');
    resourceAssertSame(4, $transport->calls, 'decoder failure unexpectedly refetched metadata or skipped image retry');

    $imageIdentity = implode(':', [
        '350234',
        '1',
        '00001.jpg',
        '/media/photos/350234/00001.jpg',
        '2',
    ]);
    $pageKey = $context->testCacheNamespace() . 'page:v3:' . hash('sha256', $imageIdentity);
    resourceAssertSame(null, $cache->get($pageKey), 'decoder failure entered decoded page cache');
    resourceAssertSame(null, $cache->get('lock:' . $pageKey), 'decoder failure left a single-flight lock');
}

function testScrambleThresholdGoldenVectorsRemainStableAcrossExtensionsAndQueries(): void
{
    $vectors = [
        [['220980', '220979', '00001.jpg'], 0],
        [['220980', '220980', '00001.jpg'], 10],
        [['220980', '268849', '00001.jpg'], 10],
        [['220980', '268850', '00001.jpg'], 6],
        [['220980', '268851', '00001.jpg'], 12],
        [['220980', '421925', '00001.jpg'], 18],
        [['220980', '421926', '00001.jpg'], 14],
        [['220980', '421927', '00001.jpg'], 8],
        [['220980', '421926', '00001.webp?token=abc'], 14],
        [['268850', '268849', '00047.png'], 0],
        [['268850', '268850', '00047.png'], 6],
        [['421926', '421925', 'page-9.jpeg?x=1'], 0],
        [['421926', '421926', 'page-9.jpeg?x=1'], 14],
    ];
    foreach ($vectors as [$arguments, $expected]) {
        resourceAssertSame(
            $expected,
            ScrambleDecoder::segments(...$arguments),
            'scramble golden vector changed for ' . implode('|', $arguments),
        );
    }
    resourceAssertSame(
        ScrambleDecoder::segments('220980', '421926', '00001.jpg'),
        ScrambleDecoder::segments('220980', '421926', '00001.webp?token=abc'),
        'scramble page-name normalization changed across extension/query',
    );
}

function testScrambleDecimalIdentifiersRemainExactAcrossTwentyDigits(): void
{
    $vectors = [
        [['220980', '9223372036854775807', '00003.jpg'], 4],
        [['220980', '9223372036854775808', '00003.jpg'], 12],
        [['9223372036854775808', '9223372036854775807', '00001.jpg'], 0],
        [['99999999999999999999', '9223372036854775808', '00001.jpg'], 0],
        [['00000000000000220980', '00000000000000421926', '00001.jpg'], 14],
    ];
    foreach ($vectors as [$arguments, $expected]) {
        resourceAssertSame(
            $expected,
            ScrambleDecoder::segments(...$arguments),
            'twenty-digit scramble vector changed for ' . implode('|', $arguments),
        );
    }

    $formerlyColliding = [
        ScrambleDecoder::segments('220980', '9223372036854775808', '00001.jpg'),
        ScrambleDecoder::segments('220980', '99999999999999999999', '00001.jpg'),
    ];
    resourceAssertSame([6, 2], $formerlyColliding, 'distinct twenty-digit IDs collided after native-integer saturation');

    foreach (['', ' ', '+1', '-1', '1.0', '1e3', str_repeat('9', 21)] as $invalid) {
        $thrown = null;
        try {
            ScrambleDecoder::segments($invalid, '350234', '00001.jpg');
        } catch (Throwable $error) {
            $thrown = $error;
        }
        resourceAssert($thrown instanceof InvalidArgumentException, 'invalid scramble decimal was accepted: ' . var_export($invalid, true));
    }
}

function testGdEncoderRevalidatesActualMimeCompleteDecodeAndDimensions(): void
{
    resourceAssert(extension_loaded('gd'), 'GD encoder output validation test requires GD');
    $source = imagecreatetruecolor(32, 32);
    $wrongSize = imagecreatetruecolor(33, 33);
    resourceAssert($source instanceof GdImage && $wrongSize instanceof GdImage, 'encoder validation fixture allocation failed');
    imagefill($source, 0, 0, imagecolorallocate($source, 255, 255, 255));
    imagefill($wrongSize, 0, 0, imagecolorallocate($wrongSize, 0, 0, 0));

    $encodeSelected = static function (GdImage $image): string {
        $level = ob_get_level();
        try {
            ob_start();
            $ok = ScrambleDecoder::preferredDecodedMime() === 'image/webp'
                ? imagewebp($image, null, 85)
                : imagejpeg($image, null, 85);
            $bytes = ob_get_clean();
            resourceAssert($ok === true && is_string($bytes) && $bytes !== '', 'selected encoder fixture failed');
            return $bytes;
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
    };

    try {
        $selectedBytes = $encodeSelected($source);
        $wrongSizeBytes = $encodeSelected($wrongSize);
        $truncated = null;
        for ($length = 1, $total = strlen($selectedBytes); $length < $total; $length++) {
            $candidate = substr($selectedBytes, 0, $length);
            $info = @getimagesizefromstring($candidate);
            if (!is_array($info)) continue;
            $decoded = @imagecreatefromstring($candidate);
            if ($decoded instanceof GdImage) {
                imagedestroy($decoded);
                continue;
            }
            $truncated = $candidate;
            break;
        }
        resourceAssert(is_string($truncated), 'could not construct an identifiable but undecodable selected-format payload');

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAqSURBVFhH7c4xAQAADMOg+TedyegDCrjGBAQEBAQEBAQEBAQEBAQExoF6l/rw4lHYRKwAAAAASUVORK5CYII=',
            true,
        );
        resourceAssert(is_string($png), 'mismatched MIME PNG fixture failed');
        $cases = [
            'mismatched-mime' => $png,
            'truncated-selected-format' => $truncated,
            'wrong-dimensions' => $wrongSizeBytes,
        ];

        foreach ($cases as $name => $writerBytes) {
            $writer = static function (GdImage $image, int $quality) use ($writerBytes): bool {
                echo $writerBytes;
                return true;
            };
            $encoder = new GdImageOutputEncoder(webpWriter: $writer, jpegWriter: $writer);
            $beforeLevel = ob_get_level();
            $thrown = null;
            try {
                $encoder->encode($source);
            } catch (Throwable $error) {
                $thrown = $error;
            }
            resourceAssert($thrown instanceof ImageProcessingException, "encoder accepted {$name} output");
            resourceAssertSame($beforeLevel, ob_get_level(), "encoder {$name} failure leaked an output buffer");
        }
    } finally {
        imagedestroy($source);
        imagedestroy($wrongSize);
    }
}

function testTrustedProxyCidrMatchingIsStrictForIpv4Ipv6AndInvalidBoundaries(): void
{
    $policy = TrustedProxyPolicy::fromCidrs([
        '127.0.0.0/8',
        '192.0.2.128/25',
        '2001:db8::/32',
        '::1/128',
        'invalid',
        '192.0.2.1/33',
        '2001:db8::/129',
        '192.0.2.1/-1',
    ]);

    foreach (['127.0.0.1', '127.255.255.255', '192.0.2.128', '192.0.2.255', '2001:db8::1', '2001:db8:ffff::1', '::1'] as $trusted) {
        resourceAssert($policy->isTrustedProxy($trusted), "trusted proxy boundary {$trusted} was rejected");
    }
    foreach (['126.255.255.255', '128.0.0.0', '192.0.2.127', '192.0.3.0', '2001:db9::1', '::2', 'not-an-ip', '', "127.0.0.1\r\nX: y"] as $untrusted) {
        resourceAssert(!$policy->isTrustedProxy($untrusted), "untrusted/invalid proxy {$untrusted} was accepted");
    }
    resourceAssertSame([], TrustedProxyPolicy::fromCidrs(['invalid', '10.0.0.1', '10.0.0.0/99'])->cidrs(), 'invalid CIDRs did not fail closed');
}

function testForwardedClientIpIsUsedOnlyFromTrustedPeerAndFirstLegalValue(): void
{
    $spoofed = [
        'REMOTE_ADDR' => '192.0.2.44',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        'HTTP_X_REAL_IP' => '203.0.113.98',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.97',
    ];
    resourceAssertSame(
        '192.0.2.44',
        TrustedProxyPolicy::fromCidrs([])->clientIp($spoofed),
        'default empty CIDR policy trusted spoofed forwarding headers',
    );

    $policy = TrustedProxyPolicy::fromCidrs(['127.0.0.0/8', '::1/128']);
    $trusted = $spoofed;
    $trusted['REMOTE_ADDR'] = '127.0.0.1';
    $trusted['HTTP_X_FORWARDED_FOR'] = 'invalid, 198.51.100.7, 203.0.113.8';
    resourceAssertSame('198.51.100.7', $policy->clientIp($trusted), 'trusted XFF did not select the first legal client IP');

    $realIpFallback = $trusted;
    $realIpFallback['HTTP_X_FORWARDED_FOR'] = 'invalid, still-invalid';
    $realIpFallback['HTTP_X_REAL_IP'] = '2001:db8::7';
    resourceAssertSame('2001:db8::7', $policy->clientIp($realIpFallback), 'trusted X-Real-IP fallback changed');

    $cfFallback = $trusted;
    $cfFallback['HTTP_X_FORWARDED_FOR'] = 'invalid';
    $cfFallback['HTTP_X_REAL_IP'] = 'invalid';
    $cfFallback['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
    resourceAssertSame('198.51.100.9', $policy->clientIp($cfFallback), 'trusted CF client IP fallback changed');

    $injected = $trusted;
    $injected['HTTP_X_FORWARDED_FOR'] = "198.51.100.7\r\nX-Evil: yes";
    $injected['HTTP_X_REAL_IP'] = "198.51.100.8\x00";
    $injected['HTTP_CF_CONNECTING_IP'] = 'invalid';
    resourceAssertSame('127.0.0.1', $policy->clientIp($injected), 'forwarded IP header injection was accepted');

    resourceAssertSame(
        '127.0.0.1',
        $policy->clientIp(['REMOTE_ADDR' => 'invalid', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']),
        'invalid peer address was allowed to activate forwarded headers',
    );
}

function testForwardedBaseUrlUsesTheSameTrustedPeerGateAndRejectsUnsafeHostProto(): void
{
    $untrustedPolicy = TrustedProxyPolicy::fromCidrs([]);
    $server = [
        'REMOTE_ADDR' => '192.0.2.44',
        'HTTPS' => 'on',
        'HTTP_HOST' => 'internal.example:8443',
        'SCRIPT_NAME' => '\\proxy\\index.php',
        'HTTP_X_FORWARDED_PROTO' => 'http',
        'HTTP_X_FORWARDED_HOST' => 'attacker.example',
    ];
    resourceAssertSame(
        'https://internal.example:8443/proxy',
        $untrustedPolicy->requestBaseUrl($server),
        'untrusted forwarded proto/host controlled the public base URL',
    );

    $trustedPolicy = TrustedProxyPolicy::fromCidrs(['127.0.0.0/8']);
    $trusted = $server;
    $trusted['REMOTE_ADDR'] = '127.0.0.1';
    $trusted['HTTP_X_FORWARDED_PROTO'] = 'https, http';
    $trusted['HTTP_X_FORWARDED_HOST'] = 'public.example:9443, internal.example';
    resourceAssertSame(
        'https://public.example:9443/proxy',
        $trustedPolicy->requestBaseUrl($trusted),
        'trusted forwarded base URL changed',
    );

    $unsafe = $trusted;
    $unsafe['HTTP_X_FORWARDED_PROTO'] = 'javascript';
    $unsafe['HTTP_X_FORWARDED_HOST'] = "evil.example/path\r\nX: y";
    resourceAssertSame(
        'https://internal.example:8443/proxy',
        $trustedPolicy->requestBaseUrl($unsafe),
        'unsafe forwarded proto/host did not fall back to the direct request',
    );

    $ipv6 = [
        'REMOTE_ADDR' => '::1',
        'HTTP_HOST' => '[2001:db8::1]:8088',
        'SCRIPT_NAME' => '/index.php',
    ];
    resourceAssertSame('http://[2001:db8::1]:8088', TrustedProxyPolicy::fromCidrs([])->requestBaseUrl($ipv6), 'safe IPv6 Host changed');

    $badDirectHost = $server;
    $badDirectHost['HTTP_HOST'] = 'user@evil.example/path';
    unset($badDirectHost['HTTP_X_FORWARDED_PROTO'], $badDirectHost['HTTP_X_FORWARDED_HOST']);
    resourceAssertSame('https://localhost/proxy', $untrustedPolicy->requestBaseUrl($badDirectHost), 'unsafe direct Host was reflected');
}

function testTrustedProxyEnvironmentDefaultsEmptyAndTestOverrideIsExactAndTestOnly(): void
{
    putenv('JM_TRUSTED_PROXY_CIDRS');
    $context = RequestContext::forTest('trusted-proxy-env', 12000, 6);
    $server = [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
    ];

    resourceAssertSame(
        '127.0.0.1',
        TrustedProxyPolicy::fromEnvironment($context, [])->clientIp($server),
        'default trusted proxy policy was not empty',
    );
    resourceAssertSame(
        '127.0.0.1',
        TrustedProxyPolicy::fromEnvironment($context, ['test_trusted_proxy' => 'true'])->clientIp($server),
        'non-exact test trusted proxy override was accepted',
    );
    resourceAssertSame(
        '198.51.100.7',
        TrustedProxyPolicy::fromEnvironment($context, ['test_trusted_proxy' => '1'])->clientIp($server),
        'exact test-mode loopback trusted proxy override was not applied',
    );
    $containerPeer = $server;
    $containerPeer['REMOTE_ADDR'] = '172.18.0.1';
    resourceAssertSame(
        '198.51.100.7',
        TrustedProxyPolicy::fromEnvironment($context, ['test_trusted_proxy' => '1'], '172.18.0.1')->clientIp($containerPeer),
        'exact test-mode override did not trust only the actual container peer',
    );

    putenv('JM_TRUSTED_PROXY_CIDRS=10.0.0.0/8,2001:db8::/32,invalid');
    $configured = TrustedProxyPolicy::fromEnvironment($context, []);
    resourceAssert($configured->isTrustedProxy('10.255.255.255'), 'configured IPv4 trusted proxy CIDR was ignored');
    resourceAssert($configured->isTrustedProxy('2001:db8::1'), 'configured IPv6 trusted proxy CIDR was ignored');
    resourceAssert(!$configured->isTrustedProxy('192.0.2.1'), 'unconfigured proxy was trusted');
    putenv('JM_TRUSTED_PROXY_CIDRS');
}

function testSecurityManagerAndGlobalBaseUrlReuseTrustedProxyPolicy(): void
{
    putenv('JM_TRUSTED_PROXY_CIDRS');
    $server = [
        'REMOTE_ADDR' => '192.0.2.44',
        'HTTPS' => 'on',
        'HTTP_HOST' => 'internal.example:8443',
        'SCRIPT_NAME' => '/proxy/index.php',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        'HTTP_X_FORWARDED_PROTO' => 'http',
        'HTTP_X_FORWARDED_HOST' => 'attacker.example',
    ];
    $policy = TrustedProxyPolicy::fromCidrs([]);
    $security = new SecurityManager(
        store: new RedisStore('resource-test:'),
        trustedProxyPolicy: $policy,
        server: $server,
        query: [],
    );
    resourceAssertSame('192.0.2.44', $security->clientIpForDiagnostics(), 'SecurityManager bypassed trusted proxy policy');

    $previousServer = $_SERVER;
    $previousGet = $_GET;
    try {
        $_SERVER = $server;
        $_GET = [];
        resourceAssertSame('https://internal.example:8443/proxy', requestBaseUrl(), 'global requestBaseUrl bypassed trusted proxy policy');
    } finally {
        $_SERVER = $previousServer;
        $_GET = $previousGet;
    }
}

function testRedisConnectionIsLazyAndFailureCircuitPreventsRepeatedConnects(): void
{
    $nowMs = 1000;
    $clock = static function () use (&$nowMs): int { return $nowMs; };
    $circuit = new RedisFailureCircuit(clockMs: $clock);
    $firstAdapter = new ResourceRedisAdapter(connectResult: false);
    $recoveryAdapter = new ResourceRedisAdapter(connectResult: true, evalResult: [1, 29, 0]);
    $factory = new ResourceRedisFactory([$firstAdapter, $recoveryAdapter]);
    $host = 'redis-' . bin2hex(random_bytes(4)) . '.test';
    putenv('REDIS_HOST=' . $host);
    putenv('REDIS_PORT=6379');
    putenv('REDIS_BREAKER_TTL_SECONDS=5');

    $first = new RedisStore(prefix: 'resource:', factory: $factory, failureCircuit: $circuit);
    resourceAssertSame(0, $factory->createCalls, 'RedisStore constructor connected eagerly');
    resourceAssertSame([true, 30, 0], $first->checkRate('client', 60, 30), 'Redis connect failure did not fail open');
    resourceAssertSame(1, $factory->createCalls, 'first Redis operation did not attempt exactly one connection');
    resourceAssertSame(1, $firstAdapter->connectCalls, 'first Redis adapter connect count changed');

    $second = new RedisStore(prefix: 'resource:', factory: $factory, failureCircuit: $circuit);
    resourceAssertSame([true, 30, 0], $second->checkRate('client', 60, 30), 'open Redis circuit did not fail open');
    resourceAssertSame(1, $factory->createCalls, 'open Redis circuit repeated a connection attempt');

    $nowMs += 5001;
    $third = new RedisStore(prefix: 'resource:', factory: $factory, failureCircuit: $circuit);
    resourceAssertSame([true, 29, 0], $third->checkRate('client', 60, 30), 'Redis circuit did not allow recovery after TTL');
    resourceAssertSame(2, $factory->createCalls, 'Redis recovery did not attempt one new connection');
    resourceAssertSame(1, $recoveryAdapter->connectCalls, 'recovery Redis adapter connect count changed');

    putenv('REDIS_HOST');
    putenv('REDIS_PORT');
    putenv('REDIS_BREAKER_TTL_SECONDS');
}

function testRedisConfiguresBothConnectAndReadTimeouts(): void
{
    $adapter = new ResourceRedisAdapter(connectResult: true, evalResult: [1, 29, 0]);
    $factory = new ResourceRedisFactory([$adapter]);
    $host = 'redis-timeouts-' . bin2hex(random_bytes(4)) . '.test';
    putenv('REDIS_HOST=' . $host);
    putenv('REDIS_PORT=6381');
    putenv('REDIS_TIMEOUT_MS=750');

    try {
        $store = new RedisStore(
            prefix: 'resource:',
            factory: $factory,
            failureCircuit: new RedisFailureCircuit(),
        );
        resourceAssertSame(0, $factory->createCalls, 'Redis timeout configuration connected eagerly');
        resourceAssertSame([true, 29, 0], $store->checkRate('client', 60, 30), 'Redis timeout test rate result changed');
        resourceAssertSame(1, count($adapter->connectArguments), 'Redis timeout test did not connect exactly once');
        resourceAssertSame([
            'host' => $host,
            'port' => 6381,
            'connect_timeout_seconds' => 0.75,
            'read_timeout_seconds' => 0.75,
        ], $adapter->connectArguments[0], 'Redis did not configure bounded connect and read timeouts together');
    } finally {
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');
        putenv('REDIS_TIMEOUT_MS');
    }
}

function testRedisSlidingWindowUsesOneEvalAndParsesAllowedAndRejectedResults(): void
{
    $cases = [
        'allowed' => [[1, 29, 0], [true, 29, 0]],
        'rejected' => [['0', '0', '17'], [false, 0, 17]],
    ];
    foreach ($cases as $name => [$redisResult, $expected]) {
        $adapter = new ResourceRedisAdapter(connectResult: true, evalResult: $redisResult);
        $factory = new ResourceRedisFactory([$adapter]);
        $host = 'redis-' . $name . '-' . bin2hex(random_bytes(4)) . '.test';
        putenv('REDIS_HOST=' . $host);
        $store = new RedisStore(
            prefix: 'resource:',
            factory: $factory,
            failureCircuit: new RedisFailureCircuit(),
        );
        resourceAssertSame($expected, $store->checkRate('198.51.100.7', 60, 30), "Redis {$name} result parsing changed");
        resourceAssertSame(1, $adapter->evalCalls, "Redis {$name} rate check issued more than one EVAL");
        resourceAssertSame(1, count($adapter->evalArguments), "Redis {$name} EVAL arguments missing");
        $call = $adapter->evalArguments[0];
        resourceAssertSame(1, $call['num_keys'], "Redis {$name} EVAL key count changed");
        resourceAssertSame('rate:198.51.100.7', $call['args'][0] ?? null, "Redis {$name} EVAL key changed");
        foreach (['ZREMRANGEBYSCORE', 'ZCARD', 'ZRANGE', 'ZADD', 'EXPIRE'] as $command) {
            resourceAssert(str_contains($call['script'], $command), "Redis Lua omitted {$command}");
        }
        resourceAssertSame('resource:', $adapter->prefix, "Redis {$name} prefix was not configured");
    }
    putenv('REDIS_HOST');
}

function testRedisSlidingWindowClampsClockRollbackInsideAtomicLua(): void
{
    $adapter = new ResourceRedisAdapter(connectResult: true, evalResult: [0, 0, 60]);
    $factory = new ResourceRedisFactory([$adapter]);
    $host = 'redis-clock-rollback-' . bin2hex(random_bytes(4)) . '.test';
    putenv('REDIS_HOST=' . $host);

    try {
        $store = new RedisStore(
            prefix: 'resource:',
            factory: $factory,
            failureCircuit: new RedisFailureCircuit(),
        );
        resourceAssertSame(
            [false, 0, 60],
            $store->checkRate('198.51.100.8', 60, 30),
            'bounded rollback rejection was parsed as a failure or fail-open result',
        );
        resourceAssertSame(1, count($adapter->evalArguments), 'rollback defense did not remain one atomic EVAL');
        $script = $adapter->evalArguments[0]['script'];
        resourceAssert(str_contains($script, 'ZREVRANGE'), 'Redis Lua did not inspect the newest score before pruning');
        resourceAssert(
            str_contains($script, 'local effectiveNow = nowMs')
                && str_contains($script, 'effectiveNow = math.max(effectiveNow, tonumber(newest[2]))')
                && str_contains($script, 'local cutoffMs = effectiveNow - windowMs'),
            'Redis Lua did not derive its cutoff from a monotonic effective time',
        );
        resourceAssert(
            !str_contains($script, "redis.call('TIME')"),
            'Redis Lua rollback defense depends on TIME instead of Redis 3.2-compatible primitives',
        );
    } finally {
        putenv('REDIS_HOST');
    }
}

function testRedisMalformedOrFailedCommandsFailOpenAndTripSharedCircuit(): void
{
    $failureAdapters = [
        'malformed-eval' => new ResourceRedisAdapter(evalResult: [1]),
        'rejected-nonzero-remaining' => new ResourceRedisAdapter(evalResult: [0, 5, 0]),
        'rejected-retry-over-window' => new ResourceRedisAdapter(evalResult: [0, 0, 61]),
        'allowed-nonzero-retry' => new ResourceRedisAdapter(evalResult: [1, 29, 17]),
        'allowed-max-remaining' => new ResourceRedisAdapter(evalResult: [1, 30, 0]),
        'overflow-allowed' => new ResourceRedisAdapter(evalResult: ['99999999999999999999', 0, 0]),
        'overflow-remaining' => new ResourceRedisAdapter(evalResult: [1, '99999999999999999999', 0]),
        'overflow-retry' => new ResourceRedisAdapter(evalResult: [0, 0, '99999999999999999999']),
        'throwing-eval' => new ResourceRedisAdapter(throwOnEval: true),
        'throwing-command' => new ResourceRedisAdapter(throwOnCommands: true),
    ];
    foreach ($failureAdapters as $name => $failingAdapter) {
        $recoveryAdapter = new ResourceRedisAdapter(evalResult: [1, 29, 0]);
        $factory = new ResourceRedisFactory([$failingAdapter, $recoveryAdapter]);
        $host = 'redis-failure-' . $name . '-' . bin2hex(random_bytes(4)) . '.test';
        putenv('REDIS_HOST=' . $host);
        $circuit = new RedisFailureCircuit();
        $store = new RedisStore(prefix: 'resource:', factory: $factory, failureCircuit: $circuit);

        if ($name === 'throwing-command') {
            resourceAssertSame(false, $store->isBanned('client'), 'Redis command failure did not fail open');
        } else {
            resourceAssertSame([true, 30, 0], $store->checkRate('client', 60, 30), "Redis {$name} did not fail open");
        }
        resourceAssertSame(1, $factory->createCalls, "Redis {$name} did not make exactly one initial connection");

        $suppressed = new RedisStore(prefix: 'resource:', factory: $factory, failureCircuit: $circuit);
        resourceAssertSame([true, 30, 0], $suppressed->checkRate('client', 60, 30), "Redis {$name} open circuit did not fail open");
        resourceAssertSame(1, $factory->createCalls, "Redis {$name} open circuit did not suppress reconnect");
    }
    putenv('REDIS_HOST');
}

function testApcuDiagnosticsCacheFragmentationAndMapRuntimeCounters(): void
{
    resourceAssert(function_exists('apcu_enabled') && apcu_enabled(), 'APCu diagnostics test requires APCu CLI mode');
    resourceAssert(apcu_clear_cache(), 'APCu diagnostics test could not clear the cache');
    $prefix = 'resource-diagnostics-' . bin2hex(random_bytes(4)) . ':';
    $cache = new MemoryCache($prefix);

    $heldLeaseKey = $prefix . 'diagnostics:apcu-fragmentation-lease:v1';
    resourceAssert(apcu_add($heldLeaseKey, 717171, 1), 'APCu diagnostics test could not hold the sampler lease');
    $leaseLoser = $cache->diagnostics();
    foreach (['largest_free_block_bytes', 'fragmentation_ratio', 'fragmentation_sampled_at', 'fragmentation_cached'] as $field) {
        resourceAssertSame(null, $leaseLoser[$field], "APCu fragmentation lease loser unexpectedly sampled {$field}");
    }
    resourceAssert(apcu_delete($heldLeaseKey), 'APCu diagnostics test could not release the held sampler lease');

    $first = $cache->diagnostics();
    $second = $cache->diagnostics();
    foreach ([
        'largest_free_block_bytes',
        'fragmentation_ratio',
        'fragmentation_sampled_at',
        'fragmentation_cached',
        'inserts',
        'expunges',
        'cleanups',
        'defragmentations',
    ] as $field) {
        resourceAssert(array_key_exists($field, $first), "APCu diagnostics missing {$field}");
    }

    resourceAssertSame(false, $first['fragmentation_cached'], 'first APCu fragmentation sample was not produced by the owner');
    resourceAssertSame(true, $second['fragmentation_cached'], 'second APCu fragmentation sample did not use the short cache');
    resourceAssert(is_int($first['fragmentation_sampled_at']) && $first['fragmentation_sampled_at'] > 0, 'APCu fragmentation sample timestamp is invalid');
    resourceAssertSame($first['fragmentation_sampled_at'], $second['fragmentation_sampled_at'], 'APCu fragmentation cache did not reuse the same sample');
    resourceAssert(is_int($first['free_memory_bytes']) && $first['free_memory_bytes'] >= 0, 'APCu diagnostics free bytes are invalid');
    resourceAssert(is_int($first['largest_free_block_bytes']) && $first['largest_free_block_bytes'] >= 0, 'APCu largest free block is invalid');
    resourceAssert($first['largest_free_block_bytes'] <= $first['free_memory_bytes'], 'APCu largest free block exceeds total free bytes');
    $expectedFragmentation = $first['free_memory_bytes'] === 0
        ? 0
        : (int) floor((max(0, $first['free_memory_bytes'] - $first['largest_free_block_bytes']) * 100) / $first['free_memory_bytes']);
    resourceAssertSame($expectedFragmentation, $first['fragmentation_ratio'], 'APCu fragmentation ratio formula changed');

    $runtime = apcu_cache_info(true);
    resourceAssert(is_array($runtime), 'APCu runtime counters are unavailable');
    resourceAssertSame((int) $runtime['expunges'], $second['expunges'], 'APCu expunges mapping changed');
    resourceAssertSame((int) $runtime['cleanups'], $second['cleanups'], 'APCu cleanups mapping changed');
    resourceAssertSame((int) $runtime['defragmentations'], $second['defragmentations'], 'APCu defragmentations mapping changed');
}

$tests = [
    'chapter-strings' => 'testChapterStringsUseOnlyValidatedRelativeMediaPaths',
    'chapter-object-mixed' => 'testChapterObjectAndMixedImagesNormalizeWithoutDroppingOrReorderingPages',
    'chapter-malformed-empty' => 'testMalformedChapterImagesFailTheWholePayloadWhileExplicitEmptyRemainsValid',
    'chapter-json-object-containers' => 'testChapterJsonObjectContainersFailThroughEncryptedProductionChainWithoutCaching',
    'json-root-list-provenance' => 'testRootJsonListProvenanceRejectsObjectsAndCachesOnlyTrueEmptyLists',
    'json-nested-list-provenance' => 'testNestedJsonListProvenanceRejectsObjectsAndCachesOnlyTrueEmptyLists',
    'json-list-item-field-provenance' => 'testListItemJsonListFieldsRejectObjectsBeforeCaching',
    'list-item-id-validation' => 'testListItemIdsMustBeCanonicalJmIdsBeforeCaching',
    'json-album-list-field-provenance' => 'testAlbumJsonListFieldsRejectObjectsBeforeCaching',
    'json-chapter-root-series-provenance' => 'testChapterRootAndSeriesJsonContainersFailSafelyBeforeCaching',
    'json-chapter-extra-metadata-forward-compat' => 'testChapterImageUnknownJsonMetadataIsIgnoredBeforeCaching',
    'json-list-item-extra-metadata-forward-compat' => 'testListItemUnknownJsonMetadataIsProjectedOutBeforeCaching',
    'json-album-extra-metadata-forward-compat' => 'testAlbumUnknownJsonMetadataIsProjectedOutBeforeCaching',
    'chapter-id' => 'testChapterPhotoIdMustBeOneToTwentyDigitsAndMatchTheRequestedId',
    'scramble-cache-poison' => 'testInvalidScrambleIdsNeverEnterChapterOrManifestCaches',
    'chapter-public-json' => 'testChapterPublicJsonMaterializesStableAbsoluteSourceWithoutLeakingMediaPath',
    'cover-stable' => 'testCoverCdnSelectionIsStableAndEpochControlledWhileAbsoluteUrlsArePreserved',
    'chapter-cache-v2' => 'testChapterV2CachePayloadRoundTripsOnlyValidatedRelativePaths',
    'chapter-service-cache-v2' => 'testChapterServiceUsesOnlyStrictV2CacheKeysAndPayloads',
    'test-cache-namespace' => 'testTestModeWithoutRunIdNeverSharesTheProductionCacheNamespace',
    'manifest-cache-v2' => 'testReaderManifestUsesStrictV2RelativePathSchemaAndIgnoresLegacyEntries',
    'manifest-cache-forgery' => 'testReaderManifestRejectsForgedImageIdentityAndDerivedFields',
    'manifest-cache-unsegmented-mime' => 'testReaderManifestValidatesUnsegmentedMimeFromTheOriginalFilename',
    'chapter-malformed-not-cached' => 'testMalformedChapterPayloadIsNeverSwallowedOrCachedAsPartialSuccess',
    'chapter-complete-failure' => 'testCompleteChapterFailureIsNotReturnedAsSuccessfulEmptyData',
    'chapter-ttl-zero-bypass' => 'testChapterTtlZeroBypassesExistingCacheAndNeverWritesFreshData',
    'chapter-ttl-zero-failure' => 'testChapterTtlZeroNeverFallsBackToExistingCacheWhenProducerFails',
    'manifest-ttl-zero-bypass' => 'testManifestTtlZeroBypassesExistingManifestAndChapterCaches',
    'manifest-ttl-zero-failure' => 'testManifestTtlZeroNeverFallsBackToExistingCacheWhenProducerFails',
    'page-ttl-zero-bypass' => 'testPageTtlZeroBypassesMaliciousDecodedCacheAndNeverWrites',
    'page-ttl-zero-failure' => 'testPageTtlZeroNeverFallsBackToDecodedCacheWhenProducerFails',
    'page-cache-poison' => 'testDecodedPageCacheRejectsMalformedEnvelopesAndRebuilds',
    'page-cache-attestation' => 'testDecodedPageAttestationAvoidsRepeatedFullDecodeAndRebuildsAfterMarkerLoss',
    'page-cache-production-write-once' => 'testProductionDecodedPageWritePerformsOneCompleteValidation',
    'page-cache-digest-binding' => 'testDecodedPageAttestationBindsActualBytesRejectsTruncationAndIgnoresV2',
    'page-cache-attestation-disabled' => 'testDisabledDecodedPageCacheNeverValidatesOrWritesAttestation',
    'page-cache-attestation-unavailable' => 'testUnavailableApcuNeverValidatesOrWritesDecodedPageAttestation',
    'unicode-control-cache-poison' => 'testUnicodeControlCachePoisonCannotReachDecodedPage',
    'page-ttl-zero-prefetch' => 'testPageTtlZeroDisablesPrefetchAndCacheDependentDiagnostics',
    'cdn-primary' => 'testCdnPrimaryIsStableAllowlistedAndSuccessfulRequestsNeverTouchSecondary',
    'cdn-failover-policy' => 'testCdnFailoverOccursExactlyOnceOnlyForNetworkOrHttp5xxFailures',
    'cdn-health-secondary' => 'testCdnSecondaryUsesExistingHealthAcrossAllAllowlistedCandidates',
    'compressed-body-collector' => 'testCompressedBodyCollectorEnforcesExactForgedAndMissingLengthBoundariesBeforeAppend',
    'compressed-body-limit' => 'testCompressedBodyLimitHasDistinctStatusAndNeverTriggersCdnFailover',
    'image-pixel-policy' => 'testImageDecoderValidatesDimensionsAndPixelCapsBeforeEveryBypassOrGdPath',
    'image-passthrough-validation' => 'testImageDecoderFullyValidatesTruncatedPngAndGifPassthroughs',
    'image-container-tail-truncation' => 'testDecodedPageRejectsEveryTailTruncatedPngAndGifBeforeAttestation',
    'image-gif-lzw-integrity' => 'testGifLzwTruncationCannotPoisonDecodedPageCache',
    'image-gif-lzw-boundaries' => 'testGifLzwCodeBoundariesAndAnimationFrames',
    'image-gif-frame-bounds' => 'testGifFramesStayInsideLogicalScreen',
    'image-gif-streaming-memory' => 'testGifOneByteSubBlocksAreRejectedWithoutAmplifiedMemory',
    'image-segmented-raw-container' => 'testSegmentedDecoderRejectsIncompleteRawContainerBeforeGdReencode',
    'image-container-structure' => 'testImageContainerPolicyRejectsMalformedStructureAndTrailingDataAcrossFormats',
    'image-container-webp-animation' => 'testImageContainerPolicyValidatesAnimatedWebpFramePayloads',
    'image-passthrough-502' => 'testTruncatedPassthroughFailuresReturn502AndNeverWriteDecodedCache',
    'image-decoder-injection' => 'testDecodedPageUsesInjectedDecoderAndRecordsBoundedImageMetrics',
    'image-encoder-failures' => 'testGdEncoderFalseOrExceptionFailsSafelyAndNeverLeaksOutputBuffers',
    'image-encoder-failure-metrics' => 'testGdPostPreflightEncoderFailuresCarryCompleteMetricsAndPreviousChain',
    'image-encoder-output-validation' => 'testGdEncoderRevalidatesActualMimeCompleteDecodeAndDimensions',
    'image-decoder-failure-cleanup' => 'testDecoderFailureReturns502WithoutCacheOrSingleflightResidue',
    'scramble-golden' => 'testScrambleThresholdGoldenVectorsRemainStableAcrossExtensionsAndQueries',
    'scramble-decimal-20' => 'testScrambleDecimalIdentifiersRemainExactAcrossTwentyDigits',
    'trusted-proxy-cidr' => 'testTrustedProxyCidrMatchingIsStrictForIpv4Ipv6AndInvalidBoundaries',
    'trusted-proxy-client-ip' => 'testForwardedClientIpIsUsedOnlyFromTrustedPeerAndFirstLegalValue',
    'trusted-proxy-base-url' => 'testForwardedBaseUrlUsesTheSameTrustedPeerGateAndRejectsUnsafeHostProto',
    'trusted-proxy-environment' => 'testTrustedProxyEnvironmentDefaultsEmptyAndTestOverrideIsExactAndTestOnly',
    'trusted-proxy-integration' => 'testSecurityManagerAndGlobalBaseUrlReuseTrustedProxyPolicy',
    'redis-lazy-breaker' => 'testRedisConnectionIsLazyAndFailureCircuitPreventsRepeatedConnects',
    'redis-timeouts' => 'testRedisConfiguresBothConnectAndReadTimeouts',
    'redis-lua-results' => 'testRedisSlidingWindowUsesOneEvalAndParsesAllowedAndRejectedResults',
    'redis-clock-rollback' => 'testRedisSlidingWindowClampsClockRollbackInsideAtomicLua',
    'redis-failure-circuit' => 'testRedisMalformedOrFailedCommandsFailOpenAndTripSharedCircuit',
    'apcu-diagnostics' => 'testApcuDiagnosticsCacheFragmentationAndMapRuntimeCounters',
];

$selected = $argv[1] ?? 'all';
if ($selected !== 'all' && !isset($tests[$selected])) {
    throw new InvalidArgumentException('Unknown resource-policy test: ' . $selected);
}

$executed = 0;
foreach ($tests as $name => $test) {
    if ($selected !== 'all' && $selected !== $name) continue;
    $test();
    $executed++;
    fwrite(STDOUT, "PASS {$name}\n");
}

fwrite(STDOUT, "Resource policy runtime passed ({$executed} tests).\n");
