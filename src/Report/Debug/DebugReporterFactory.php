<?php

declare(strict_types=1);

namespace Infection\Report\Debug;

use Infection\Configuration\Entry\Logs;
use Infection\Metrics\MetricsCalculator;
use Infection\Metrics\ResultsCollector;
use Infection\Report\ComposableReporter;
use Infection\Report\Framework\Factory\ReporterFactory;
use Infection\Report\Framework\Writer\FileWriter;
use Infection\Report\NullReporter;
use Infection\Report\Reporter;
use Symfony\Component\Filesystem\Filesystem;

final readonly class DebugReporterFactory implements ReporterFactory
{
    public function __construct(
        private MetricsCalculator $metricsCalculator,
        private ResultsCollector $resultsCollector,
        private Filesystem $filesystem,
        private bool $onlyCoveredCode,
    ) {
    }

    public function create(Logs $logConfig): Reporter
    {
        $filePath = $logConfig->getDebugLogFilePath();

        return null === $filePath
            ? new NullReporter()
            : new ComposableReporter(
                new DebugProducer(
                    $this->metricsCalculator,
                    $this->resultsCollector,
                    $this->onlyCoveredCode,
                ),
                new FileWriter(
                    $this->filesystem,
                    $logConfig->getDebugLogFilePath(),
                ),
            );
    }
}
