<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Command;

use Infection\GitHubWorkAnalysis\Command\GeneratePullRequestCategoryDashboardCommand;
use Infection\GitHubWorkAnalysis\Report\PullRequestCategoryDashboardGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(GeneratePullRequestCategoryDashboardCommand::class)]
final class GeneratePullRequestCategoryDashboardCommandTest extends TestCase
{
    public function test_generates_the_dashboard(): void
    {
        $generator = $this->createMock(PullRequestCategoryDashboardGenerator::class);
        $generator
            ->expects($this->once())
            ->method('generate')
            ->willReturn('/project/var/report.html');
        $output = new BufferedOutput();
        $command = new GeneratePullRequestCategoryDashboardCommand($generator);

        $this->assertSame(0, $command($output));
        $this->assertSame("Dashboard generated at /project/var/report.html\n", $output->fetch());
    }
}
