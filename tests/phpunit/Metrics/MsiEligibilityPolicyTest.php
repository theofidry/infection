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

namespace Infection\Tests\Metrics;

use Infection\Metrics\MsiEligibility;
use Infection\Metrics\MsiEligibilityPolicy;
use Infection\Mutant\Evaluation\EvaluationAttempt;
use Infection\Mutant\Evaluation\EvaluationOutcome;
use Infection\Mutant\Evaluation\EvaluationReason;
use Infection\Mutant\Evaluation\MutationEvaluationResultReducer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MsiEligibilityPolicy::class)]
final class MsiEligibilityPolicyTest extends TestCase
{
    #[DataProvider('classificationProvider')]
    public function test_it_classifies_msi_eligibility(
        EvaluationOutcome $outcome,
        EvaluationReason $reason,
        bool $timeoutsAsEscaped,
        MsiEligibility $expected,
    ): void {
        $attempt = new EvaluationAttempt('evaluator', $outcome, $reason, '', '', 0.0);
        $result = MutationEvaluationResultReducer::reduce([$attempt]);

        $this->assertEquals($expected, (new MsiEligibilityPolicy($timeoutsAsEscaped))->classify($result));
    }

    public static function classificationProvider(): iterable
    {
        yield 'detected' => [
            EvaluationOutcome::DETECTED,
            EvaluationReason::TEST_FAILURE,
            false,
            MsiEligibility::NUMERATOR_AND_BOTH_DENOMINATORS,
        ];

        yield 'not covered' => [
            EvaluationOutcome::NOT_EVALUATED,
            EvaluationReason::NO_COVERING_TESTS,
            false,
            MsiEligibility::OVERALL_DENOMINATOR_ONLY,
        ];

        yield 'ignored' => [
            EvaluationOutcome::NOT_EVALUATED,
            EvaluationReason::IGNORED,
            false,
            MsiEligibility::EXCLUDED,
        ];

        yield 'timeout contributes by default' => [
            EvaluationOutcome::INCONCLUSIVE,
            EvaluationReason::TIMEOUT,
            false,
            MsiEligibility::NUMERATOR_AND_BOTH_DENOMINATORS,
        ];

        yield 'timeout can be treated as escaped' => [
            EvaluationOutcome::INCONCLUSIVE,
            EvaluationReason::TIMEOUT,
            true,
            MsiEligibility::BOTH_DENOMINATORS,
        ];
    }
}
