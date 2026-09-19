<?php

declare(strict_types=1);

namespace Infection\DevTools\MutatorPerformanceWorkbook;

use function array_keys;
use function array_map;
use function count;
use function file_exists;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function mb_strlen;
use function mb_substr;
use Infection\Mutant\DetectionStatus;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;
use function Safe\json_decode;

final class WorkbookBuilder
{
    private const int CELL_CHARACTER_LIMIT = 32_767;

    private const string TRUNCATION_SUFFIX = "\n[truncated; see JSONL report]";

    private const int FIRST_COLUMN_INDEX = 0;

    private const int HEADER_ROW = 1;

    private const int FIRST_DATA_ROW = 2;

    private const int METRIC_ROW_COUNT = 16;

    private const array SUMMARY_COLUMN_WIDTHS = [
        32 => [1],
        18 => [2],
    ];

    private const array METRICS_COLUMN_WIDTHS = [
        36 => [1],
        22 => [2, 3, 4],
        72 => [5],
    ];

    private const array OBSERVATION_COLUMN_WIDTHS = [
        18 => [1, 2, 3],
        36 => [4, 5, 10, 12],
        60 => [13, 14],
    ];

    private const array MUTATION_COLUMN_WIDTHS = [
        18 => [1, 2, 8],
        36 => [3, 4],
        60 => [7],
    ];

    private const array REVIEW_COLUMN_WIDTHS = [
        18 => [1, 2, 3],
        50 => [4, 5, 6],
    ];

    private const array LIST_COLUMN_WIDTHS = [
        42 => [1],
    ];

    private const array CLASSIFICATIONS = [
        'actionable — tests',
        'actionable — subject',
        'non-actionable — equivalent',
        'non-actionable — redundant',
        'non-actionable — irrelevant or arid',
        'cannot determine',
    ];

    private const array OBSERVATION_HEADERS = [
        'Run ID',
        'Mutation ID',
        'Mutator',
        'Mutator Class',
        'Source File',
        'Start Line',
        'End Line',
        'Detection Status',
        'Selected Test Count',
        'Selected Tests',
        'Process Runtime Seconds',
        'Command Line',
        'Diff',
        'Process Output',
    ];

    private readonly Style $headerStyle;

    public function __construct()
    {
        $this->headerStyle = (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::DARK_BLUE)
        ;
    }

    public function build(string $inputPath, string $outputPath): void
    {
        if (file_exists($outputPath)) {
            throw new RuntimeException("Refusing to overwrite existing workbook: {$outputPath}");
        }

        [$mutations, $runIds, $statusCounts, $observationCount] = $this->index($inputPath);
        $metrics = $this->calculateMetrics($inputPath);

        $writer = new Writer();
        $writer->openToFile($outputPath);

        try {
            $this->writeSummary($writer, count($runIds), count($mutations), $observationCount, $statusCounts);
            $this->writeMetrics($writer, count($mutations), $metrics);
            $this->writeObservations($writer, $inputPath, $observationCount);
            $this->writeMutations($writer, $mutations);
            $this->writeReviews($writer, $mutations);
            $this->writeLists($writer);
        } finally {
            $writer->close();
        }
    }

    /**
     * @return array{array<string, array<string, mixed>>, array<string, true>, array<string, positive-int>, positive-int}
     */
    private function index(string $inputPath): array
    {
        $mutations = [];
        $runIds = [];
        $statusCounts = [];
        $identities = [];
        $observationCount = 0;

        foreach ($this->records($inputPath) as $record) {
            $runId = self::stringAt($record, 'runId');
            $mutation = self::arrayAt($record, 'mutation');
            $mutationId = self::stringAt($mutation, 'id');
            $identity = $runId . "\0" . $mutationId;

            if (isset($identities[$identity])) {
                throw new UnexpectedValueException("Duplicate observation for run '{$runId}' and mutation '{$mutationId}'");
            }

            $identities[$identity] = true;
            $runIds[$runId] = true;
            $status = self::stringAt($record, 'detectionStatus');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            if (!isset($mutations[$mutationId])) {
                $mutations[$mutationId] = $mutation + ['observationCount' => 0];
            } else {
                $indexedMutation = $mutations[$mutationId];
                unset($indexedMutation['observationCount']);

                if ($indexedMutation !== $mutation) {
                    throw new UnexpectedValueException("Mutation '{$mutationId}' has inconsistent metadata between observations");
                }
            }

            ++$mutations[$mutationId]['observationCount'];
            ++$observationCount;
        }

        if ($observationCount === 0) {
            throw new UnexpectedValueException('The JSONL report contains no observations');
        }

        ksort($mutations);
        ksort($statusCounts);

        return [$mutations, $runIds, $statusCounts, $observationCount];
    }

