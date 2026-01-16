<?php

declare(strict_types=1);

namespace Infection\Report;

use Infection\Report\Framework\DataProducer;
use Infection\Report\Framework\Writer\ReportWriter;

final readonly class ComposableReporter implements Reporter
{
    public function __construct(
        private DataProducer $producer,
        private ReportWriter $writer,
    ) {
    }

    public function report(): void
    {
        $this->writer->write(
            $this->producer->produce(),
        );
    }
}