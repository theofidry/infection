<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory;

final readonly class CategoryEvidence
{
    public function __construct(
        public Category $category,
        public EvidenceSource $source,
        public string $text,
    ) {}
}
