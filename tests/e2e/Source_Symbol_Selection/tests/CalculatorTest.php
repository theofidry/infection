<?php

declare(strict_types=1);

namespace SourceSymbolSelection\Test;

use PHPUnit\Framework\TestCase;
use SourceSymbolSelection\Calculator;

final class CalculatorTest extends TestCase
{
    public function test_arithmetic(): void
    {
        $calculator = new Calculator();

        $this->assertSame(
            3,
            $calculator->add(
                2,
                1,
            ),
            'The selected method must add its operands.',
        );
        $this->assertSame(
            6,
            $calculator->addThree(
                1,
                2,
                3,
            ),
            'The unselected method must also be covered and detect both addition mutations.',
        );
    }
}
