<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\GitHub;

use Infection\GitHubWorkAnalysis\GitHub\HttpGitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpGitHubClient::class)]
final class HttpGitHubClientTest extends TestCase
{
    #[DataProvider('authenticationProvider')]
    public function test_reports_the_authentication_state(int $status, bool $expected): void
    {
        $client = new MockHttpClient(
            function (string $method, string $url) use ($status): MockResponse {
                $this->assertSame('GET', $method);
                $this->assertSame('https://api.github.com/user', $url);

                return new MockResponse('', ['http_code' => $status]);
            },
        );
        $githubClient = new HttpGitHubClient($client, new MockClock());

        $this->assertSame($expected, $githubClient->isAuthenticated());
        $this->assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function authenticationProvider(): iterable
    {
        yield 'authenticated' => [200, true];
        yield 'not authenticated' => [401, false];
    }
}
