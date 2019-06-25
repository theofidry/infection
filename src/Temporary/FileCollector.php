<?php

declare(strict_types=1);

namespace Infection\Temporary;

use Infection\Finder\SourceFilesFinder;
use function iterator_to_array;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @internal
 */
final class FileCollector
{
    /**
     * @return SplFileInfo[]
     */
    public function collectFiles(
        array $sourceDirectories,
        array $excludeDirectories,
        string $filter
    ): array
    {
        return iterator_to_array(
            (new SourceFilesFinder($sourceDirectories, $excludeDirectories))->getSourceFiles($filter),
            false
        );
    }
}