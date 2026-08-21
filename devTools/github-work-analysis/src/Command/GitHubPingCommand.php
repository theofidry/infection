<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Command;

use Infection\GitHubWorkAnalysis\GitHub\GitHubClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'github:ping',
    description: 'Checks the GitHub API connection and authentication.',
)]
final readonly class GitHubPingCommand
{
    public function __construct(
        private GitHubClient $githubClient,
    ) {}

    public function __invoke(OutputInterface $output): int
    {
        $authenticated = $this->githubClient->isAuthenticated();

        $output->writeln('GitHub API is reachable.');
        $output->writeln($authenticated ? 'Authenticated: yes' : 'Authenticated: no');

        return Command::SUCCESS;
    }
}
