<?php

declare(strict_types=1);

namespace Infection\Temporary\MutantGenerator;

use PhpParser\Node;

/**
 * @internal
 */
interface NodeMutantGenerator
{
    /**
     * @return Node[]
     */
    public function mutate(Node $node): array;
}