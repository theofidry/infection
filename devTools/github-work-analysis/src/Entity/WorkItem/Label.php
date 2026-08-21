<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\WorkItem;

final readonly class Label
{
    public function __construct(
        public int $githubId,
        public string $name,
        public string $color,
        public ?string $description,
    ) {}
}
