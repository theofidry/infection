<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Command;

use Infection\GitHubWorkAnalysis\Command\GitHubPingCommand;
use Infection\GitHubWorkAnalysis\GitHub\GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(GitHubPingCommand::class)]
final class GitHubPingCommandTest extends TestCase
{
    #[DataProvider('authenticationProvider')]
    public function test_reports_the_connection_and_authentication_state(bool $authenticated, string $expected): void
    {
        $githubClient = $this->createMock(GitHubClient::class);
        $githubClient
            ->expects($this->once())
            ->method('isAuthenticated')
            ->willReturn($authenticated);
        $output = new BufferedOutput();
        $command = new GitHubPingCommand($githubClient);

        $this->assertSame(0, $command($output));
        $this->assertSame($expected, $output->fetch());
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function authenticationProvider(): iterable
    {
        yield 'authenticated' => [
            true,
            "GitHub API is reachable.\nAuthenticated: yes\n",
        ];
        yield 'not authenticated' => [
            false,
            "GitHub API is reachable.\nAuthenticated: no\n",
        ];
    }
}
