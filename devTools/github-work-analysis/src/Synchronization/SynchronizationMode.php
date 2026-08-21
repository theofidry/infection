<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Synchronization;

enum SynchronizationMode: string
{
    case FULL = 'full';
    case INCREMENTAL = 'incremental';
}
