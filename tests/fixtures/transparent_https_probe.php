<?php

declare(strict_types=1);

$url = (string) getenv('JM_PROXY_TEST_URL');
$runId = (string) getenv('JM_PROXY_TEST_RUN_ID');
if ($url === '' || $runId === '') {
    fwrite(STDERR, "proxy probe environment is incomplete\n");
    exit(20);
}

$curl = curl_init($url);
if ($curl === false) {
    fwrite(STDERR, "curl_init failed\n");
    exit(21);
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['X-JM-Test-Run-Id: ' . $runId],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$body = curl_exec($curl);
if ($body === false) {
    fwrite(STDERR, 'curl: ' . curl_error($curl) . "\n");
    exit(22);
}
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
if ($status !== 200) {
    fwrite(STDERR, 'status: ' . $status . "\n");
    exit(23);
}

echo base64_encode($body), "\n";
