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

namespace Infection\GitHubWorkAnalysis\Tests;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisRunner;
use Infection\GitHubWorkAnalysis\Analysis\ExplicitReference\ExplicitReferenceProcessor;
use Infection\GitHubWorkAnalysis\Analysis\ExplicitReference\ReferenceExtractor;
use Infection\GitHubWorkAnalysis\Analysis\Processor\Pipeline;
use Infection\GitHubWorkAnalysis\Database\Connection\Analysis\ConnectionFactory;
use Infection\GitHubWorkAnalysis\Database\Connection\Analysis\SchemaInitializer;
use Infection\GitHubWorkAnalysis\Database\Connection\SqliteConnectionFactory;
use Infection\GitHubWorkAnalysis\Database\Repository\PdoRepositoryRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function iterator_to_array;
use function Safe\tempnam;
use function Safe\unlink;

#[CoversNothing]
#[Group('integration')]
final class GitHubWorkAnalysisTest extends TestCase
{
    #[Group('integration')]
    #[RequiresPhpExtension('pdo_sqlite')]
    public function test_builds_relationship_graph_without_changing_the_source(): void
    {
        $sourcePath = tempnam(__DIR__, 'github-source-');
        $outputPath = tempnam(__DIR__, 'github-analysis-');
        $filesystem = new Filesystem();
        unlink($outputPath);

        try {
            $source = new PDO('sqlite:' . $sourcePath);
            $source->exec(
                <<<'SQL'
                    CREATE TABLE repositories (id INTEGER PRIMARY KEY, owner TEXT NOT NULL, name TEXT NOT NULL);
                    CREATE TABLE items (
                        id INTEGER PRIMARY KEY,
                        repository_id INTEGER NOT NULL,
                        number INTEGER NOT NULL,
                        type TEXT NOT NULL,
                        title TEXT NOT NULL,
                        body TEXT,
                        html_url TEXT NOT NULL,
                        closed_at TEXT
                    );
                    CREATE INDEX item_type ON items (type);
                    INSERT INTO repositories VALUES (1, 'infection', 'infection');
                    INSERT INTO items VALUES (
                        1, 1, 10, 'issue', 'Failure', 'Implemented by #12',
                        'https://github.com/infection/infection/issues/10', NULL
                    );
                    INSERT INTO items VALUES (
                        2, 1, 11, 'issue', 'Other failure', NULL,
                        'https://github.com/infection/infection/issues/11', NULL
                    );
                    INSERT INTO items VALUES (
                        3, 1, 12, 'pull_request', 'Fix the failure',
                        'Fixes #10. See #11 and external/project#99. Missing: #404.',
                        'https://github.com/infection/infection/pull/12', '2026-01-01T00:00:00Z'
                    );
                    INSERT INTO items VALUES (
                        4, 1, 13, 'issue', 'Unrelated failure', NULL,
                        'https://github.com/infection/infection/issues/13', '2026-01-02T00:00:00Z'
                    );
                    SQL,
            );
            unset($source);

            $pdo = new ConnectionFactory(
                $filesystem,
                new SqliteConnectionFactory($filesystem, 5_000),
                new SchemaInitializer(),
                $sourcePath,
                $outputPath,
            )
                ->create();
            $messages = iterator_to_array(
                new AnalysisRunner(
                    $pdo,
                    new Pipeline([
                        new ExplicitReferenceProcessor(
                            new ReferenceExtractor(),
                            new PdoRepositoryRepository($pdo),
                        ),
                    ]),
                )
                    ->run(),
            );

            $this->assertSame(
                [
                    'Relationships: 3',
                    'Issue/PR associations: 2',
                    'Unresolved references: 2',
                ],
                $messages,
            );

            $source = new PDO('sqlite:' . $sourcePath);
            $this->assertFalse(
                $this->query(
                    $source,
                    <<<'SQL'
                        SELECT name FROM sqlite_master WHERE name = 'work_item_relationships'
                        SQL,
                )
                    ->fetchColumn(),
            );

            $analysis = new PDO('sqlite:' . $outputPath);
            $analysis->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->assertSame(
                [
                    [
                        'issue_number' => 10,
                        'pull_request_number' => 12,
                        'issue_url' => 'https://github.com/infection/infection/issues/10',
                        'pull_request_url' => 'https://github.com/infection/infection/pull/12',
                        'relationship_type' => 'references',
                    ],
                    [
                        'issue_number' => 10,
                        'pull_request_number' => 12,
                        'issue_url' => 'https://github.com/infection/infection/issues/10',
                        'pull_request_url' => 'https://github.com/infection/infection/pull/12',
                        'relationship_type' => 'closes',
                    ],
                    [
                        'issue_number' => 11,
                        'pull_request_number' => 12,
                        'issue_url' => 'https://github.com/infection/infection/issues/11',
                        'pull_request_url' => 'https://github.com/infection/infection/pull/12',
                        'relationship_type' => 'references',
                    ],
                ],
                $this->query(
                    $analysis,
                    <<<'SQL'
                        SELECT issue.number AS issue_number,
                               pull_request.number AS pull_request_number,
                               issue_pull_request_relationships.issue_url,
                               issue_pull_request_relationships.pull_request_url,
                               issue_pull_request_relationships.relationship_type
                        FROM issue_pull_request_relationships
                        JOIN items issue ON issue.id = issue_pull_request_relationships.issue_id
                        JOIN items pull_request ON pull_request.id = issue_pull_request_relationships.pull_request_id
                        ORDER BY issue_pull_request_relationships.relationship_id
                        SQL,
                )
                    ->fetchAll(),
            );
            $this->assertSame(
                [
                    [
                        'source_item_type' => 'pull_request',
                        'source_item_number' => 12,
                        'source_item_title' => 'Fix the failure',
                        'source_item_url' => 'https://github.com/infection/infection/pull/12',
                        'source_field' => 'body',
                        'evidence_text' => 'Fixes #10. See #11 and external/project#99. Missing: #404.',
                        'target_owner' => 'external',
                        'target_repository' => 'project',
                        'target_number' => 99,
                        'target_url' => 'https://github.com/external/project/issues/99',
                        'reason' => 'external_repository',
                    ],
                    [
                        'source_item_type' => 'pull_request',
                        'source_item_number' => 12,
                        'source_item_title' => 'Fix the failure',
                        'source_item_url' => 'https://github.com/infection/infection/pull/12',
                        'source_field' => 'body',
                        'evidence_text' => 'Fixes #10. See #11 and external/project#99. Missing: #404.',
                        'target_owner' => 'infection',
                        'target_repository' => 'infection',
                        'target_number' => 404,
                        'target_url' => 'https://github.com/infection/infection/issues/404',
                        'reason' => 'missing_item',
                    ],
                ],
                $this->query(
                    $analysis,
                    <<<'SQL'
                        SELECT source_item_type,
                               source_item_number,
                               source_item_title,
                               source_item_url,
                               source_field,
                               evidence_text,
                               target_owner,
                               target_repository,
                               target_number,
                               target_url,
                               reason
                        FROM unresolved_work_item_reference_details
                        ORDER BY reference_id
                        SQL,
                )
                    ->fetchAll(),
            );
            $this->assertSame(
                1,
                $this->query(
                    $analysis,
                    <<<'SQL'
                        SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'item_type'
                        SQL,
                )
                    ->fetchColumn(),
            );
            $this->assertSame(
                2,
                $this->query(
                    $analysis,
                    <<<'SQL'
                        SELECT COUNT(*) FROM issue_pull_request_associations
                        SQL,
                )
                    ->fetchColumn(),
            );
            $this->assertSame(
                [
                    [
                        'repository_owner' => 'infection',
                        'repository_name' => 'infection',
                        'item_type' => 'issue',
                        'item_number' => 13,
                        'item_title' => 'Unrelated failure',
                        'item_url' => 'https://github.com/infection/infection/issues/13',
                        'closed_at' => '2026-01-02T00:00:00Z',
                    ],
                ],
                $this->query(
                    $analysis,
                    <<<'SQL'
                        SELECT repository_owner,
                               repository_name,
                               item_type,
                               item_number,
                               item_title,
                               item_url,
                               closed_at
                        FROM work_items_without_relationships
                        ORDER BY item_id
                        SQL,
                )
                    ->fetchAll(),
            );
        } finally {
            new Filesystem()
                ->remove([$sourcePath, $outputPath]);
        }
    }

    private function query(PDO $pdo, string $query): PDOStatement
    {
        $statement = $pdo->query($query);

        if (!$statement instanceof PDOStatement) {
            $this->fail('The SQLite query failed.');
        }

        return $statement;
    }
}