    /**
     * @return array{
     *     evaluatedMutationCount: int,
     *     syntacticallyInvalidMutationCount: int,
     *     selectedTestWorkload: int,
     *     processRuntimeSeconds: float,
     *     repeatedlyEvaluatedMutationCount: int,
     *     unstableMutationCount: int
     * }
     */
    private function calculateMetrics(string $inputPath): array
    {
        $evaluatedMutations = [];
        $syntacticallyInvalidMutations = [];
        $evaluatedObservationCounts = [];
        $statusesByMutation = [];
        $selectedTestWorkload = 0;
        $processRuntimeSeconds = 0.;

        foreach ($this->records($inputPath) as $record) {
            $mutationId = self::stringAt(self::arrayAt($record, 'mutation'), 'id');
            $status = self::stringAt($record, 'detectionStatus');

            if (!self::wasEvaluated($status)) {
                continue;
            }

            $evaluatedMutations[$mutationId] = true;
            $evaluatedObservationCounts[$mutationId] = ($evaluatedObservationCounts[$mutationId] ?? 0) + 1;
            $statusesByMutation[$mutationId][$status] = true;
            $selectedTestWorkload += count(self::arrayAt($record, 'tests'));

            if ($status === DetectionStatus::SYNTAX_ERROR->value) {
                $syntacticallyInvalidMutations[$mutationId] = true;
            }

            $process = $record['decisiveProcess'];

            if (is_array($process)) {
                $processRuntimeSeconds += self::numberAt($process, 'runtimeSeconds');
            }
        }

        $repeatedlyEvaluatedMutationCount = 0;
        $unstableMutationCount = 0;

        foreach ($evaluatedObservationCounts as $mutationId => $count) {
            if ($count < self::FIRST_DATA_ROW) {
                continue;
            }

            ++$repeatedlyEvaluatedMutationCount;

            if (count($statusesByMutation[$mutationId]) > 1) {
                ++$unstableMutationCount;
            }
        }

        return [
            'evaluatedMutationCount' => count($evaluatedMutations),
            'syntacticallyInvalidMutationCount' => count($syntacticallyInvalidMutations),
            'selectedTestWorkload' => $selectedTestWorkload,
            'processRuntimeSeconds' => $processRuntimeSeconds,
            'repeatedlyEvaluatedMutationCount' => $repeatedlyEvaluatedMutationCount,
            'unstableMutationCount' => $unstableMutationCount,
        ];
    }

