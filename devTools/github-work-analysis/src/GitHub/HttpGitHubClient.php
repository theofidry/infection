<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\GitHub;

use DateTimeZone;
use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Safe\DateTimeImmutable;
use Safe\Exceptions\DatetimeException;
use Safe\Exceptions\UrlException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Webmozart\Assert\Assert;

use function array_key_exists;
use function explode;
use function http_build_query;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function random_int;
use function rawurlencode;
use function Safe\parse_url;
use function Safe\preg_match;
use function sprintf;
use function str_contains;
use function strtolower;

use const DATE_RFC7231;
use const JSON_THROW_ON_ERROR;
use const PHP_QUERY_RFC3986;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

final readonly class HttpGitHubClient implements GitHubClient
{
    private const int MAX_RETRIES = 3;
    private const int HTTP_OK = 200;
    private const int HTTP_UNAUTHORIZED = 401;
    private const int HTTP_FORBIDDEN = 403;
    private const int HTTP_TOO_MANY_REQUESTS = 429;
    private const int HTTP_SERVER_ERROR = 500;
    private const int BACKOFF_BASE = 2;

    public function __construct(
        #[Autowire(service: 'github.client')]
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
    ) {}

    public function initialIssuesPageUrl(string $owner, string $name, SynchronizationMode $mode, ?string $since): string
    {
        $parameters = [
            'state' => 'all',
            'sort' => $mode === SynchronizationMode::FULL ? 'created' : 'updated',
            'direction' => 'asc',
            'per_page' => 100,
        ];

        if ($since !== null) {
            $parameters['since'] = $since;
        }

        return sprintf(
            'https://api.github.com/repos/%s/%s/issues?%s',
            rawurlencode($owner),
            rawurlencode($name),
            http_build_query($parameters, encoding_type: PHP_QUERY_RFC3986),
        );
    }

    /**
     * @throws DatetimeException when GitHub returns an invalid Date header
     * @throws InvalidArgumentException when GitHub returns an invalid or permanent failure response
     * @throws JsonException when GitHub returns invalid JSON
     * @throws RuntimeException when a GitHub rate limit persists beyond the retry limit
     * @throws TransportExceptionInterface when a transport failure persists beyond the retry limit
     */
    public function fetchIssuesPage(string $url): GitHubIssuesPage
    {
        $response = $this->request($url);

        return new GitHubIssuesPage(
            self::decodeItems($response->getContent(false)),
            self::sourceDate($response),
            self::nextPageUrl(self::header($response, 'link')),
        );
    }

    public function isAuthenticated(): bool
    {
        $response = $this->httpClient->request('GET', 'https://api.github.com/user');
        $status = $response->getStatusCode();
        $response->getContent(false);

        Assert::oneOf(
            $status,
            [self::HTTP_OK, self::HTTP_UNAUTHORIZED],
            'Unexpected GitHub response (HTTP %s).',
        );

        return $status === self::HTTP_OK;
    }

    private function request(string $url): ResponseInterface
    {
        self::validateUrl($url);

        for ($attempt = 0;; ++$attempt) {
            try {
                $response = $this->httpClient->request('GET', $url);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $response->getContent(false);
            } catch (TransportExceptionInterface $failure) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw $failure;
                }

                $this->backoff($attempt);

                continue;
            }

            if ($status === self::HTTP_FORBIDDEN && ($headers['x-ratelimit-remaining'][0] ?? null) === '0') {
                $reset = $headers['x-ratelimit-reset'][0] ?? 'unknown';

                throw new RuntimeException(sprintf('GitHub rate limit reached; reset: %s.', $reset));
            }

            if (self::isSecondaryRateLimit($response)) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw new RuntimeException('GitHub secondary rate limit persisted after retries.');
                }

                $retryAfter = $headers['retry-after'][0] ?? null;

                if (is_string($retryAfter) && preg_match('/^\d+$/', $retryAfter) === 1) {
                    $this->clock->sleep(max(0, (int) $retryAfter));
                } else {
                    $this->backoff($attempt);
                }

                continue;
            }

            if ($status >= self::HTTP_SERVER_ERROR && $attempt < self::MAX_RETRIES) {
                $this->backoff($attempt);

                continue;
            }

            $message =
                $status === self::HTTP_UNAUTHORIZED || $status === self::HTTP_FORBIDDEN
                    ? sprintf(
                        'GitHub authentication or permission failure (HTTP %d).',
                        $status,
                    )
                    : sprintf('Unexpected GitHub response (HTTP %d).', $status);

            Assert::same($status, self::HTTP_OK, $message);

            return $response;
        }
    }

    private static function validateUrl(string $url): void
    {
        Assert::true(self::isTrustedUrl($url), 'Refusing to request an untrusted GitHub API URL.');
    }

    private static function isTrustedUrl(string $url): bool
    {
        try {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
        } catch (UrlException) {
            return false;
        }

        return $scheme === 'https' && $host === 'api.github.com';
    }

    private function backoff(int $attempt): void
    {
        $maximum = self::BACKOFF_BASE ** $attempt;
        $this->clock->sleep(random_int(0, $maximum));
    }

    private static function isSecondaryRateLimit(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        if ($status === self::HTTP_TOO_MANY_REQUESTS) {
            return true;
        }

        if ($status !== self::HTTP_FORBIDDEN) {
            return false;
        }

        if (array_key_exists('retry-after', $response->getHeaders(false))) {
            return true;
        }

        return str_contains(strtolower($response->getContent(false)), 'secondary rate limit');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeItems(string $body): array
    {
        $items = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        Assert::isArray($items, 'GitHub response must be a JSON array.');

        foreach ($items as $item) {
            Assert::isArray($item, 'GitHub response items must be objects.');
        }

        /**
         * @var list<array<string, mixed>> $items
         */
        return $items;
    }

    private static function sourceDate(ResponseInterface $response): string
    {
        $date = self::header($response, 'date');

        Assert::string($date, 'GitHub response is missing its Date header.');
        $parsed = DateTimeImmutable::createFromFormat(DATE_RFC7231, $date);

        return $parsed
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private static function header(ResponseInterface $response, string $name): ?string
    {
        return $response->getHeaders(false)[$name][0] ?? null;
    }

    private static function nextPageUrl(?string $link): ?string
    {
        if ($link === null) {
            return null;
        }

        foreach (explode(',', $link) as $part) {
            if (preg_match('/^\s*<([^>]+)>;\s*rel="next"\s*$/', $part, $matches) !== 1) {
                continue;
            }

            $url = $matches[1] ?? null;

            Assert::string($url, 'GitHub returned a malformed next-page link.');
            Assert::true(self::isTrustedUrl($url), 'GitHub returned an untrusted next-page URL.');

            return $url;
        }

        return null;
    }
}
