<?php

declare(strict_types=1);

namespace Infection\Temporary;

use Infection\Process\Runner\Parallel\ParallelProcessRunner;

/**
 * @internal
 */
final class ProcessExecutor
{
    private $processRunner;
    private $threadCount;

    public function __construct(ParallelProcessRunner $processRunner, int $threadCount)
    {
        $this->processRunner = $processRunner;
        $this->threadCount = $threadCount;
    }

    /**
     * @param MutantProcess[]
     */
    public function execute(array $processes): void
    {
        $this->processRunner->run($processes, $this->threadCount);
    }
}