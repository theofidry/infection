<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Report;

use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

use function json_encode;
use function str_replace;

/**
 * Renders pull request dashboard data as a standalone HTML document.
 */
final readonly class PullRequestCategoryDashboardRenderer
{
    private const string DETAILED_DATA = '__DETAILED_DATA__';
    private const string PR_COUNT_DATA = '__PR_COUNT_DATA__';
    private const string CATEGORIZED_PR_COUNT_DATA = '__CATEGORIZED_PR_COUNT_DATA__';
    private const string AGGREGATED_DATA = '__AGGREGATED_DATA__';
    private const string LATEST_DATE = '__LATEST_DATE__';

    public function __construct(
        private Filesystem $filesystem,
        #[Autowire(param: 'github_work_analysis.dashboard_template')]
        private string $templatePath,
    ) {}

    /**
     * @throws JsonException
     */
    public function render(PullRequestCategoryDashboardData $data): string
    {
        return str_replace(
            [
                self::DETAILED_DATA,
                self::PR_COUNT_DATA,
                self::CATEGORIZED_PR_COUNT_DATA,
                self::AGGREGATED_DATA,
                self::LATEST_DATE,
            ],
            [
                self::encode($data->detailedCounts),
                self::encode($data->pullRequestCounts),
                self::encode($data->categorizedPullRequestCounts),
                self::encode($data->aggregatedCounts),
                self::encode($data->latestDate),
            ],
            $this->filesystem->readFile($this->templatePath),
        );
    }

    /**
     * @throws JsonException
     */
    private static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
