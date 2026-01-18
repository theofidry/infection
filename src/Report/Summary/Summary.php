<?php

declare(strict_types=1);

namespace Infection\Report\Summary;

final readonly class Summary
{
    public function __construct(
        public int $totalMutantsCount,
        public int $killedCount,
        public int $notCoveredCount,
        public int $escapedCount,
        public int $errorCount,
        public int $syntaxErrorCount,
        public int $skippedCount,
        public int $ignoredCount,
        public int $timeOutCount,
        public float $msi,
        public float $mutationCodeCoverage,
        public float $coveredCodeMsi,
    ) {
    }
}