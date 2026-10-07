<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Report;

use Infection\GitHubWorkAnalysis\Report\PullRequestCategoryDashboardData;
use Infection\GitHubWorkAnalysis\Report\PullRequestCategoryDashboardDataFetcher;
use Infection\GitHubWorkAnalysis\Report\PullRequestCategoryDashboardGenerator;
use Infection\GitHubWorkAnalysis\Report\PullRequestCategoryDashboardRenderer;
use Infection\GitHubWorkAnalysis\Report\QuarterlyPullRequestCategoryCount;
use Infection\GitHubWorkAnalysis\Report\QuarterlyPullRequestCount;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function Safe\tempnam;

#[CoversClass(PullRequestCategoryDashboardGenerator::class)]
#[CoversClass(PullRequestCategoryDashboardDataFetcher::class)]
#[CoversClass(PullRequestCategoryDashboardRenderer::class)]
#[CoversClass(PullRequestCategoryDashboardData::class)]
#[CoversClass(QuarterlyPullRequestCategoryCount::class)]
#[CoversClass(QuarterlyPullRequestCount::class)]
final class PullRequestCategoryDashboardGeneratorTest extends TestCase
{
    public function test_generates_the_dashboard_from_analysis_views(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            <<<'SQL'
                CREATE TABLE pull_request_category_details (
                    pull_request_id INTEGER NOT NULL,
                    closed_at TEXT,
                    category TEXT NOT NULL
                );
                CREATE TABLE uncategorized_pull_requests (
                    pull_request_id INTEGER NOT NULL,
                    closed_at TEXT
                );
                INSERT INTO pull_request_category_details VALUES
                    (1, '2025-01-10T00:00:00Z', 'feature'),
                    (2, '2025-01-20T00:00:00Z', 'testing'),
                    (2, '2025-01-20T00:00:00Z', 'refactoring');
                INSERT INTO uncategorized_pull_requests VALUES
                    (3, '2025-04-10T00:00:00Z');
                SQL,
        );
        $filesystem = new Filesystem();
        $templatePath = tempnam(__DIR__, 'dashboard-template-');
        $reportPath = tempnam(__DIR__, 'dashboard-report-');
        $filesystem->dumpFile(
            $templatePath,
            '__DETAILED_DATA__ __PR_COUNT_DATA__ __CATEGORIZED_PR_COUNT_DATA__ __AGGREGATED_DATA__ __LATEST_DATE__',
        );

        try {
            $generator = new PullRequestCategoryDashboardGenerator(
                new PullRequestCategoryDashboardDataFetcher($pdo),
                new PullRequestCategoryDashboardRenderer($filesystem, $templatePath),
                $filesystem,
                $reportPath,
            );

            $this->assertSame($reportPath, $generator->generate());
            $this->assertSame(
                '[{"quarter":"2025-Q1","category":"feature","pullRequestCount":1},{"quarter":"2025-Q1","category":"refactoring","pullRequestCount":1},{"quarter":"2025-Q1","category":"testing","pullRequestCount":1}] '
                . '[{"quarter":"2025-Q1","pullRequestCount":2},{"quarter":"2025-Q2","pullRequestCount":1}] '
                . '[{"quarter":"2025-Q1","pullRequestCount":2}] '
                . '[{"quarter":"2025-Q1","category":"feature","pullRequestCount":1},{"quarter":"2025-Q1","category":"other","pullRequestCount":1}] '
                . '"2025-04-10T00:00:00Z"',
                $filesystem->readFile($reportPath),
            );
        } finally {
            $filesystem->remove([$templatePath, $reportPath]);
        }
    }
}
