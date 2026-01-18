<?php

declare(strict_types=1);

namespace Infection\Tests\Fixtures\Logger;

use Infection\Report\Framework\DataProducer;

final readonly class DummyDataProducer implements DataProducer
{
    /**
     * @param string[] $lines
     */
    public function __construct(private array $lines)
    {
    }

    public function produce(): array
    {
        return $this->lines;
    }
}
