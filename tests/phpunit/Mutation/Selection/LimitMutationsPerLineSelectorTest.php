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
use Infection\Mutation\Selection\ExhaustiveMutationSelector;
use Infection\Mutation\Selection\LimitMutationsPerLineSelector;
use Infection\Mutation\Selection\MutationEvaluationPlan;
use Infection\Tests\Mutation\MutationBuilder;
use function iterator_to_array;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LimitMutationsPerLineSelector::class)]
final class LimitMutationsPerLineSelectorTest extends TestCase
{
    public function test_it_limits_mutations_with_the_same_file_and_starting_line(): void
    {
        $plans = [
            $first = self::plan('first', 'src/Foo.php', 10),
            $second = self::plan('second', 'src/Foo.php', 10),
            self::plan('third', 'src/Foo.php', 10),
        ];

        $selected = (new LimitMutationsPerLineSelector(2))->select($plans);

        $this->assertSame([$first, $second], iterator_to_array($selected));
    }

    public function test_it_counts_lines_independently_per_file(): void
    {
        $plans = [
            $fooLine10 = self::plan('foo-10-first', 'src/Foo.php', 10),
            self::plan('foo-10-second', 'src/Foo.php', 10),
            $fooLine11 = self::plan('foo-11', 'src/Foo.php', 11),
            $barLine10 = self::plan('bar-10', 'src/Bar.php', 10),
        ];

        $selected = (new LimitMutationsPerLineSelector(1))->select($plans);

        $this->assertSame(
            [$fooLine10, $fooLine11, $barLine10],
            iterator_to_array($selected),
        );
    }

    public function test_it_preserves_streaming(): void
    {
        $plans = self::planStream();
        $selected = (new LimitMutationsPerLineSelector(1))->select($plans);

        foreach ($selected as $plan) {
            $this->assertSame('first', $plan->identity);
            $this->assertSame($plan, $plans->current());

            return;
        }

        $this->fail('Expected a selected mutation');
    }

    public function test_an_id_rerun_is_limited_after_the_requested_mutation_is_selected(): void
    {
        $mutations = [
            self::plan('first', 'src/Foo.php', 10)->mutation,
            $requested = self::plan('second', 'src/Foo.php', 10)->mutation,
        ];
        $plans = (new ExhaustiveMutationSelector('second'))->select($mutations);

        $selected = (new LimitMutationsPerLineSelector(1))->select($plans);

        $this->assertEquals(
            [MutationEvaluationPlan::forFirstOrderMutation($requested)],
            iterator_to_array($selected),
        );
    }

    private static function plan(string $hash, string $file, int $line): MutationEvaluationPlan
    {
        $mutation = MutationBuilder::withMinimalTestData()
            ->withHash($hash)
            ->withOriginalFilePath($file)
            ->withAttribute('startLine', $line)
            ->build()
        ;

        return MutationEvaluationPlan::forFirstOrderMutation($mutation);
    }

    /**
     * @return Generator<MutationEvaluationPlan>
     */
    private static function planStream(): Generator
    {
        yield self::plan('first', 'src/Foo.php', 10);

        yield self::plan('second', 'src/Foo.php', 10);
    }
}
