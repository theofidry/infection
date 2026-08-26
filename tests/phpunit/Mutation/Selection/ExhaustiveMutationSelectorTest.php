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

use Generator;
use Infection\Mutation\Mutation;
use Infection\Mutation\Selection\ExhaustiveMutationSelector;
use Infection\Mutation\Selection\MutationEvaluationPlan;
use Infection\Tests\Mutation\MutationBuilder;
use function iterator_to_array;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExhaustiveMutationSelector::class)]
final class ExhaustiveMutationSelectorTest extends TestCase
{
    public function test_it_does_not_consume_the_next_candidate_before_it_is_requested(): void
    {
        $candidates = self::candidateStream();
        $plans = (new ExhaustiveMutationSelector(null))->select($candidates);

        foreach ($plans as $plan) {
            $this->assertSame('first', $plan->identity);
            $this->assertSame('first', $candidates->current()->getHash());

            return;
        }

        $this->fail('Expected a selected mutation');
    }

    public function test_it_selects_every_candidate_in_order(): void
    {
        $plans = (new ExhaustiveMutationSelector(null))->select([
            $first = self::mutation('first'),
            $second = self::mutation('second'),
        ]);

        $this->assertEquals(
            [
                MutationEvaluationPlan::forFirstOrderMutation($first),
                MutationEvaluationPlan::forFirstOrderMutation($second),
            ],
            iterator_to_array($plans),
        );
    }

    public function test_it_selects_nothing_from_empty_input(): void
    {
        $plans = (new ExhaustiveMutationSelector(null))->select([]);

        $this->assertSame([], iterator_to_array($plans));
    }

    public function test_it_selects_only_the_requested_mutation(): void
    {
        $plans = (new ExhaustiveMutationSelector('second'))->select([
            self::mutation('first'),
            $selected = self::mutation('second'),
            self::mutation('third'),
        ]);

        $this->assertEquals(
            [MutationEvaluationPlan::forFirstOrderMutation($selected)],
            iterator_to_array($plans),
        );
    }

    private static function mutation(string $hash): Mutation
    {
        return MutationBuilder::withMinimalTestData()->withHash($hash)->build();
    }

    /**
     * @return Generator<Mutation>
     */
    private static function candidateStream(): Generator
    {
        yield self::mutation('first');

        yield self::mutation('second');
    }
}
