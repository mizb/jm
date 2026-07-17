<?php

declare(strict_types=1);

define('JM_API_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/index.php';

foreach (['https://example.test/album/123oops', 'https://example.test/?id=123oops'] as $invalid) {
    try {
        InputValidator::parseJmId($invalid);
        fwrite(STDERR, "accepted partial JM ID: {$invalid}\n");
        exit(31);
    } catch (SecurityException) {
    }
}

$valid = [
    'https://example.test/album/123?from=library' => '123',
    'https://example.test/?jmid=456&from=library' => '456',
];
foreach ($valid as $input => $expected) {
    $actual = InputValidator::parseJmId($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "JM ID URL parsed as {$actual}, expected {$expected}: {$input}\n");
        exit(32);
    }
}

$album = JmAlbum::fromApiResponse([
    'id' => '100',
    'name' => 'Validation fixture',
    'series' => [
        ['id' => '101', 'sort' => '1'],
        ['id' => '102', 'sort' => '2'],
    ],
], '100');
foreach (['101,999', '101,bad', '101,'] as $invalidBatch) {
    try {
        InputValidator::validateChapterParam($invalidBatch, $album);
        fwrite(STDERR, "accepted partial chapter batch: {$invalidBatch}\n");
        exit(33);
    } catch (SecurityException) {
    }
}
$deduplicated = InputValidator::validateChapterParam('101,101,102', $album);
if ($deduplicated !== ['101', '102']) {
    fwrite(STDERR, 'chapter batch was not stably deduplicated: ' . json_encode($deduplicated) . "\n");
    exit(34);
}

fwrite(STDOUT, "Input validation policy runtime passed.\n");
