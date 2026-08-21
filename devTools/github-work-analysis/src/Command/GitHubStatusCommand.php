<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Command;

use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatus;
use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatusRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(
    name: 'github:status',
    description: 'Shows the status of the local GitHub data import.',
)]
final readonly class GitHubStatusCommand
{
    public function __construct(
        private SynchronizationStatusRepository $statusReader,
    ) {}

    public function __invoke(OutputInterface $output): int
    {
        $status = $this->statusReader->get();

        $output->writeln(
            self::createMessages($status),
        );

        return Command::SUCCESS;
    }

    /**
     * @return iterable<string>
     */
    private static function createMessages(SynchronizationStatus $status): iterable
    {
        yield from [
            sprintf('Issues: %d', $status->issues),
            sprintf('Pull requests: %d', $status->pullRequests),
            sprintf('Newest item update: %s', $status->newestItemUpdate ?? 'never'),
            sprintf('Last completed sync: %s', $status->lastCompletedAt ?? 'never'),
            sprintf('Last full sync: %s', $status->lastFullSyncAt ?? 'never'),
        ];

        $run = $status->incompleteRun;

        yield $run === null
            ? 'Incomplete run: none'
            : sprintf(
                'Incomplete run: %s/%s %s %s; %d pages, %d items; next: %s',
                $run->owner,
                $run->name,
                $run->mode->value,
                $run->status,
                $run->pagesImported,
                $run->itemsSeen,
                $run->nextPageUrl ?? 'initial request',
            );
    }
}
