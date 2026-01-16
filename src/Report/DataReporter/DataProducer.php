<?php

declare(strict_types=1);

namespace Infection\Report\DataReporter;

/**
 * Service for producing mutation testing results report content. It does not
 * care about the encoding or destination.
 */
interface DataProducer
{
    /**
     * @return iterable<string>|string
     */
    public function produce(): iterable|string;
}
