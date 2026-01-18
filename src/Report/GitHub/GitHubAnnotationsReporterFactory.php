<?php

declare(strict_types=1);

namespace Infection\Report\GitHub;

use Infection\Configuration\Entry\Logs;
use Infection\Metrics\ResultsCollector;
use Infection\Report\ComposableReporter;
use Infection\Report\Framework\Factory\ReporterFactory;
use Infection\Report\Framework\Writer\StreamWriter;
use Infection\Report\NullReporter;
use Infection\Report\Reporter;

final readonly class GitHubAnnotationsReporterFactory implements ReporterFactory
{
    public function __construct(
        private ResultsCollector $resultsCollector,
        private ?string $loggerProjectRootDirectory,
        private bool $decoratedOutput,
    ) {
    }

    public function create(Logs $logConfig): Reporter
    {
        if (!$logConfig->getUseGitHubAnnotationsLogger()) {
            return new NullReporter();
        }

        return new ComposableReporter(
            new GitHubAnnotationsProducer(
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