    private static function wasEvaluated(string $status): bool
    {
        if ($status === DetectionStatus::IGNORED->value) {
            return false;
        }

        if ($status === DetectionStatus::NOT_COVERED->value) {
            return false;
        }

        if ($status === DetectionStatus::SKIPPED->value) {
            return false;
        }

        return true;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function records(string $inputPath): iterable
    {
        $file = new SplFileObject($inputPath, 'r');
        $lineNumber = 0;

        while (!$file->eof()) {
            $line = $file->fgets();
            ++$lineNumber;

            if ($line === false || trim($line) === '') {
                continue;
            }

            try {
                $record = json_decode($line, true);
            } catch (\JsonException $exception) {
                throw new UnexpectedValueException(
                    "Invalid JSON on line {$lineNumber}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            if (!is_array($record)) {
                throw new UnexpectedValueException("Expected a JSON object on line {$lineNumber}");
            }

            try {
                self::validate($record);
            } catch (UnexpectedValueException $exception) {
                throw new UnexpectedValueException(
                    "Invalid observation on line {$lineNumber}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            yield $record;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function validate(array $record): void
    {
        self::stringAt($record, 'runId');
        $mutation = self::arrayAt($record, 'mutation');
        self::stringAt($mutation, 'id');
        self::stringAt($mutation, 'mutatorName');
        self::stringAt($mutation, 'mutatorClass');
        self::stringAt($mutation, 'diff');
        $source = self::arrayAt($mutation, 'source');
        self::stringAt($source, 'file');
        self::integerAt($source, 'startLine');
        self::integerAt($source, 'endLine');
        self::stringAt($record, 'detectionStatus');
        $tests = self::arrayAt($record, 'tests');

        foreach ($tests as $test) {
            self::testDescription($test);
        }

        if (($record['decisiveProcess'] ?? null) !== null) {
            $process = self::arrayAt($record, 'decisiveProcess');
            self::stringAt($process, 'commandLine');
            self::stringAt($process, 'output');
            self::numberAt($process, 'runtimeSeconds');
        }
    }

    /**
     * @param array<string, mixed> $statusCounts
     */
    private function writeSummary(
        Writer $writer,
        int $runCount,
        int $mutationCount,
        int $observationCount,
        array $statusCounts,
    ): void {
        $sheet = $writer->getCurrentSheet()->setName('Summary');
        self::setColumnWidths($sheet, self::SUMMARY_COLUMN_WIDTHS);
        $writer->addRow($this->row(['Metric', 'Value'], $this->headerStyle));
        $writer->addRow($this->row(['Runs', $runCount]));
        $writer->addRow($this->row(['Unique mutations', $mutationCount]));
        $writer->addRow($this->row(['Observations', $observationCount]));
        $writer->addRow($this->row([]));
        $writer->addRow($this->row(['Detection status', 'Observations'], $this->headerStyle));

        foreach ($statusCounts as $status => $count) {
            $writer->addRow($this->row([$status, $count]));
        }
    }

    /**
     * @param array{
     *     evaluatedMutationCount: int,
     *     syntacticallyInvalidMutationCount: int,
     *     selectedTestWorkload: int,
     *     processRuntimeSeconds: float,
     *     repeatedlyEvaluatedMutationCount: int,
     *     unstableMutationCount: int
     * } $metrics
     */
    private function writeMetrics(Writer $writer, int $mutationCount, array $metrics): void
    {
        $sheet = $this->newTableSheet(
            $writer,
            'Metrics',
            ['Metric', 'Value', 'Numerator', 'Denominator', 'Interpretation'],
            self::METRIC_ROW_COUNT,
        );
        self::setColumnWidths($sheet, self::METRICS_COLUMN_WIDTHS);

        $evaluated = $metrics['evaluatedMutationCount'];
        $invalid = $metrics['syntacticallyInvalidMutationCount'];
        $valid = $evaluated - $invalid;
        $repeated = $metrics['repeatedlyEvaluatedMutationCount'];
        $unstable = $metrics['unstableMutationCount'];

        $writer->addRow($this->row([
            'Syntactic validity rate',
            self::rate($valid, $evaluated),
            $valid,
            $evaluated,
            'Operational rate based on evaluated unique mutations; any observed syntax-error status marks the mutation invalid.',
        ]));
        $writer->addRow($this->formulaMetricRow(
            'Actionability rate',
            '=IFERROR((COUNTIF(Reviews!C:C,"actionable — tests")+COUNTIF(Reviews!C:C,"actionable — subject"))/(COUNTIF(Reviews!C:C,"actionable — tests")+COUNTIF(Reviews!C:C,"actionable — subject")+COUNTIF(Reviews!C:C,"non-actionable — equivalent")+COUNTIF(Reviews!C:C,"non-actionable — redundant")+COUNTIF(Reviews!C:C,"non-actionable — irrelevant or arid")),"")',
            'Calculated by Excel from classified Reviews rows; cannot-determine and blank reviews are excluded.',
        ));
        $writer->addRow($this->formulaMetricRow(
            'Unresolved review proportion',
            '=IFERROR(COUNTIF(Reviews!C:C,"cannot determine")/(COUNTIF(Reviews!C:C,"actionable — tests")+COUNTIF(Reviews!C:C,"actionable — subject")+COUNTIF(Reviews!C:C,"non-actionable — equivalent")+COUNTIF(Reviews!C:C,"non-actionable — redundant")+COUNTIF(Reviews!C:C,"non-actionable — irrelevant or arid")+COUNTIF(Reviews!C:C,"cannot determine")),"")',
            'Calculated by Excel from reviewed mutations.',
        ));
        $writer->addRow($this->row(['Generated mutations', $mutationCount, null, null, 'Unique mutation IDs.']));
        $writer->addRow($this->row(['Evaluated mutations', $evaluated, null, null, 'Unique mutations with at least one process-starting status.']));
        $writer->addRow($this->row([
            'Selected-test workload',
            $metrics['selectedTestWorkload'],
            null,
            null,
            'Sum of selected-test counts over all evaluated observations, including repetitions.',
        ]));
        $writer->addRow($this->row([
            'Recorded mutant-process runtime (seconds)',
            $metrics['processRuntimeSeconds'],
            null,
            null,
            'Sum of available decisive-process runtimes; this is not end-to-end time or baseline-adjusted overhead.',
        ]));
        $writer->addRow($this->row([
            'Outcome instability rate',
            self::rate($unstable, $repeated),
            $unstable,
            $repeated,
            'Unique mutations whose native status changes across at least two evaluated observations.',
        ]));
        $writer->addRow($this->unavailableMetricRow('Reviewer agreement', 'Independent reviewer assignments are not recorded.'));
        $writer->addRow($this->unavailableMetricRow('Actionability confidence interval', 'The sampling design is not recorded.'));
        $writer->addRow($this->unavailableMetricRow('Mutation-generation cost', 'Generation time and memory are not recorded.'));
        $writer->addRow($this->unavailableMetricRow('Evaluation-time overhead and amplification', 'Matched original-code runtimes are not recorded.'));
        $writer->addRow($this->unavailableMetricRow('Peak-memory overhead and amplification', 'Mutant and matched-baseline peak memory are not recorded.'));
        $writer->addRow($this->unavailableMetricRow('End-to-end latency and total resource cost', 'Run-level time, memory, and worker metadata are not recorded.'));
        $writer->addRow($this->unavailableMetricRow('Runtime instability', 'Complete process-chain runtimes and repeated baselines are not recorded.'));
        $writer->addRow($this->unavailableMetricRow('Per-project results', 'Subject and revision metadata are not recorded.'));
    }

    private function formulaMetricRow(string $metric, string $formula, string $interpretation): Row
    {
        return new Row([
            new StringCell($metric, null),
            new FormulaCell($formula, null, null),
            Cell::fromValue(null),
            Cell::fromValue(null),
            new StringCell($interpretation, null),
        ]);
    }

    private function unavailableMetricRow(string $metric, string $reason): Row
    {
        return $this->row([$metric, 'Unavailable', null, null, $reason]);
    }

    private static function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * @param positive-int $observationCount
     */
    private function writeObservations(Writer $writer, string $inputPath, int $observationCount): void
    {
        $sheet = $this->newTableSheet($writer, 'Observations', self::OBSERVATION_HEADERS, $observationCount);
        self::setColumnWidths($sheet, self::OBSERVATION_COLUMN_WIDTHS);

        foreach ($this->records($inputPath) as $record) {
            $writer->addRow($this->row($this->observationRow($record)));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $mutations
     */
    private function writeMutations(Writer $writer, array $mutations): void
    {
        $headers = ['Mutation ID', 'Mutator', 'Mutator Class', 'Source File', 'Start Line', 'End Line', 'Diff', 'Observations'];
        $sheet = $this->newTableSheet($writer, 'Mutations', $headers, count($mutations));
        self::setColumnWidths($sheet, self::MUTATION_COLUMN_WIDTHS);

        foreach ($mutations as $mutation) {
            $source = self::arrayAt($mutation, 'source');
            $writer->addRow($this->row([
                self::stringAt($mutation, 'id'),
                self::stringAt($mutation, 'mutatorName'),
                self::stringAt($mutation, 'mutatorClass'),
                self::stringAt($source, 'file'),
                self::integerAt($source, 'startLine'),
                self::integerAt($source, 'endLine'),
                self::stringAt($mutation, 'diff'),
                self::integerAt($mutation, 'observationCount'),
            ]));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $mutations
     */
    private function writeReviews(Writer $writer, array $mutations): void
    {
        $headers = ['Mutation ID', 'Reviewer', 'Classification', 'Rationale', 'Proposed Improvement', 'Uncertainty'];
        $sheet = $this->newTableSheet($writer, 'Reviews', $headers, count($mutations));
        self::setColumnWidths($sheet, self::REVIEW_COLUMN_WIDTHS);

        foreach (array_keys($mutations) as $mutationId) {
            $writer->addRow($this->row([$mutationId, '', '', '', '', '']));
        }
    }

    private function writeLists(Writer $writer): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Lists')->setIsVisible(false);
        self::setColumnWidths($sheet, self::LIST_COLUMN_WIDTHS);
        $writer->addRow($this->row(['Classifications'], $this->headerStyle));

        foreach (self::CLASSIFICATIONS as $classification) {
            $writer->addRow($this->row([$classification]));
        }
    }

    /**
     * @param non-empty-list<string> $headers
     * @param 0|positive-int $dataRowCount
     */
    private function newTableSheet(Writer $writer, string $name, array $headers, int $dataRowCount): Sheet
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent()->setName($name);
        $sheet->setSheetView((new SheetView())->setFreezeRow(self::FIRST_DATA_ROW));
        $sheet->setAutoFilter(new AutoFilter(
            self::FIRST_COLUMN_INDEX,
            self::HEADER_ROW,
            count($headers) - self::HEADER_ROW,
            $dataRowCount + self::HEADER_ROW,
        ));
        $writer->addRow($this->row($headers, $this->headerStyle));

        return $sheet;
    }

    /**
     * @param array<int, list<positive-int>> $columnWidths
     */
    private static function setColumnWidths(Sheet $sheet, array $columnWidths): void
    {
        foreach ($columnWidths as $width => $columns) {
            $sheet->setColumnWidth($width, ...$columns);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return list<float|int|string|null>
     */
    private function observationRow(array $record): array
    {
        $mutation = self::arrayAt($record, 'mutation');
        $source = self::arrayAt($mutation, 'source');
        $tests = self::arrayAt($record, 'tests');
        $process = $record['decisiveProcess'] === null ? null : self::arrayAt($record, 'decisiveProcess');

        return [
            self::stringAt($record, 'runId'),
            self::stringAt($mutation, 'id'),
            self::stringAt($mutation, 'mutatorName'),
            self::stringAt($mutation, 'mutatorClass'),
            self::stringAt($source, 'file'),
            self::integerAt($source, 'startLine'),
            self::integerAt($source, 'endLine'),
            self::stringAt($record, 'detectionStatus'),
            count($tests),
            implode("\n", array_map(self::testDescription(...), $tests)),
            $process === null ? null : self::numberAt($process, 'runtimeSeconds'),
            $process === null ? null : self::stringAt($process, 'commandLine'),
            self::stringAt($mutation, 'diff'),
            $process === null ? null : self::stringAt($process, 'output'),
        ];
    }

    private static function testDescription(mixed $test): string
    {
        if (!is_array($test)) {
            throw new UnexpectedValueException('Expected every test to be an object');
        }

        $description = self::stringAt($test, 'method');
        $file = $test['file'] ?? null;
        $executionTime = $test['executionTimeSeconds'] ?? null;

        if ($file !== null && !is_string($file)) {
            throw new UnexpectedValueException("Expected test 'file' to be a string or null");
        }

        if ($executionTime !== null && !is_int($executionTime) && !is_float($executionTime)) {
            throw new UnexpectedValueException("Expected test 'executionTimeSeconds' to be a number or null");
        }

        if (is_string($file)) {
            $description .= " [{$file}]";
        }

        if (is_int($executionTime) || is_float($executionTime)) {
            $description .= " ({$executionTime}s)";
        }

        return $description;
    }

    /**
     * @param list<float|int|string|null> $values
     */
    private function row(array $values, ?Style $style = null): Row
    {
        return new Row(
            array_map(
                static fn (float|int|string|null $value): Cell => is_string($value)
                    ? new StringCell(self::fitCell($value), null)
                    : Cell::fromValue($value),
                $values,
            ),
            $style,
        );
    }

    private static function fitCell(string $value): string
    {
        if (mb_strlen($value) <= self::CELL_CHARACTER_LIMIT) {
            return $value;
        }

        return mb_substr(
            $value,
            0,
            self::CELL_CHARACTER_LIMIT - mb_strlen(self::TRUNCATION_SUFFIX),
        ) . self::TRUNCATION_SUFFIX;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function arrayAt(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (!is_array($value)) {
            throw new UnexpectedValueException("Expected '{$key}' to be an object or array");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function stringAt(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException("Expected '{$key}' to be a non-empty string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function integerAt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (!is_int($value)) {
            throw new UnexpectedValueException("Expected '{$key}' to be an integer");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function numberAt(array $values, string $key): float|int
    {
        $value = $values[$key] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw new UnexpectedValueException("Expected '{$key}' to be a number");
        }

        return $value;
    }
}
