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

use function array_map;
use Generator;
use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\Mutation\Mutation;
use Infection\Mutation\Selection\MutationEvaluationPlan;
use Infection\Mutation\Selection\WeightedMutationSelector;
use Infection\PhpParser\Visitor\MarkNodesAsAridVisitor;
use Infection\Tests\Mutation\MutationBuilder;
use function iterator_to_array;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeightedMutationSelector::class)]
final class WeightedMutationSelectorTest extends TestCase
{
    public function test_it_orders_each_file_by_value_then_cost_and_preserves_ties(): void
    {
        $plans = (new WeightedMutationSelector(null))->select([
            self::mutation('arid', 'src/A.php', true, 0.1),
            self::mutation('slow', 'src/A.php', false, 0.3),
            self::mutation('fast-first', 'src/A.php', false, 0.1),
            self::mutation('fast-second', 'src/A.php', false, 0.1),
            self::mutation('next-file', 'src/B.php', false, 0.2),
        ]);

        $this->assertSame(
            ['fast-first', 'fast-second', 'slow', 'arid', 'next-file'],
            array_map(
                static fn (MutationEvaluationPlan $plan): string => $plan->identity,
                iterator_to_array($plans, false),
            ),
        );
    }

    public function test_it_buffers_only_the_current_file(): void
    {
        $candidates = self::candidateStream();
        $plans = (new WeightedMutationSelector(null))->select($candidates);

        foreach ($plans as $plan) {
            $this->assertSame('first', $plan->identity);
            $this->assertSame('second-file', $candidates->current()->getHash());

            return;
        }

        $this->fail('Expected a selected mutation');
    }

    public function test_it_selects_only_the_requested_mutation_without_calculating_other_weights(): void
    {
        $plans = (new WeightedMutationSelector('second'))->select([
            self::mutation('first', 'src/A.php', false, 0.1),
            self::mutation('second', 'src/A.php', true, 0.2),
            self::mutation('third', 'src/A.php', false, 0.3),
        ]);

        $plans = iterator_to_array($plans, false);

        $this->assertCount(1, $plans);
        $this->assertSame('second', $plans[0]->identity);
    }

    private static function mutation(
        string $hash,
        string $filePath,
        bool $arid,
        float $runtime,
    ): Mutation {
        return MutationBuilder::withMinimalTestData()
            ->withHash($hash)
            ->withOriginalFilePath($filePath)
            ->withAttribute(MarkNodesAsAridVisitor::ARID, $arid ? 1 : 0)
            ->withTests([new TestLocation('FooTest::test', '/tests/FooTest.php', $runtime)])
            ->build()
        ;
    }

    /**
     * @return Generator<Mutation>
     */
    private static function candidateStream(): Generator
    {
        yield self::mutation('first', 'src/A.php', false, 0.1);

        yield self::mutation('second-file', 'src/B.php', false, 0.1);

        yield self::mutation('not-consumed', 'src/C.php', false, 0.1);
    }
}
