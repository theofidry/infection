<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Tests\Mutation\Selection;

use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\Mutation\Selection\MutationWeight;
use Infection\PhpParser\Visitor\MarkNodesAsAridVisitor;
use Infection\Tests\Mutation\MutationBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MutationWeight::class)]
final class MutationWeightTest extends TestCase
{
    #[DataProvider('mutationProvider')]
    public function test_it_keeps_value_noise_and_cost_as_independent_signals(
        bool $arid,
        int $expectedValue,
    ): void {
        $mutation = MutationBuilder::withMinimalTestData()
            ->withAttribute(MarkNodesAsAridVisitor::ARID, $arid ? 1 : 0)
            ->withTests([
                new TestLocation('FooTest::test', '/tests/FooTest.php', 0.25),
            ])
            ->build()
        ;

        $weight = MutationWeight::forMutation($mutation);

        $this->assertSame($expectedValue, $weight->expectedValue);
        $this->assertNull($weight->noiseRisk);
        $this->assertSame(0.25, $weight->estimatedExecutionCost);
    }

    public static function mutationProvider(): iterable
    {
        yield 'productive' => [false, 1];

        yield 'arid' => [true, 0];
    }
}
