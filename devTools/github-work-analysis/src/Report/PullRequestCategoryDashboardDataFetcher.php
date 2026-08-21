<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Report;

use PDO;
use PDOStatement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

/**
 * Fetches pull request dashboard data from the analysis views.
 */
final readonly class PullRequestCategoryDashboardDataFetcher
{
    public function __construct(
        #[Autowire(service: 'github_work_analysis.reporting_connection')]
        private PDO $pdo,
    ) {}

    public function fetch(): PullRequestCategoryDashboardData
    {
        return new PullRequestCategoryDashboardData(
            $this->categoryCounts(
                <<<'SQL'
                    SELECT
                        strftime('%Y', closed_at) || '-Q' || ((CAST(strftime('%m', closed_at) AS INTEGER) - 1) / 3 + 1) AS quarter,
                        category,
                        COUNT(DISTINCT pull_request_id) AS pull_request_count
                    FROM pull_request_category_details
                    WHERE closed_at IS NOT NULL
                    GROUP BY quarter, category
                    ORDER BY quarter, category
                    SQL,
                'Could not read detailed pull request category data.',
            ),
            $this->pullRequestCounts(),
            $this->categorizedPullRequestCounts(),
            $this->categoryCounts(
                <<<'SQL'
                    SELECT
                        strftime('%Y', closed_at) || '-Q' || ((CAST(strftime('%m', closed_at) AS INTEGER) - 1) / 3 + 1) AS quarter,
                        CASE
                            WHEN category IN ('feature', 'bugfix', 'performance') THEN category
                            ELSE 'other'
                        END AS category,
                        COUNT(DISTINCT pull_request_id) AS pull_request_count
                    FROM pull_request_category_details
                    WHERE closed_at IS NOT NULL
                    GROUP BY quarter, CASE
                        WHEN category IN ('feature', 'bugfix', 'performance') THEN category
                        ELSE 'other'
                    END
                    ORDER BY quarter, category
                    SQL,
                'Could not read aggregated pull request category data.',
            ),
            $this->latestDate(),
        );
    }

    /**
     * @return list<QuarterlyPullRequestCategoryCount>
     */
    private function categoryCounts(string $query, string $error): array
    {
        $statement = $this->pdo->query($query);
        Assert::isInstanceOf($statement, PDOStatement::class, $error);

        $counts = [];

        while ($row = $statement->fetch()) {
            Assert::isMap($row, $error);
            Assert::keyExists($row, 'quarter', $error);
            Assert::stringNotEmpty($row['quarter'], $error);
            Assert::keyExists($row, 'category', $error);
            Assert::stringNotEmpty($row['category'], $error);
            Assert::keyExists($row, 'pull_request_count', $error);
            Assert::integer($row['pull_request_count'], $error);

            $counts[] = new QuarterlyPullRequestCategoryCount(
                $row['quarter'],
                $row['category'],
                $row['pull_request_count'],
            );
        }

        return $counts;
    }

    /**
     * @return list<QuarterlyPullRequestCount>
     */
    private function pullRequestCounts(): array
    {
        return $this->quarterlyPullRequestCounts(
            <<<'SQL'
                SELECT
                    strftime('%Y', closed_at) || '-Q' || ((CAST(strftime('%m', closed_at) AS INTEGER) - 1) / 3 + 1) AS quarter,
                    COUNT(DISTINCT pull_request_id) AS pull_request_count
                FROM (
                    SELECT pull_request_id, closed_at
                    FROM pull_request_category_details
                    WHERE closed_at IS NOT NULL
                    UNION
                    SELECT pull_request_id, closed_at
                    FROM uncategorized_pull_requests
                    WHERE closed_at IS NOT NULL
                )
                GROUP BY quarter
                ORDER BY quarter
                SQL,
            'Could not read pull request count data.',
        );
    }

    /**
     * @return list<QuarterlyPullRequestCount>
     */
    private function categorizedPullRequestCounts(): array
    {
        return $this->quarterlyPullRequestCounts(
            <<<'SQL'
                SELECT
                    strftime('%Y', closed_at) || '-Q' || ((CAST(strftime('%m', closed_at) AS INTEGER) - 1) / 3 + 1) AS quarter,
                    COUNT(DISTINCT pull_request_id) AS pull_request_count
                FROM pull_request_category_details
                WHERE closed_at IS NOT NULL
                GROUP BY quarter
                ORDER BY quarter
                SQL,
            'Could not read categorized pull request count data.',
        );
    }

    /**
     * @return list<QuarterlyPullRequestCount>
     */
    private function quarterlyPullRequestCounts(string $query, string $error): array
    {
        $statement = $this->pdo->query($query);
        Assert::isInstanceOf($statement, PDOStatement::class, $error);

        $counts = [];

        while ($row = $statement->fetch()) {
            Assert::isMap($row, $error);
            Assert::keyExists($row, 'quarter', $error);
            Assert::stringNotEmpty($row['quarter'], $error);
            Assert::keyExists($row, 'pull_request_count', $error);
            Assert::integer($row['pull_request_count'], $error);

            $counts[] = new QuarterlyPullRequestCount(
                $row['quarter'],
                $row['pull_request_count'],
            );
        }

        return $counts;
    }

    /**
     * @return non-empty-string
     */
    private function latestDate(): string
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT MAX(closed_at)
                FROM (
                    SELECT closed_at
                    FROM pull_request_category_details
                    WHERE closed_at IS NOT NULL
                    UNION ALL
                    SELECT closed_at
                    FROM uncategorized_pull_requests
                    WHERE closed_at IS NOT NULL
                )
                SQL,
        );

        Assert::isInstanceOf($statement, PDOStatement::class, 'Could not read the latest pull request date.');

        $latestDate = $statement->fetchColumn();
        Assert::stringNotEmpty($latestDate, 'The analysis database does not contain completed pull requests.');

        return $latestDate;
    }
}
