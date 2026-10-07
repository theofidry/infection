<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Command;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'analyze',
    description: 'Builds the work analysis database.',
)]
final readonly class RunAnalysisCommand
{
    public function __construct(
        private readonly AnalysisRunner $analysisRunner,
    ) {}

    public function __invoke(OutputInterface $output): int
    {
        $output->writeln($this->analysisRunner->run());

        return Command::SUCCESS;
    }
}
