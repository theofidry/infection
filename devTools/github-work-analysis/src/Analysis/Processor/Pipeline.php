<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\Processor;

use PDO;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class Pipeline
{
    /**
     * @param iterable<Processor> $processors
     */
    public function __construct(
        #[AutowireIterator('github_work_analysis.analysis_processor')]
        private iterable $processors,
    ) {}

    /**
     * @return iterable<string>
     */
    public function process(PDO $pdo): iterable
    {
        foreach ($this->processors as $processor) {
            yield from $processor->process($pdo);
        }
    }
}
