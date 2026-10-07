<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Analysis\PullRequestCategory;

use Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory\PullRequestCategorizer;
use Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory\PullRequestCategoryProcessor;
use Infection\GitHubWorkAnalysis\Database\Connection\Analysis\SchemaInitializer;
use Infection\GitHubWorkAnalysis\Database\Repository\PdoPullRequestRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

#[CoversClass(PullRequestCategoryProcessor::class)]
#[Group('integration')]
#[RequiresPhpExtension('pdo_sqlite')]
final class PullRequestCategoryProcessorTest extends TestCase
{
    public function test_persists_pull_request_categories_and_their_evidence(): void
    {
        $pdo = new PDO(
            'sqlite::memory:',
            options: [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
        $pdo->exec(
            <<<'SQL'
                CREATE TABLE repositories (
                    id INTEGER PRIMARY KEY,
                    owner TEXT NOT NULL,
                    name TEXT NOT NULL
                );
                CREATE TABLE items (
                    id INTEGER PRIMARY KEY,
                    repository_id INTEGER NOT NULL,
                    number INTEGER NOT NULL,
                    type TEXT NOT NULL,
                    title TEXT NOT NULL,
                    body TEXT,
                    state TEXT NOT NULL,
                    html_url TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    closed_at TEXT,
                    merged_at TEXT,
                    draft INTEGER NOT NULL
                );
                CREATE TABLE labels (
                    id INTEGER PRIMARY KEY,
                    repository_id INTEGER NOT NULL,
                    github_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    color TEXT NOT NULL,
                    description TEXT
                );
                CREATE TABLE item_labels (
                    item_id INTEGER NOT NULL,
                    label_id INTEGER NOT NULL,
                    PRIMARY KEY (item_id, label_id)
                );

                INSERT INTO repositories VALUES (1, 'infection', 'infection');
                INSERT INTO items VALUES (
                    1, 1, 10, 'pull_request', 'refactor: simplify polling',
                    'tests: cover the timing boundary', 'open', 'https://example.com/pull/10',
                    '2026-01-01T00:00:00Z', NULL, NULL, 0
                );
                INSERT INTO items VALUES (
                    2, 1, 11, 'pull_request', 'Change process polling',
                    NULL, 'open', 'https://example.com/pull/11',
                    '2026-01-02T00:00:00Z', NULL, NULL, 0
                );
                INSERT INTO items VALUES (
                    3, 1, 12, 'issue', 'fix: reported failure',
                    NULL, 'open', 'https://example.com/issues/12',
                    '2026-01-03T00:00:00Z', NULL, NULL, 0
                );
                INSERT INTO items VALUES (
                    4, 1, 13, 'pull_request', 'fix: rejected change',
                    NULL, 'closed', 'https://example.com/pull/13',
                    '2026-01-04T00:00:00Z', '2026-01-05T00:00:00Z', NULL, 0
                );
                INSERT INTO items VALUES (
                    5, 1, 14, 'pull_request', 'feat: unfinished experiment',
                    NULL, 'open', 'https://example.com/pull/14',
                    '2026-01-06T00:00:00Z', NULL, NULL, 1
                );
                INSERT INTO items VALUES (
                    6, 1, 15, 'pull_request', 'Unfinished experiment',
                    NULL, 'open', 'https://example.com/pull/15',
                    '2026-01-07T00:00:00Z', NULL, NULL, 1
                );
                INSERT INTO labels VALUES (1, 1, 100, 'Performance', '000000', NULL);
                INSERT INTO labels VALUES (2, 1, 101, 'Bugfix', '000000', NULL);
                INSERT INTO item_labels VALUES (1, 1);
                INSERT INTO item_labels VALUES (3, 2);
                SQL,
        );
        $schemaInitializer = new SchemaInitializer();
        $schemaInitializer->initialize($pdo);

        $categorizer = new PullRequestCategorizer();
        $pullRequestRepository = new PdoPullRequestRepository(
            $pdo,
        );
        $processor = new PullRequestCategoryProcessor(
            $categorizer,
            $pullRequestRepository,
        );
        $messages = iterator_to_array(
            $processor->process($pdo),
        );

        $statement = $pdo->query(
            <<<'SQL'
                SELECT pull_request_categories.category,
                       pull_request_category_evidence.source,
                       pull_request_category_evidence.evidence_text
                FROM pull_request_categories
                JOIN pull_request_category_evidence
                  ON pull_request_category_evidence.pull_request_category_id = pull_request_categories.id
                ORDER BY pull_request_categories.id
                SQL,
        );
        $this->assertInstanceOf(PDOStatement::class, $statement);
        $this->assertSame(
            [
                'Pull requests categorized as feature: 1',
                'Pull requests categorized as bugfix: 1',
                'Pull requests categorized as performance: 1',
                'Pull requests categorized as refactoring: 1',
                'Pull requests categorized as maintenance: 0',
                'Pull requests categorized as dependency: 0',
                'Pull requests categorized as documentation: 0',
                'Pull requests categorized as testing: 1',
                'Pull requests categorized as build_ci: 0',
                'Pull requests categorized as internal_tooling: 0',
                'Uncategorized pull requests: 1',
            ],
            $messages,
        );
        $this->assertSame(
            [
                [
                    'category' => 'performance',
                    'source' => 'label',
                    'evidence_text' => 'Performance',
                ],
                [
                    'category' => 'refactoring',
                    'source' => 'title',
                    'evidence_text' => 'refactor: simplify polling',
                ],
                [
                    'category' => 'testing',
                    'source' => 'body',
                    'evidence_text' => 'tests: cover the timing boundary',
                ],
                [
                    'category' => 'bugfix',
                    'source' => 'title',
                    'evidence_text' => 'fix: rejected change',
                ],
                [
                    'category' => 'feature',
                    'source' => 'title',
                    'evidence_text' => 'feat: unfinished experiment',
                ],
            ],
            $statement->fetchAll(),
        );

        $visiblePullRequests = $pdo->query(
            <<<'SQL'
                SELECT DISTINCT pull_request_url
                FROM pull_request_category_details
                ORDER BY pull_request_url
                SQL,
        );
        $this->assertInstanceOf(PDOStatement::class, $visiblePullRequests);
        $this->assertSame(
            [
                ['pull_request_url' => 'https://example.com/pull/10'],
            ],
            $visiblePullRequests->fetchAll(),
        );
    }
}
