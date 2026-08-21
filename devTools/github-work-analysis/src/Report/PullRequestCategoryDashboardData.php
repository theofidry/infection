<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Report;

/**
 * @internal
 */
final readonly class PullRequestCategoryDashboardData
{
    /**
     * @param list<QuarterlyPullRequestCategoryCount> $detailedCounts
     * @param list<QuarterlyPullRequestCount> $pullRequestCounts
     * @param list<QuarterlyPullRequestCount> $categorizedPullRequestCounts
     * @param list<QuarterlyPullRequestCategoryCount> $aggregatedCounts
     * @param non-empty-string $latestDate
     */
    public function __construct(
        public array $detailedCounts,
        public array $pullRequestCounts,
        public array $categorizedPullRequestCounts,
        public array $aggregatedCounts,
        public string $latestDate,
    ) {}
}
