<?php

declare(strict_types=1);

namespace Infection\Tests\Event\Subscriber;

use Infection\Console\IO;
use Infection\Event\EventDispatcher\SyncEventDispatcher;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutableFileWasProcessed;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasStarted;
use Infection\Event\Subscriber\MutationGenerationBufferedLoggerSubscriber;
use Infection\Event\Subscriber\MutationGenerationLoggerSubscriber;
use Infection\Logger\Console\ConsoleLogger;
use Infection\Logger\MutationGeneration\MutationGenerationLoggerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(MutationGenerationBufferedLoggerSubscriber::class)]
final class MutationGenerationBufferedLoggerSubscriberTest extends TestCase
{
    #[DataProvider('provideProgressModes')]
    public function test_it_logs_diagnostics_after_generation_finishes(bool $noProgress): void
    {
        $output = new BufferedOutput();
        $logger = new MutationGenerationBufferedLoggerSubscriber(
            new ConsoleLogger(
                new IO(
                    new StringInput(''),
                    $output,
                ),
            ),
            $output,
        );

        $dispatcher = new SyncEventDispatcher();
        $dispatcher->addSubscriber(
            new MutationGenerationLoggerSubscriber(
                new MutationGenerationLoggerFactory($noProgress, $output)->create(),
            ),
        );
        $dispatcher->addSubscriber($logger);

        $dispatcher->dispatch(new MutationGenerationWasStarted(1));

        $logger->warning('Unknown mutator "UnknownMutator".');
        $logger->warning('No mutator names.');

        $this->assertStringNotContainsString('[warning]', $output->fetch());

        $dispatcher->dispatch(new MutableFileWasProcessed('/source.php', []));
        $dispatcher->dispatch(new MutationGenerationWasFinished());

        $expected = $noProgress
            ? "\n"
            : "Processing source code files: 1/1\n";

        $this->assertSame(
            $expected . "[warning] Unknown mutator \"UnknownMutator\".\n[warning] No mutator names.\n",
            $output->fetch(),
        );

        $dispatcher->dispatch(new MutationGenerationWasFinished());

        $this->assertSame('', $output->fetch());
    }

    public static function provideProgressModes(): iterable
    {
        yield 'progress enabled' => [false];
        yield 'progress disabled' => [true];
    }

    public function test_it_preserves_log_level_message_and_context(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $subscriber = new MutationGenerationBufferedLoggerSubscriber(
            $logger,
            new BufferedOutput(),
        );

        $subscriber->notice('Message with {name}.', ['name' => 'context']);

        $logger->expects($this->once())
            ->method('log')
            ->with(
                LogLevel::NOTICE,
                'Message with {name}.',
                ['name' => 'context'],
            );

        $subscriber->onMutationGenerationWasFinished(new MutationGenerationWasFinished());
    }
}
