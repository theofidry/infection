<?php

declare(strict_types=1);

namespace Infection\Report\Writer;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use UnexpectedValueException;
use function is_string;

final readonly class StreamWriter implements ReportWriter
{
    public const STDOUT_STREAM = 'php://stdout';
    public const STDERR_STREAM = 'php://stdout';

    public function __construct(
        private OutputInterface $output,
    ) {
    }

    public static function createForStream(
        string $stream,
        // TODO: to review
        bool $decorated = null,
    ): self
    {
        $output = match($stream) {
            self::STDOUT_STREAM => new ConsoleOutput(),
            self::STDERR_STREAM => (new ConsoleOutput())->getErrorOutput(),
            default => throw new UnexpectedValueException(),
        };

        return new self($output);
    }

    public function write(iterable|string $contentOrLines): void
    {
        if (is_string($contentOrLines)) {
            $this->output->writeln($contentOrLines);
        } else {
            foreach ($contentOrLines as $line) {
                $this->output->write($line);
            }
        }
    }
}