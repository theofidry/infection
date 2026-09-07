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

namespace Infection\Tests\Report\MutatorPerformance;

use function in_array;
use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\Metrics\ResultsCollector;
use Infection\Mutant\DetectionStatus;
use Infection\Mutator\Loop\For_;
use Infection\Report\MutatorPerformance\MutatorPerformanceJsonLinesDataProducer;
use Infection\Tests\Mutant\MutantExecutionResultBuilder;
use function iterator_to_array;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Safe\json_decode;

#[CoversClass(MutatorPerformanceJsonLinesDataProducer::class)]
final class MutatorPerformanceJsonLinesDataProducerTest extends TestCase
{
    public function test_it_produces_one_json_object_per_mutation(): void
    {
        $collector = new ResultsCollector();
        $collector->collect(
            MutantExecutionResultBuilder::withCompleteTestData()->build(),
            MutantExecutionResultBuilder::withMinimalTestData()
                ->withDetectionStatus(DetectionStatus::NOT_COVERED)
                ->withMutantHash('not-covered')
                ->withTests([
                    new TestLocation('Test::test_it', null, null),
                ])
                ->build(),
        );

        $lines = iterator_to_array(
            (new MutatorPerformanceJsonLinesDataProducer($collector, 'run-1'))->produce(),
            false,
        );

        $this->assertCount(2, $lines);
        $notCoveredRecord = json_decode($lines[1], true);

        $this->assertIsArray($notCoveredRecord);
        $this->assertSame(
            [
                'runId' => 'run-1',
                'mutation' => [
                    'id' => 'abc123def456789',
                    'mutatorName' => 'For_',
                    'mutatorClass' => For_::class,
                    'source' => [
                        'file' => '/path/to/src/Foo.php',
                        'startLine' => 10,
                        'endLine' => 15,
                    ],
                    'diff' => <<<'DIFF'
                        --- Original
                        +++ Mutated
                        @@ @@
                        -        for ($i = 0; $i < 10; $i++) {
                        -            echo $i;
                        -        }
                        +        // Mutated: removed for loop
                        DIFF,
                ],
                'tests' => [
                    [
                        'method' => 'FooTest::test_it_can_do_something',
                        'file' => '/path/to/tests/FooTest.php',
                        'executionTimeSeconds' => 0.123,
                    ],
                    [
                        'method' => 'FooTest::test_it_can_do_something_else',
                        'file' => '/path/to/tests/FooTest.php',
                        'executionTimeSeconds' => 0.456,
                    ],
                ],
                'detectionStatus' => 'killed by tests',
                'decisiveProcess' => [
                    'commandLine' => 'vendor/bin/phpunit --configuration phpunit.xml --filter FooTest',
                    'output' => <<<'OUTPUT'
                        PHPUnit 11.0.0 by Sebastian Bergmann

                        Time: 00:00.123, Memory: 16.00 MB

                        FAILURES!
                        Tests: 2, Assertions: 5, Failures: 1.
                        OUTPUT,
                    'runtimeSeconds' => 0.789,
                ],
            ],
            json_decode($lines[0], true),
        );
        $this->assertSame(
            [
                [
                    'method' => 'Test::test_it',
                    'file' => null,
                    'executionTimeSeconds' => null,
                ],
            ],
            $notCoveredRecord['tests'],
        );
        $this->assertSame('run-1', $notCoveredRecord['runId']);
    }

    #[DataProvider('detectionStatusProvider')]
    public function test_it_reports_whether_a_decisive_process_was_executed(
        DetectionStatus $status,
        bool $processWasExecuted,
    ): void {
        $collector = new ResultsCollector();
        $collector->collect(
            MutantExecutionResultBuilder::withMinimalTestData()
                ->withDetectionStatus($status)
                ->build(),
        );

        $lines = iterator_to_array(
            (new MutatorPerformanceJsonLinesDataProducer($collector, 'run-1'))->produce(),
            false,
        );
        $record = json_decode($lines[0], true);

        $this->assertIsArray($record);
        $this->assertSame($status->value, $record['detectionStatus']);
        $this->assertSame($processWasExecuted, $record['decisiveProcess'] !== null);
    }

    public static function detectionStatusProvider(): iterable
    {
        foreach (DetectionStatus::cases() as $status) {
            yield $status->value => [
                $status,
                !in_array($status, [
                    DetectionStatus::IGNORED,
                    DetectionStatus::NOT_COVERED,
                    DetectionStatus::SKIPPED,
                ], true),
            ];
        }
    }
}
