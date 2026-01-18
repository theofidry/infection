<?php

declare(strict_types=1);

namespace Infection\Report\Summary;

use Infection\Configuration\Entry\Logs;
use Infection\Report\AggregateReporter;
use Infection\Report\ComposableReporter;
use Infection\Report\Framework\Factory\ReporterFactory;
use Infection\Report\Framework\Writer\FileWriter;
use Infection\Report\NullReporter;
use Infection\Report\Reporter;
use Symfony\Component\Filesystem\Filesystem;
use function count;
use function Pipeline\take;

final readonly class SummaryReporterFactory implements ReporterFactory
{
    public function __construct(
        private Filesystem $filesystem,
        private Summarizer $summarizer,
    ) {
    }

    public function create(Logs $logConfig): Reporter
    {
        $reporters = take($this->createReporters($logConfig))->toList();

        return count($reporters) > 0
            ? new AggregateReporter($reporters)
            : new NullReporter();
    }

    /**
     * @return iterable<Reporter>
     */
    public function createReporters(Logs $logConfig): iterable
    {
        if ($logConfig->getSummaryJsonLogFilePath() !== null) {
            yield new ComposableReporter(
                new JsonSummaryProducer($this->summarizer),
                new FileWriter(
                    $this->filesystem,
                    $logConfig->getSummaryJsonLogFilePath(),
                ),
            );
        }

        if ($logConfig->getSummaryLogFilePath() !== null) {
            yield new ComposableReporter(
                new TextSummaryProducer($this->summarizer),
                new FileWriter(
                    $this->filesystem,
                    $logConfig->getSummaryJsonLogFilePath(),
                ),
            );
        }
    }
}
