<?php

declare(strict_types=1);

namespace Infection\Temporary;

/**
 * @internal
 */
final class Configuration
{
    private $sourceDirectories;
    private $excludeDirectories;
    private $filter;
    private $onlyCovered;

    /**
     * @param string[] $sourceDirectories
     * @param string[] $excludeDirectories
     * @param string   $filter
     */
    public function __construct(
        array $sourceDirectories,
        array $excludeDirectories,
        string $filter,
        bool $onlyCovered
    )
    {
        $this->sourceDirectories = $sourceDirectories;
        $this->excludeDirectories = $excludeDirectories;
        $this->filter = $filter;
        $this->onlyCovered = $onlyCovered;
    }

    /**
     * @return string[]
     */
    public function getSourceDirectories(): array
    {
        return $this->sourceDirectories;
    }

    /**
     * @return string[]
     */
    public function getExcludeDirectories(): array
    {
        return $this->excludeDirectories;
    }

    public function getFilter(): string
    {
        return $this->filter;
    }

    public function getOnlyCovered(): bool
    {
        return $this->onlyCovered;
    }
}