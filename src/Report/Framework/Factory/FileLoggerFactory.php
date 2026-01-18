<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Report\Framework\Factory;

use Infection\Configuration\Entry\Logs;
use Infection\Console\LogVerbosity;
use Infection\Logger\FederatedLogger;
use Infection\Logger\FileLogger;
use Infection\Logger\Html\HtmlFileLogger;
use Infection\Logger\JsonLogger;
use Infection\Logger\PerMutatorLogger;
use Infection\Metrics\MetricsCalculator;
use Infection\Metrics\ResultsCollector;
use Infection\Report\Framework\DataProducer;
use Infection\Report\GitLab\GitLabCodeQualityLogger;
use Infection\Report\Reporter;
use Infection\Report\Stryker\StrykerHtmlReportBuilder;
use Infection\Report\Text\GitHubActionsLogTextFileLogger;
use Infection\Report\Text\TextFileLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 * @final
 */
readonly class FileLoggerFactory
{
    public function __construct(
        private MetricsCalculator $metricsCalculator,
        private ResultsCollector $resultsCollector,
        private Filesystem $filesystem,
        private string $logVerbosity,
        private bool $debugMode,
        private bool $onlyCoveredCode,
        private LoggerInterface $logger,
        private StrykerHtmlReportBuilder $strykerHtmlReportBuilder,
        private ?string $loggerProjectRootDirectory,
        private float $processTimeout,
    ) {
    }

    public function createFromLogEntries(Logs $logConfig): Reporter
    {
        $loggers = [];

        foreach ($this->createLineLoggers($logConfig) as $filePath => $lineLogger) {
            $loggers[] = $this->wrapWithFileLogger($filePath, $lineLogger);
        }

        return new FederatedLogger(...$loggers);
    }

    /**
     * @return iterable<string, DataProducer>
     */
    private function createLineLoggers(Logs $logConfig): iterable
    {
        if ($this->logVerbosity === LogVerbosity::NONE) {
            return;
        }

        if ($logConfig->getTextLogFilePath() !== null) {
            yield $logConfig->getTextLogFilePath() => $this->createTextLogger($logConfig);
        }

        if ($logConfig->getHtmlLogFilePath() !== null) {
            yield $logConfig->getHtmlLogFilePath() => $this->createHtmlLogger();
        }

        if ($logConfig->getJsonLogFilePath() !== null) {
            yield $logConfig->getJsonLogFilePath() => $this->createJsonLogger();
        }

        if ($logConfig->getGitlabLogFilePath() !== null) {
            yield $logConfig->getGitlabLogFilePath() => $this->createGitlabLogger();
        }

        if ($logConfig->getPerMutatorFilePath() !== null) {
            yield $logConfig->getPerMutatorFilePath() => $this->createPerMutatorLogger();
        }
    }

    private function wrapWithFileLogger(string $filePath, DataProducer $lineLogger): Reporter
    {
        return new FileLogger(
            $filePath,
            $this->filesystem,
            $lineLogger,
            $this->logger,
        );
    }

    private function createTextLogger(Logs $logConfig): DataProducer
    {
        if (
            $logConfig->getUseGitHubAnnotationsLogger()
            && $logConfig->getTextLogFilePath() === 'php://stdout'
        ) {
            return new GitHubActionsLogTextFileLogger(
                $this->resultsCollector,
                $this->logVerbosity === LogVerbosity::DEBUG,
                $this->onlyCoveredCode,
                $this->debugMode,
            );
        }

        return new TextFileLogger(
            $this->resultsCollector,
            $this->logVerbosity === LogVerbosity::DEBUG,
            $this->onlyCoveredCode,
            $this->debugMode,
        );
    }

    private function createHtmlLogger(): DataProducer
    {
        return new HtmlFileLogger(
            $this->strykerHtmlReportBuilder,
        );
    }

    private function createJsonLogger(): DataProducer
    {
        return new JsonLogger(
            $this->metricsCalculator,
            $this->resultsCollector,
            $this->onlyCoveredCode,
        );
    }

    private function createGitlabLogger(): DataProducer
    {
        return new GitLabCodeQualityLogger($this->resultsCollector, $this->loggerProjectRootDirectory);
    }

    private function createPerMutatorLogger(): DataProducer
    {
        return new PerMutatorLogger(
            $this->metricsCalculator,
            $this->resultsCollector,
            $this->processTimeout,
        );
    }
}
