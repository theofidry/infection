<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory;

enum EvidenceSource: string
{
    case Label = 'label';
    case Title = 'title';
    case Body = 'body';
}
