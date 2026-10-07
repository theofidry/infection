<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\ExplicitReference;

final readonly class Reference
{
    public function __construct(
        public ?string $owner,
        public ?string $repository,
        public int $number,
        public RelationshipType $type,
        public string $evidence,
    ) {}
}
