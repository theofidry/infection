#!/usr/bin/env php
<?php

declare(strict_types=1);

use Infection\DevTools\MutatorPerformanceWorkbook\WorkbookBuilder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php devTools/mutator-performance-workbook/build.php <report.jsonl> <workbook.xlsx>\n");

    exit(1);
}

try {
    (new WorkbookBuilder())->build($argv[1], $argv[2]);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");

    exit(1);
}

fwrite(STDOUT, "Created {$argv[2]}\n");
