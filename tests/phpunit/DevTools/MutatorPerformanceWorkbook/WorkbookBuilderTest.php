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

namespace Infection\Tests\DevTools\MutatorPerformanceWorkbook;

use function array_keys;
use function array_map;
use function array_slice;
use function implode;
use Infection\DevTools\MutatorPerformanceWorkbook\WorkbookBuilder;
use Infection\Mutator\Arithmetic\Plus;
use Infection\Tests\TestingUtility\FS;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function Safe\json_encode;
use Symfony\Component\Filesystem\Filesystem;
use UnexpectedValueException;

#[CoversClass(WorkbookBuilder::class)]
#[Group('integration')]
final class WorkbookBuilderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = FS::tmpDir('mutator-performance-workbook');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function test_it_builds_the_workbook_and_groups_repeated_observations(): void
    {
        $inputPath = $this->temporaryDirectory . '/report.jsonl';
        $outputPath = $this->temporaryDirectory . '/report.xlsx';
        $filesystem = new Filesystem();
        $filesystem->dumpFile(
            $inputPath,
            implode("\n", array_map(json_encode(...), [
                self::record('run-1', 'mutation-1', 'escaped', '=dangerous output'),
                self::record('run-2', 'mutation-1', 'killed by tests', 'output'),
            ])) . "\n",
        );

        (new WorkbookBuilder())->build($inputPath, $outputPath);

        $reader = new Reader();
        $reader->open($outputPath);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];

            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();

        $this->assertSame(['Summary', 'Metrics', 'Observations', 'Mutations', 'Reviews', 'Lists'], array_keys($sheets));
        $this->assertSame(['Runs', 2], $sheets['Summary'][1]);
        $this->assertSame(['Unique mutations', 1], $sheets['Summary'][2]);
        $this->assertSame(['Observations', 2], $sheets['Summary'][3]);
        $this->assertSame(['Syntactic validity rate', 1, 1, 1], array_slice($sheets['Metrics'][1], 0, 4));
        $this->assertSame(['Outcome instability rate', 1, 1, 1], array_slice($sheets['Metrics'][8], 0, 4));
        $this->assertCount(3, $sheets['Observations']);
        $this->assertSame('=dangerous output', $sheets['Observations'][1][13]);
        $this->assertSame(2, $sheets['Mutations'][1][7]);
        $this->assertCount(2, $sheets['Reviews']);
        $this->assertSame('mutation-1', $sheets['Reviews'][1][0]);
    }

    public function test_it_reports_the_invalid_json_line(): void
    {
        $inputPath = $this->temporaryDirectory . '/report.jsonl';
        (new Filesystem())->dumpFile($inputPath, "{}\ninvalid\n");

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Invalid observation on line 1: Expected 'runId' to be a non-empty string");

        (new WorkbookBuilder())->build($inputPath, $this->temporaryDirectory . '/report.xlsx');
    }

    public function test_it_rejects_a_duplicate_run_and_mutation_pair(): void
    {
        $inputPath = $this->temporaryDirectory . '/report.jsonl';
        $record = self::record('run-1', 'mutation-1', 'escaped', 'output');
        (new Filesystem())->dumpFile($inputPath, json_encode($record) . "\n" . json_encode($record) . "\n");

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Duplicate observation for run 'run-1' and mutation 'mutation-1'");

        (new WorkbookBuilder())->build($inputPath, $this->temporaryDirectory . '/report.xlsx');
    }

    public function test_it_rejects_inconsistent_metadata_for_a_mutation(): void
    {
        $inputPath = $this->temporaryDirectory . '/report.jsonl';
        $firstRecord = self::record('run-1', 'mutation-1', 'escaped', 'output');
        $secondRecord = self::record('run-2', 'mutation-1', 'escaped', 'output', diff: 'different diff');
        (new Filesystem())->dumpFile(
            $inputPath,
            json_encode($firstRecord) . "\n" . json_encode($secondRecord) . "\n",
        );

        $this->expectExceptionMessage("Mutation 'mutation-1' has inconsistent metadata between observations");

        (new WorkbookBuilder())->build($inputPath, $this->temporaryDirectory . '/report.xlsx');
    }

    public function test_it_refuses_to_replace_an_existing_workbook(): void
    {
        $outputPath = $this->temporaryDirectory . '/report.xlsx';
        (new Filesystem())->dumpFile($outputPath, 'existing reviews');

        $this->expectExceptionMessage("Refusing to overwrite existing workbook: {$outputPath}");

        (new WorkbookBuilder())->build($this->temporaryDirectory . '/missing.jsonl', $outputPath);
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(
        string $runId,
        string $mutationId,
        string $status,
        string $output,
        string $diff = "- return 1;\n+ return 2;",
    ): array {
        return [
            'runId' => $runId,
            'mutation' => [
                'id' => $mutationId,
                'mutatorName' => 'Plus',
                'mutatorClass' => Plus::class,
                'source' => [
                    'file' => 'src/Example.php',
                    'startLine' => 12,
                    'endLine' => 12,
                ],
                'diff' => $diff,
            ],
            'tests' => [
                [
                    'method' => 'ExampleTest::test_it_adds',
                    'file' => 'tests/ExampleTest.php',
                    'executionTimeSeconds' => 0.012,
                ],
            ],
            'detectionStatus' => $status,
            'decisiveProcess' => [
                'commandLine' => 'vendor/bin/phpunit',
                'output' => $output,
                'runtimeSeconds' => 0.031,
            ],
        ];
    }
}
