<?php

declare(strict_types=1);

namespace Infection\Temporary\MutantGenerator;

use Infection\Temporary\PhpParser\Parser;
use Infection\TestFramework\Coverage\CodeCoverageData;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @internal
 */
final class CoverageBasedMutantGenerator implements MutantGenerator
{
    private $parser;
    private $sourceFiles;
    private $coverageData;
    private $onlyCovered;

    /**
     * @param SplFileInfo[] $sourceFiles
     */
    public function __construct(
        Parser $parser,
        array $sourceFiles,
        CodeCoverageData $coverageData,
        bool $onlyCovered
    )
    {
        $this->parser = $parser;
        $this->sourceFiles = $sourceFiles;
        $this->coverageData = $coverageData;
        $this->onlyCovered = $onlyCovered;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(): array
    {
        $allFilesMutations = [[]];

        foreach ($this->sourceFiles as $file) {
            if (!$this->onlyCovered || $this->hasTests($file)) {
                $allFilesMutations[] = $this->parser->parse($file);
            }

            // TODO
            //$this->eventDispatcher->dispatch(new MutableFileProcessed());
        }

        return array_merge(...$allFilesMutations);
    }

    private function hasTests(SplFileInfo $file): bool
    {
        $filePath = $file->getRealPath();

        return $this->coverageData->hasTests($filePath);
    }
}