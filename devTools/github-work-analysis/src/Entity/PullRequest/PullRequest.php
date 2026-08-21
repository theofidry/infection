<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\PullRequest;

final readonly class PullRequest
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $body,
        public array $labels,
    ) {}
}
