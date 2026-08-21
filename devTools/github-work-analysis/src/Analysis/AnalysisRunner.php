<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis;

use Infection\GitHubWorkAnalysis\Analysis\Processor\Pipeline;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the analysis database by running its processors over a synchronized source copy.
 */
final readonly class AnalysisRunner
{
    public function __construct(
        #[Autowire(service: 'github_work_analysis.analysis_connection')]
        private PDO $pdo,
        private Pipeline $pipeline,
    ) {}

    /**
     * @return iterable<string>
     */
    public function run(): iterable
    {
        return $this->pipeline->process($this->pdo);
    }
}
