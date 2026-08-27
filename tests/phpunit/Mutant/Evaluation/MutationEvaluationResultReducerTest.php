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

namespace Infection\Tests\Mutant\Evaluation;

use Infection\Mutant\Evaluation\EvaluationAttempt;
use Infection\Mutant\Evaluation\EvaluationOutcome;
use Infection\Mutant\Evaluation\EvaluationReason;
use Infection\Mutant\Evaluation\MutationEvaluationResultReducer;
use Infection\Mutant\Evaluation\MutationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MutationEvaluationResultReducer::class)]
final class MutationEvaluationResultReducerTest extends TestCase
{
    public function test_it_retains_ordered_attempts_and_the_first_detection_wins(): void
    {
        $phpUnitAttempt = new EvaluationAttempt(
            'phpunit',
            EvaluationOutcome::UNDETECTED,
            EvaluationReason::PASSED,
            'phpunit',
            'OK',
            0.1,
        );
        $phpStanAttempt = new EvaluationAttempt(
            'phpstan',
            EvaluationOutcome::DETECTED,
            EvaluationReason::STATIC_ANALYSIS_FAILURE,
            'phpstan analyse',
            'Error',
            0.2,
        );

        $result = MutationEvaluationResultReducer::reduce([$phpUnitAttempt, $phpStanAttempt]);

        $this->assertSame([$phpUnitAttempt, $phpStanAttempt], $result->attempts);
        $this->assertSame(MutationOutcome::COVERED, $result->outcome);
        $this->assertSame(EvaluationReason::STATIC_ANALYSIS_FAILURE, $result->resolutionReason);
    }

    public function test_it_classifies_an_inconclusive_attempt_as_suspicious(): void
    {
        $attempt = new EvaluationAttempt(
            'phpunit',
            EvaluationOutcome::INCONCLUSIVE,
            EvaluationReason::TIMEOUT,
            'phpunit',
            '',
            10.0,
        );

        $result = MutationEvaluationResultReducer::reduce([$attempt]);

        $this->assertSame(MutationOutcome::SUSPICIOUS, $result->outcome);
        $this->assertSame(EvaluationReason::TIMEOUT, $result->resolutionReason);
    }
}
