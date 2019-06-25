<?php

declare(strict_types=1);

namespace Infection\Temporary\MutantGenerator;

use Infection\Temporary\Mutant;

/**
 * @internal
 */
interface MutantGenerator
{
    /**
     * @return Mutant[]
     */
    public function generate(): array;
}