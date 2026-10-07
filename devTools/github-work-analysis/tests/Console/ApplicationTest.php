<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Console;

use Infection\GitHubWorkAnalysis\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;

use function restore_exception_handler;

#[CoversClass(Kernel::class)]
final class ApplicationTest extends TestCase
{
    public function test_registers_the_application_commands(): void
    {
        $kernel = new Kernel('test', false);
        $application = new Application($kernel);

        try {
            $this->assertTrue($application->has('analyze'));
            $this->assertTrue($application->has('github:ping'));
            $this->assertTrue($application->has('github:status'));
            $this->assertTrue($application->has('github:synchronize'));
            $this->assertTrue($application->has('report:pull-request-categories'));
            $this->assertFalse($application->has('dataset:status'));
            $this->assertFalse($application->has('dataset:sync'));
            $this->assertFalse($application->has('github:sync'));

            $container = $kernel->getContainer();
            $projectDirectory = dirname(__DIR__, 2);

            $this->assertSame(
                $projectDirectory . '/var/github.sqlite',
                $container->getParameter('github_work_analysis.source_database'),
            );
            $this->assertSame(
                $projectDirectory . '/var/github-work-analysis.sqlite',
                $container->getParameter('github_work_analysis.result_database'),
            );
            $this->assertSame(
                $projectDirectory . '/var/pull-requests-by-category-over-time.html',
                $container->getParameter('github_work_analysis.dashboard_report'),
            );
        } finally {
            $kernel->shutdown();

            restore_exception_handler();
        }
    }
}
