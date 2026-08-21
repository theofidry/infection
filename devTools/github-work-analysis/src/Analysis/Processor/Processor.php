<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\Processor;

use PDO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('github_work_analysis.analysis_processor')]
interface Processor
{
    /**
     * @return iterable<string>
     */
    public function process(PDO $pdo): iterable;
}
