<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\GitHub;

use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;

/**
 * Fetches and interprets pages from the GitHub Issues API, including its retry and pagination
 * protocols.
 */
interface GitHubClient
{
    public function initialIssuesPageUrl(
        string $owner,
        string $name,
        SynchronizationMode $mode,
        ?string $since,
    ): string;

    public function fetchIssuesPage(string $url): GitHubIssuesPage;

    public function isAuthenticated(): bool;
}
