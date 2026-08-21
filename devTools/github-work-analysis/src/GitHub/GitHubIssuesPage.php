<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\GitHub;

final readonly class GitHubIssuesPage
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public string $sourceStartedAt,
        public ?string $nextPageUrl,
    ) {}
}
