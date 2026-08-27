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

namespace Infection\Metrics;

use Infection\Mutant\Evaluation\EvaluationReason;
use Infection\Mutant\Evaluation\MutationEvaluationResult;
use Infection\Mutant\Evaluation\MutationOutcome;

/** @internal */
final readonly class MsiEligibilityPolicy
{
    public function __construct(private bool $timeoutsAsEscaped)
    {
    }

    public function classify(MutationEvaluationResult $result): MsiEligibility
    {
        if ($result->outcome === MutationOutcome::IGNORED || $result->outcome === MutationOutcome::SKIPPED) {
            return MsiEligibility::EXCLUDED;
        }

        if ($result->outcome === MutationOutcome::NOT_COVERED) {
            return MsiEligibility::OVERALL_DENOMINATOR_ONLY;
        }

        $inNumerator = $result->outcome === MutationOutcome::COVERED
            || $result->resolutionReason === EvaluationReason::PROCESS_ERROR
            || $result->resolutionReason === EvaluationReason::SYNTAX_ERROR;

        if ($result->resolutionReason === EvaluationReason::TIMEOUT) {
            $inNumerator = !$this->timeoutsAsEscaped;
        }

        if ($inNumerator) {
            return MsiEligibility::NUMERATOR_AND_BOTH_DENOMINATORS;
        }

        return MsiEligibility::BOTH_DENOMINATORS;
    }
}
