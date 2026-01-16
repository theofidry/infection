<?php

declare(strict_types=1);

namespace Infection\Report\GitHub;

use Infection\Configuration\Entry\Logs;
use Infection\Metrics\MetricsCalculator;
use Infection\Metrics\ResultsCollector;
use Infection\Report\ComposableReporter;
use Infection\Report\Factory\ReporterFactory;
use Infection\Report\NullReporter;
use Infection\Report\Reporter;
use Infection\Report\Stryker\StrykerHtmlReportBuilder;
use Infection\Report\Writer\StreamWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class GitHubAnnotationsFactory implements ReporterFactory
{
    public function __construct(
        private MetricsCalculator $metricsCalculator,
        private ResultsCollector $resultsCollector,
        private Filesystem $filesystem,
        private string $logVerbosity,
        private bool $debugMode,
        private bool $onlyCoveredCode,
        private LoggerInterface $logger,
        private StrykerHtmlReportBuilder $strykerHtmlReportBuilder,
        private ?string $loggerProjectRootDirectory,
        private float $processTimeout,
        private bool $decoratedOutput,
    ) {
    }

    public function create(Logs $logConfig): Reporter
    {
        if (!$logConfig->getUseGitHubAnnotationsLogger()) {
            return new NullReporter();
        }

        return new ComposableReporter(
            new GitHubAnnotationsLogger(
                $this->resultsCollector,
                $this->loggerProjectRootDirectory,
            ),
            writer: StreamWriter::createForStream(
                // Currently, we do not allow configuring the logger anywhere else.
                StreamWriter::STDOUT_STREAM,
                $this->decoratedOutput,
            ),
        );
    }
}
