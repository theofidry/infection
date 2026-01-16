<?php

declare(strict_types=1);

namespace Infection\Report\Writer;

/**
 * Represents the hesitation to which the report is written/sent to. It can be
 * a file, stream, API etc.
 *
 * @internal
 */
interface ReportWriter
{
    /**
     * @param iterable<string>|string $contentOrLines
     */
    public function write(iterable|string $contentOrLines): void;
}