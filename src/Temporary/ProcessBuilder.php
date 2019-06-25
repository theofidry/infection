<?php

declare(strict_types=1);

namespace Infection\Temporary;

use Infection\Process\Builder\ProcessBuilder as OriginalProcessBuilder;

/**
 * @internal
 */
final class ProcessBuilder
{
    private $processBuilder;
    private $testFrameworkExtraOptions;

    public function __construct(OriginalProcessBuilder $processBuilder, $testFrameworkExtraOptions)
    {
        $this->processBuilder = $processBuilder;
        $this->testFrameworkExtraOptions = $testFrameworkExtraOptions;
    }

    public function create(Mutant $mutant): MutantProcess
    {
        return $this->processBuilder->getProcessForMutant($mutant, $this->testFrameworkExtraOptions);
    }
}