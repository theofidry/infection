<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Report;

/**
 * @internal
 */
final readonly class QuarterlyPullRequestCategoryCount
{
    /**
     * @param non-empty-string $quarter
     * @param non-empty-string $category
     */
    public function __construct(
        public string $quarter,
        public string $category,
        public int $pullRequestCount,
    ) {}
}
