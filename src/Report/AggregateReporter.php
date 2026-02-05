<?php

declare(strict_types=1);

namespace Infection\Report;

use Infection\Reporter\Reporter;

final readonly class AggregateReporter implements Reporter
{
    /**
     * @param Reporter[] $reporters
     */
    public function __construct(
        private array $reporters,
    ) {
    }

    public function report(): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->report();
        }
    }
}
