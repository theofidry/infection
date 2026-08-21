<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Report;

/**
 * @internal
 */
final readonly class QuarterlyPullRequestCount
{
    /**
     * @param non-empty-string $quarter
     */
    public function __construct(
        public string $quarter,
        public int $pullRequestCount,
    ) {}
}
