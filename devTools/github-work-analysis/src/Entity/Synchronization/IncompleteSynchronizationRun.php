<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\Synchronization;

use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;

final readonly class IncompleteSynchronizationRun
{
    public function __construct(
        public string $owner,
        public string $name,
        public SynchronizationMode $mode,
        public string $status,
        public int $pagesImported,
        public int $itemsSeen,
        public ?string $nextPageUrl,
    ) {}
}
