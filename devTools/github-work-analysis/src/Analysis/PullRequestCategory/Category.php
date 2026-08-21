<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory;

enum Category: string
{
    case Feature = 'feature';
    case Bugfix = 'bugfix';
    case Performance = 'performance';
    case Refactoring = 'refactoring';
    case Maintenance = 'maintenance';
    case Dependency = 'dependency';
    case Documentation = 'documentation';
    case Testing = 'testing';
    case BuildCi = 'build_ci';
    case InternalTooling = 'internal_tooling';
}
