<?php

declare(strict_types=1);

namespace Infection\Report\Framework\Writer;

use Symfony\Component\Filesystem\Filesystem;
use function is_string;
use function Pipeline\take;

final readonly class FileWriter implements ReportWriter
{
    public function __construct(
        private Filesystem $filesystem,
        private string $filePath,
    ) {
        // TODO: validation of the path? Separate constructor?
    }

    public function write(iterable|string $contentOrLines): void
    {
        $contents = is_string($contentOrLines)
            ? $contentOrLines
            : take($contentOrLines)->toList();

        $this->filesystem->dumpFile($this->filePath, $contents);
    }
}