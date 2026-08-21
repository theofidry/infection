<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Report;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;

/**
 * Builds the interactive pull request category dashboard from the analysis views.
 *
 * @internal
 * @final
 */
readonly class PullRequestCategoryDashboardGenerator
{
    public function __construct(
        private PullRequestCategoryDashboardDataFetcher $dataFetcher,
        private PullRequestCategoryDashboardRenderer $renderer,
        private Filesystem $filesystem,
        #[Autowire(param: 'github_work_analysis.dashboard_report')]
        private string $reportPath,
    ) {}

    public function generate(): string
    {
        $data = $this->dataFetcher->fetch();
        $contents = $this->renderer->render($data);

        $this->filesystem->mkdir(dirname($this->reportPath));
        $this->filesystem->dumpFile($this->reportPath, $contents);

        return $this->reportPath;
    }
}
