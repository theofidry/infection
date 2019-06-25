<?php

declare(strict_types=1);

namespace Infection\Temporary\Runner;

/**
 * @internal
 */
interface Runner
{
    public function run(): Result;
}