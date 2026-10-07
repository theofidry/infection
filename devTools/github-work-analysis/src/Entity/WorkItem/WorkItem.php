<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\WorkItem;

final readonly class WorkItem
{
    /**
     * @param list<Label> $labels
     */
    public function __construct(
        public int $githubId,
        public int $number,
        public string $type,
        public string $title,
        public ?string $body,
        public string $state,
        public ?string $authorLogin,
        public string $htmlUrl,
        public string $createdAt,
        public string $updatedAt,
        public ?string $closedAt,
        public ?string $mergedAt,
        public bool $draft,
        public array $labels,
    ) {}
}
