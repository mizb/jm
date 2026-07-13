<?php
declare(strict_types=1);

$sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read index.php');
}

if (!preg_match(
    '/function normalizeCatalogOrder\(mixed \$value\): string\s*\{[\s\S]*?\n\}/',
    $source,
    $match,
)) {
    throw new RuntimeException('Unable to isolate normalizeCatalogOrder');
}

eval($match[0]);

$cases = [
    [null, 'new'],
    ['', 'new'],
    ['   ', 'new'],
    ['new', 'new'],
    [' MV ', 'mv'],
    ['TF', 'tf'],
    ['mp', 'new'],
    [['mv'], 'new'],
];

foreach ($cases as [$input, $expected]) {
    $actual = normalizeCatalogOrder($input);
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            'normalizeCatalogOrder(%s) expected %s, got %s',
            var_export($input, true),
            $expected,
            $actual,
        ));
    }
}

function selectedOrder(array $query): string
{
    return normalizeCatalogOrder($query['order'] ?? $query['o'] ?? 'new');
}

$aliasCases = [
    [[], 'new'],
    [['o' => 'mv'], 'mv'],
    [['order' => 'TF', 'o' => 'mv'], 'tf'],
    [['order' => '', 'o' => 'mv'], 'new'],
    [['order' => ['mv'], 'o' => 'tf'], 'new'],
];

foreach ($aliasCases as [$query, $expected]) {
    $actual = selectedOrder($query);
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            'selectedOrder(%s) expected %s, got %s',
            var_export($query, true),
            $expected,
            $actual,
        ));
    }
}

fwrite(STDOUT, "Catalog order runtime checks passed.\n");
