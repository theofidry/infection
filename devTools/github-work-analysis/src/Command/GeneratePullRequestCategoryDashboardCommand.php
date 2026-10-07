<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Command;

use Infection\GitHubWorkAnalysis\Report\PullRequestCategoryDashboardGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(
    name: 'report:pull-request-categories',
    description: 'Generates the interactive pull request category dashboard.',
    help: <<<'HELP'
        Generates a standalone HTML dashboard from the completed analysis database. Run
        <info>bin/console analyze</info> first to refresh the database.

        The configured output path is the <info>github_work_analysis.dashboard_report</info>
        container parameter.
        HELP,
)]
final readonly class GeneratePullRequestCategoryDashboardCommand
{
    public function __construct(
        private PullRequestCategoryDashboardGenerator $generator,
    ) {}

    public function __invoke(OutputInterface $output): int
    {
        $path = $this->generator->generate();

        $output->writeln(
            sprintf('Dashboard generated at %s', $path),
        );

        return Command::SUCCESS;
    }
}
