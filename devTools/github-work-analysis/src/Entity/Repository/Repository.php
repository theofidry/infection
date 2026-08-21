<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\Repository;

final readonly class Repository
{
    public function __construct(
        public int $id,
        public string $owner,
        public string $name,
    ) {}
}
