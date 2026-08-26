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

/**
 * Offline exploration of the data already present in Infection's JSON report.
 *
 * This is intentionally a development tool, not a runtime selection policy.
 */
if ($argc !== 2) {
    \fwrite(\STDERR, "Usage: php devTools/analyse-mutation-results.php <infection-log.json>\n");

    exit(1);
}

if (!\is_readable($argv[1])) {
    \fwrite(\STDERR, \sprintf("Could not read report %s\n", $argv[1]));

    exit(1);
}

$contents = \file_get_contents($argv[1]);

if ($contents === false) {
    \fwrite(\STDERR, \sprintf("Could not read report %s\n", $argv[1]));

    exit(1);
}

try {
    $report = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    \fwrite(\STDERR, \sprintf("Invalid JSON report: %s\n", $exception->getMessage()));

    exit(1);
}

if (!\is_array($report)) {
    \fwrite(\STDERR, "The report root must be an object.\n");

    exit(1);
}

$reportSections = [
    'killed' => 'killed',
    'killedByStaticAnalysis' => 'killed_by_static_analysis',
    'escaped' => 'escaped',
    'timeouted' => 'timed_out',
    'errored' => 'error',
    'syntaxErrors' => 'syntax_error',
    'uncovered' => 'not_covered',
    'ignored' => 'ignored',
];

$mutations = [];

foreach ($reportSections as $section => $outcome) {
    $rows = $report[$section] ?? [];

    if (!\is_array($rows)) {
        \fwrite(\STDERR, \sprintf('Report section "%s" must be an array.%s', $section, \PHP_EOL));

        exit(1);
    }

    foreach ($rows as $row) {
        if (!\is_array($row) || !isset($row['mutator']) || !\is_array($row['mutator'])) {
            \fwrite(\STDERR, \sprintf('Invalid mutation in report section "%s".%s', $section, \PHP_EOL));

            exit(1);
        }

        $mutation = $row['mutator'];
        $mutatorName = $mutation['mutatorName'] ?? null;
        $originalSourceCode = $mutation['originalSourceCode'] ?? null;
        $originalStartLine = $mutation['originalStartLine'] ?? null;

        if (!\is_string($mutatorName) || !\is_string($originalSourceCode) || !\is_int($originalStartLine)) {
            \fwrite(\STDERR, \sprintf('Mutation in report section "%s" has no mutator name, source code, or start line.%s', $section, \PHP_EOL));

            exit(1);
        }

        $mutations[] = [
            'outcome' => $outcome,
            'mutator' => $mutatorName,
            'source_construct' => findFirstToken($originalSourceCode, $originalStartLine),
        ];
    }
}

$detailsCount = \count($mutations);
$reportedTotal = $report['stats']['totalMutantsCount'] ?? null;
$missingDetailsCount = \is_int($reportedTotal) ? $reportedTotal - $detailsCount : null;

$result = [
    'input' => [
        'reported_mutations' => $reportedTotal,
        'mutations_with_details' => $detailsCount,
        'mutations_without_details' => $missingDetailsCount,
        'runtime_available' => false,
    ],
    'by_mutator' => aggregate($mutations, 'mutator'),
    'by_source_construct' => aggregate($mutations, 'source_construct'),
];

echo \json_encode($result, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR), \PHP_EOL;

/**
 * @param list<array{outcome: string, mutator: string, source_construct: string}> $mutations
 *
 * @return array<string, array{total: int, outcomes: array<string, int>}>
 */
function aggregate(array $mutations, string $dimension): array
{
    $result = [];

    foreach ($mutations as $mutation) {
        $value = $mutation[$dimension];
        $outcome = $mutation['outcome'];

        $result[$value] ??= ['total' => 0, 'outcomes' => []];
        ++$result[$value]['total'];
        $result[$value]['outcomes'][$outcome] = ($result[$value]['outcomes'][$outcome] ?? 0) + 1;
    }

    \ksort($result);

    foreach ($result as &$row) {
        \ksort($row['outcomes']);
    }

    return $result;
}

function findFirstToken(string $sourceCode, int $line): string
{
    $lines = \preg_split('/\n|\r\n?/', $sourceCode);
    $sourceLine = $lines[$line - 1] ?? $sourceCode;
    $tokenSource = \str_starts_with(\ltrim($sourceLine), '<?php') ? $sourceLine : '<?php ' . $sourceLine;
    $tokens = \token_get_all($tokenSource);

    foreach ($tokens as $token) {
        if (!\is_array($token)) {
            return $token;
        }

        if (\in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }

        return \token_name($token[0]);
    }

    return 'empty';
}
