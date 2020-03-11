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

namespace Infection\Logger;

use Infection\Environment\BuildContextResolver;
use Infection\Environment\CouldNotResolveBuildContext;
use Infection\Environment\CouldNotResolveStrykerApiKey;
use Infection\Environment\StrykerApiKeyResolver;
use Infection\Http\StrykerDashboardClient;
use Infection\Mutant\MetricsCalculator;
use function file_put_contents;
use function Safe\sprintf;
use Symfony\Component\Console\Output\OutputInterface;
use function str_replace;
use const JSON_PRETTY_PRINT;

/**
 * @internal
 */
final class BadgeLogger implements MutationTestingResultsLogger
{
    private $output;
    private $buildContextResolver;
    private $strykerApiKeyResolver;
    private $strykerDashboardClient;
    private $metricsCalculator;
    private $branch;

    public function __construct(
        OutputInterface $output,
        BuildContextResolver $buildContextResolver,
        StrykerApiKeyResolver $strykerApiKeyResolver,
        StrykerDashboardClient $strykerDashboardClient,
        MetricsCalculator $metricsCalculator,
        string $branch
    ) {
        $this->output = $output;
        $this->buildContextResolver = $buildContextResolver;
        $this->strykerApiKeyResolver = $strykerApiKeyResolver;
        $this->strykerDashboardClient = $strykerDashboardClient;
        $this->metricsCalculator = $metricsCalculator;
        $this->branch = $branch;
    }

    public function log(): void
    {
        $json = (new StrykerReportFactory())->create($this->metricsCalculator);

        $escapedJson = json_encode( json_decode($json), JSON_HEX_QUOT|JSON_HEX_APOS );
        $escapedJson = str_replace("\u0022", "\\\"", $escapedJson );
        $escapedJson = str_replace("\u0027", "\\'",  $escapedJson );

        $template = <<<'HTML'
<!DOCTYPE html>

<head>
    <title>Install local example - Mutation test elements</title>
    <script defer src="https://www.unpkg.com/mutation-testing-elements"></script>
</head>

<body>
<a href="/">Back</a></li>
<mutation-test-report-app></mutation-test-report-app>
<script>
    document.getElementsByTagName('mutation-test-report-app').item(0).report = __JSON__;
</script>
</body>

</html>
HTML;
        file_put_contents(
            __DIR__.'/../../report.json',
            json_encode(json_decode($json), JSON_PRETTY_PRINT)
        );file_put_contents(
            __DIR__.'/../../report.html',
            str_replace(
                '__JSON__',
                $json,
                $template
            )
        );
        try {
            $buildContext = $this->buildContextResolver->resolve();
        } catch (CouldNotResolveBuildContext $exception) {
            $this->logMessage($exception->getMessage());

            return;
        }

        if ($buildContext->branch() !== $this->branch) {
            $this->logMessage(sprintf(
                'Expected branch "%s", found "%s"',
                $this->branch,
                $buildContext->branch()
            ));

            return;
        }

        try {
            $apiKey = $this->strykerApiKeyResolver->resolve(getenv());
        } catch (CouldNotResolveStrykerApiKey $exception) {
            $this->logMessage($exception->getMessage());

            return;
        }

        // All clear!
        $this->output->writeln('Sending dashboard report...');

        $this->strykerDashboardClient->sendReport(
            'github.com/' . $buildContext->repositorySlug(),
            $buildContext->branch(),
            $apiKey,
            $this->metricsCalculator->getMutationScoreIndicator()
        );
    }

    private function logMessage(string $message): void
    {
        $this->output->writeln(sprintf('Dashboard report has not been sent: %s', $message));
    }
}
