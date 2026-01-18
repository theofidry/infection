<?php

declare(strict_types=1);

namespace Infection\Report\Framework\Factory;

use Infection\Configuration\Entry\Logs;
use Infection\Report\AggregateReporter;
use Infection\Report\Reporter;
use function array_map;

final class AggregateReporterFactory implements ReporterFactory
{
    /**
     * @param ReporterFactory[] $factories
     */
    public function __construct(
        private array $factories,
    ) {
    }

    public function report(): void
    {
        foreach ($this->factories as $reporter) {
            $reporter->report();
        }
    }

    public function create(Logs $logConfig): Reporter
    {
        return new AggregateReporter(
            array_map(
                static fn (ReporterFactory $factory) => $factory->create($logConfig),
                $this->factories,
            ),
        );
    }
}
