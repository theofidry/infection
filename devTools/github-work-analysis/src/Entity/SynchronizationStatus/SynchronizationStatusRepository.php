<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus;

interface SynchronizationStatusRepository
{
    public function get(): SynchronizationStatus;
}
