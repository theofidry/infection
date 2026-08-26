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

namespace Infection\Mutation\Selection;

use Infection\Mutation\Mutation;
use function usort;

/**
 * @internal
 */
final readonly class WeightedMutationSelector
{
    public function __construct(private ?string $mutationId)
    {
    }

    /**
     * @param iterable<Mutation> $candidates
     *
     * @return iterable<MutationEvaluationPlan>
     */
    public function select(iterable $candidates): iterable
    {
        if ($this->mutationId !== null) {
            yield from $this->selectById($candidates);

            return;
        }

        $filePath = null;
        $plans = [];

        foreach ($candidates as $candidate) {
            if ($filePath !== null && $candidate->getOriginalFilePath() !== $filePath) {
                yield from self::sort($plans);

                $plans = [];
            }

            $filePath = $candidate->getOriginalFilePath();
            $plans[] = MutationEvaluationPlan::forFirstOrderMutation($candidate);
        }

        yield from self::sort($plans);
    }

    /**
     * @param iterable<Mutation> $candidates
     *
     * @return iterable<MutationEvaluationPlan>
     */
    private function selectById(iterable $candidates): iterable
    {
        foreach ($candidates as $candidate) {
            if ($candidate->getHash() !== $this->mutationId) {
                continue;
            }

            yield MutationEvaluationPlan::forFirstOrderMutation($candidate);

            return;
        }
    }

    /**
     * @param list<MutationEvaluationPlan> $plans
     *
     * @return list<MutationEvaluationPlan>
     */
    private static function sort(array $plans): array
    {
        $positions = [];

        foreach ($plans as $position => $plan) {
            $positions[$plan->identity] = $position;
        }

        usort(
            $plans,
            static fn (MutationEvaluationPlan $left, MutationEvaluationPlan $right): int => self::compare(
                $left,
                $right,
                $positions,
            ),
        );

        return $plans;
    }

    /**
     * @param array<string, int> $positions
     */
    private static function compare(
        MutationEvaluationPlan $left,
        MutationEvaluationPlan $right,
        array $positions,
    ): int {
        $valueComparison = $right->weight->expectedValue <=> $left->weight->expectedValue;

        if ($valueComparison !== 0) {
            return $valueComparison;
        }

        $costComparison = $left->weight->estimatedExecutionCost <=> $right->weight->estimatedExecutionCost;

        if ($costComparison !== 0) {
            return $costComparison;
        }

        return $positions[$left->identity] <=> $positions[$right->identity];
    }
}
