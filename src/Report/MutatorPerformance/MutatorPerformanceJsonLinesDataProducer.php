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

namespace Infection\Report\MutatorPerformance;

use function array_map;
use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\Framework\Str;
use Infection\Metrics\ResultsCollector;
use Infection\Mutant\DetectionStatus;
use Infection\Mutant\MutantExecutionResult;
use Infection\Report\Framework\DataProducer;
use function Safe\json_encode;

/**
 * @internal
 *
 * TODO: Extend the report when the corresponding observations become available:
 * - subject revision, environment, configuration, and end-to-end measurements;
 * - the complete test-framework and static-analysis process chain, including the process kind, tool, exit details,
 *   timeout, early termination, and failure signature;
 * - matched original-code runtimes and peak resident memory for each mutation;
 * - mutation-generation time, throughput, and peak memory, plus combined run-level memory;
 * - independent syntactic-validity checks and repeated-run stability data;
 * - source columns and byte-exact source, diff, and process output where required.
 *
 * Actionability classifications and reviewer information remain external human-authored data.
 */
final readonly class MutatorPerformanceJsonLinesDataProducer implements DataProducer
{
    public function __construct(
        private ResultsCollector $resultsCollector,
        private string $runId,
    ) {
    }

    /**
     * @return iterable<string>
     */
    public function produce(): iterable
    {
        foreach ($this->resultsCollector->getAllExecutionResults() as $executionResult) {
            yield json_encode($this->normalize($executionResult));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(MutantExecutionResult $executionResult): array
    {
        return [
            'runId' => $this->runId,
            'mutation' => [
                'id' => $executionResult->getMutantHash(),
                'mutatorName' => $executionResult->getMutatorName(),
                'mutatorClass' => $executionResult->getMutatorClass(),
                'source' => [
                    'file' => $executionResult->getOriginalFilePath(),
                    'startLine' => $executionResult->getOriginalStartingLine(),
                    'endLine' => $executionResult->getOriginalEndingLine(),
                ],
                'diff' => self::normalizeText($executionResult->getMutantDiff()),
            ],
            'tests' => array_map(
                self::normalizeTest(...),
                $executionResult->getTests(),
            ),
            'detectionStatus' => $executionResult->getDetectionStatus()->value,
            'decisiveProcess' => self::normalizeDecisiveProcess($executionResult),
        ];
    }

    /**
     * @return array{method: string, file: ?string, executionTimeSeconds: ?float}
     */
    private static function normalizeTest(TestLocation $test): array
    {
        return [
            'method' => self::normalizeText($test->getMethod()),
            'file' => $test->getFilePath(),
            'executionTimeSeconds' => $test->getExecutionTime(),
        ];
    }

    /**
     * @return array{commandLine: string, output: string, runtimeSeconds: float}|null
     */
    private static function normalizeDecisiveProcess(MutantExecutionResult $executionResult): ?array
    {
        $status = $executionResult->getDetectionStatus();

        if ($status === DetectionStatus::IGNORED) {
            return null;
        }

        if ($status === DetectionStatus::NOT_COVERED) {
            return null;
        }

        if ($status === DetectionStatus::SKIPPED) {
            return null;
        }

        return [
            'commandLine' => self::normalizeText($executionResult->getProcessCommandLine()),
            'output' => self::normalizeText($executionResult->getProcessOutput()),
            'runtimeSeconds' => $executionResult->getProcessRuntime(),
        ];
    }

    private static function normalizeText(string $value): string
    {
        return Str::convertToUtf8(Str::cleanForDisplay($value));
    }
}
