<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Command;

use Closure;
use Infection\GitHubWorkAnalysis\Entity\Synchronization\IncompleteSynchronizationRun;
use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatus;
use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatusRepository;
use Infection\GitHubWorkAnalysis\Synchronization\Synchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

use function explode;
use function sprintf;

#[AsCommand(
    name: 'github:synchronize',
    description: 'Synchronizes the GitHub source dataset.',
    help: <<<'HELP'
        The first synchronization is always full.

        After a completed synchronization, the default mode is incremental. It requests items
        updated since the previous synchronization, with a five-minute overlap to prevent missed
        updates at the time boundary.

        If an incomplete synchronization exists, the command resumes it. Use <info>--restart</info>
        to abandon that synchronization and start a new one. Use <info>--full</info> to start a full
        synchronization instead of an incremental synchronization. Use both options to replace an
        incomplete synchronization with a full synchronization.
        HELP,
)]
final readonly class GitHubSynchronizeCommand
{
    public function __construct(
        private Synchronizer $synchronizer,
        private SynchronizationStatusRepository $statusReader,
    ) {}

    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'GitHub repository.')]
        string $repository = 'infection/infection',
        #[Option(description: 'Starts a full synchronization.')]
        bool $full = false,
        #[Option(description: 'Abandons and replaces an incomplete run.')]
        bool $restart = false,
    ): int {
        [$owner, $name] = self::repository($repository);
        $status = $this->statusReader->get();
        $output->writeln(self::messages($status, $restart));
        [$progress, $reportProgress] = self::progress($output, $status->incompleteRun, $restart);

        $this->synchronizer->synchronize(
            $owner,
            $name,
            $full,
            $restart,
            $reportProgress,
        );

        $progress->finish();
        $output->writeln("\nSynchronization completed.");

        return Command::SUCCESS;
    }

    /**
     * @return iterable<string>
     */
    private static function messages(SynchronizationStatus $status, bool $restart): iterable
    {
        if ($status->lastCompletedAt !== null) {
            yield sprintf(
                'Previous synchronization completed at %s (last full: %s; %d issues and %d pull requests stored).',
                $status->lastCompletedAt,
                $status->lastFullSyncAt ?? 'never',
                $status->issues,
                $status->pullRequests,
            );
        }

        $run = $status->incompleteRun;

        if ($run === null) {
            return;
        }

        yield sprintf(
            'Incomplete %s synchronization (%s) found: %d pages and %d items imported.',
            $run->mode->value,
            $run->status,
            $run->pagesImported,
            $run->itemsSeen,
        );
        yield $restart
            ? 'Dropping the incomplete synchronization from the database and starting a new one.'
            : 'Resuming the incomplete synchronization.';
    }

    /**
     * @return array{ProgressBar, Closure(positive-int, non-negative-int): void}
     */
    private static function progress(OutputInterface $output, ?IncompleteSynchronizationRun $run, bool $restart): array
    {
        $pagesImported = $run === null || $restart ? 0 : $run->pagesImported;
        $itemsSeen = $run === null || $restart ? 0 : $run->itemsSeen;
        $progress = new ProgressBar($output);
        $progress->setFormat('Synchronization: %current% pages, %message% items [%elapsed%]');
        $progress->setMessage((string) $itemsSeen);
        $progress->start();
        $progress->setProgress($pagesImported);

        return [
            $progress,
            static function (int $pages, int $items) use ($progress, &$itemsSeen): void {
                $itemsSeen += $items;
                $progress->setMessage((string) $itemsSeen);
                $progress->advance($pages);
            },
        ];
    }

    /**
     * @return array{string, string}
     */
    private static function repository(string $repository): array
    {
        $parts = explode('/', $repository);

        Assert::count($parts, 2, 'Repository must use the owner/name form.');
        Assert::stringNotEmpty($parts[0], 'Repository must use the owner/name form.');
        Assert::stringNotEmpty($parts[1], 'Repository must use the owner/name form.');

        return [$parts[0], $parts[1]];
    }
}
