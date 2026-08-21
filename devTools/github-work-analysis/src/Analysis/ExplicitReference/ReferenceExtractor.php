<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\ExplicitReference;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisError;

use function array_key_exists;
use function Safe\preg_match_all;
use function Safe\preg_replace;
use function Safe\preg_replace_callback;
use function str_repeat;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;
use function trim;

use const PREG_OFFSET_CAPTURE;

/**
 * Extracts local, repository-qualified, and URL work-item references from Markdown. It identifies
 * closing references from their keywords and excludes references inside comments and code blocks.
 */
final class ReferenceExtractor
{
    private const string REFERENCE_PATTERN = <<<'REGEX'
        ~https://github\.com/(?<url_owner>[A-Za-z0-9_.-]+)/(?<url_repository>[A-Za-z0-9_.-]+)/(?:issues|pull)/(?<url_number>\d+)|(?<qualified_owner>[A-Za-z0-9_.-]+)/(?<qualified_repository>[A-Za-z0-9_.-]+)#(?<qualified_number>\d+)|(?<![\w/])#(?<local_number>\d+)~i
        REGEX;

    private const string CLOSING_PATTERN = <<<'REGEX'
        ~\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s*:?[ \t]*(?<targets>(?:(?:https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:issues|pull)/\d+|[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\#\d+|\#\d+)(?:[ \t]*(?:,|and)?[ \t]*))+)~ix
        REGEX;

    /**
     * @return list<Reference>
     */
    public function extract(string $markdown): array
    {
        $text = self::withoutFencedCode($markdown);
        $closingOffsets = self::closingReferenceOffsets($text);
        preg_match_all(self::REFERENCE_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);
        $references = [];

        foreach ($matches[0] as $index => [$evidence, $offset]) {
            $number = self::number($matches, $index);

            if ($number === null) {
                throw new AnalysisError('A work-item reference has an unexpected shape.');
            }

            $references[] = new Reference(
                self::capture($matches, 'url_owner', $index) ?? self::capture($matches, 'qualified_owner', $index),
                self::capture($matches, 'url_repository', $index) ?? self::capture(
                    $matches,
                    'qualified_repository',
                    $index,
                ),
                $number,
                array_key_exists($offset, $closingOffsets) ? RelationshipType::CLOSES : RelationshipType::REFERENCES,
                self::evidenceLine($text, $offset),
            );
        }

        return $references;
    }

    private static function withoutFencedCode(string $markdown): string
    {
        $withoutComments = preg_replace_callback(
            '/<!--.*?-->/s',
            static fn(array $match): string => str_repeat("\n", substr_count((string) ($match[0] ?? ''), "\n")),
            $markdown,
        );
        $withoutFences = preg_replace_callback(
            '/^(?<fence>`{3,}|~{3,})[^\r\n]*(?:\r?\n|\r).*?^\k<fence>[ \t]*$/ms',
            static fn(array $match): string => str_repeat("\n", substr_count((string) ($match[0] ?? ''), "\n")),
            $withoutComments,
        );

        return preg_replace('/^(?: {4}|\t).*$/m', '', $withoutFences);
    }

    private static function evidenceLine(string $text, int $offset): string
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($text, "\n", $offset);

        return trim(substr($text, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));
    }

    /**
     * @return array<int, true>
     */
    private static function closingReferenceOffsets(string $text): array
    {
        preg_match_all(self::CLOSING_PATTERN, $text, $closingMatches, PREG_OFFSET_CAPTURE);
        $offsets = [];

        foreach ($closingMatches['targets'] as [$targets, $targetsOffset]) {
            preg_match_all(self::REFERENCE_PATTERN, $targets, $references, PREG_OFFSET_CAPTURE);

            foreach ($references[0] as [$reference, $offset]) {
                $offsets[$targetsOffset + $offset] = true;
            }
        }

        return $offsets;
    }

    /**
     * @param array<string|int, array<int, array{string, int}>> $matches
     */
    private static function number(array $matches, int $index): ?int
    {
        foreach (['url_number', 'qualified_number', 'local_number'] as $name) {
            $value = self::capture($matches, $name, $index);

            if ($value !== null) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string|int, array<int, array{string, int}>> $matches
     */
    private static function capture(array $matches, string $name, int $index): ?string
    {
        $capture = $matches[$name][$index] ?? null;

        if ($capture === null || $capture[1] === -1 || $capture[0] === '') {
            return null;
        }

        return $capture[0];
    }
}
