<?php
/**
 * JM Comic Viewer — 诊断探针
 * 
 * 访问此文件检查 PHP 环境是否正常。
 * 然后逐步排查 502 问题。
 */

declare(strict_types=1);

// ── 1. 立即输出，确认 PHP 在跑 ──

// 写系统 error_log（不走文件系统）
error_log('[jm-probe] PHP executing at ' . date('Y-m-d H:i:s'));

// 尝试写文件日志
$logFile = __DIR__ . '/probe.log';
$fp = @fopen($logFile, 'a');
$canWriteFile = is_resource($fp);

echo "<pre>\n";
echo "=== JM 诊断探针 ===\n\n";

// ── 2. 环境信息 ──

echo "PHP 版本:    " . PHP_VERSION . "\n";
echo "当前文件:    " . __FILE__ . "\n";
echo "当前目录:    " . __DIR__ . "\n";
echo "磁盘写入:    " . ($canWriteFile ? "✅ {$logFile}" : "❌ 无法写入") . "\n";
echo "内存:        " . number_format(memory_get_usage(true)) . " bytes\n";
echo "最大执行:    " . ini_get('max_execution_time') . "s\n";
echo "内存限制:    " . ini_get('memory_limit') . "\n";

if ($canWriteFile) {
    fwrite($fp, date('[Y-m-d H:i:s] ') . "probe ran OK\n");
    fclose($fp);
}

// ── 3. 扩展检查 ──

echo "\n--- 扩展 ---\n";
foreach (['curl', 'openssl', 'json', 'mbstring', 'gd'] as $ext) {
    $ok = extension_loaded($ext);
    echo "{$ext}: " . ($ok ? "✅" : "❌") . "\n";
}

// ── 4. cURL 版本 ──

echo "\n--- cURL ---\n";
if (function_exists('curl_version')) {
    $cv = curl_version();
    echo "版本: {$cv['version']}\n";
    echo "SSL:  {$cv['ssl_version']}\n";
} else {
    echo "❌ cURL 不可用\n";
}

// ── 5. API 域名连通性测试 ──

$domains = [
    'www.cdnhjk.net',
    'www.cdngwc.cc',
    'www.cdngwc.net',
    'www.cdngwc.club',
    'www.cdnutc.me',
];

echo "\n--- API 域名连通性 ---\n";
foreach ($domains as $domain) {
    $start = hrtime(true);
    $ch = curl_init("https://{$domain}/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOBODY         => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $ms    = round((hrtime(true) - $start) / 1_000_000);
    $err   = curl_errno($ch) ? curl_error($ch) : null;
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_reset($ch);
    
    $status = $err ? "❌ {$err}" : "✅ HTTP {$http}";
    echo "{$domain}: {$status} ({$ms}ms)\n";
}

// ── 6. 完整 API 请求测试 ──

echo "\n--- API 请求测试 (/album?id=350234) ---\n";

$ts = (string) time();
$token = md5($ts . '185Hcomic3PAPP7R');
$tokenparam = "{$ts},2.0.26";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://www.cdnhjk.net/album?id=350234",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER     => [
        'Accept-Encoding: gzip, deflate',
        'User-Agent: Mozilla/5.0 (Linux; Android 9; V1938CT) AppleWebKit/537.36',
        'token: ' . $token,
        'tokenparam: ' . $tokenparam,
    ],
    CURLOPT_ENCODING       => '',
    CURLOPT_SSL_VERIFYPEER => true,
]);

$start = hrtime(true);
$body = curl_exec($ch);
$ms   = round((hrtime(true) - $start) / 1_000_000);
$err  = curl_errno($ch) ? '[' . curl_errno($ch) . '] ' . curl_error($ch) : null;
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$len  = strlen((string) $body);
curl_reset($ch);

echo "耗时: {$ms}ms\n";
echo "HTTP: {$code}\n";
echo "响应大小: {$len} bytes\n";
if ($err) echo "错误: {$err}\n";

if ($code === 200 && $len > 0) {
    $json = json_decode($body, true);
    if ($json && isset($json['code'])) {
        echo "API code: {$json['code']}\n";
        if ($json['code'] === 200) {
            echo "data 有值: " . (isset($json['data']) ? 'yes (' . strlen($json['data']) . ' chars)' : 'no') . "\n";
            
            // 尝试解密
            if (isset($json['data'])) {
                $cipher = base64_decode($json['data'], true);
                if ($cipher) {
                    $key = md5($ts . '185Hcomic3PAPP7R');
                    $plain = openssl_decrypt($cipher, 'AES-256-ECB', $key,
                        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
                    if ($plain) {
                        $pad = ord($plain[strlen($plain)-1]);
                        $plain = substr($plain, 0, strlen($plain) - $pad);
                        $album = json_decode($plain, true);
                        if ($album) {
                            echo "✅ 解密成功! 专辑: {$album['name']}\n";
                            echo "   章节数: " . count($album['series'] ?? []) . "\n";
                            echo "   标签: " . implode(', ', $album['tags'] ?? []) . "\n";
                        } else {
                            echo "❌ 解密后 JSON 解析失败\n";
                            echo "   解密后前200字符: " . substr($plain, 0, 200) . "\n";
                        }
                    } else {
                        echo "❌ AES 解密失败\n";
                    }
                } else {
                    echo "❌ Base64 解码失败\n";
                }
            }
        } else {
            echo "API 错误: {$json['errorMsg']}\n";
        }
    } else {
        echo "响应前500字符: " . substr($body, 0, 500) . "\n";
    }
}

echo "\n=== 诊断完成 ===</pre>\n";
