<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\Synchronization;

use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;

final readonly class ResumableSynchronizationRun
{
    public function __construct(
        public int $id,
        public SynchronizationMode $mode,
        public string $requestUrl,
        public ?string $nextPageUrl,
    ) {}
}
