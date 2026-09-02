<?php

declare(strict_types=1);

namespace Infection\E2ETests\PHPUnitTestSuiteBootstrap\Tests\Unit;

use Infection\E2ETests\PHPUnitTestSuiteBootstrap\Calculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Calculator::class)]
final class CalculatorTest extends TestCase
{
    public function test_it_returns_an_integer(): void
    {
        $this->assertTrue(PHPUNIT_UNIT_BOOTSTRAP_LOADED);
        $this->assertIsInt((new Calculator())->addOne(1));
    }
}
