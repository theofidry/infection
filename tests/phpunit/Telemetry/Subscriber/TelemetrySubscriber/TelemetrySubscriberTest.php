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

namespace Infection\Tests\Telemetry\Subscriber\TelemetrySubscriber;

use Infection\Configuration\Entry\TelemetryEntry;
use Infection\Event\Events\Application\ApplicationExecutionWasFinished;
use Infection\Event\Events\Application\ApplicationExecutionWasStarted;
use Infection\Event\Events\ArtefactCollection\ArtefactCollectionWasFinished;
use Infection\Event\Events\ArtefactCollection\ArtefactCollectionWasStarted;
use Infection\Event\Events\ArtefactCollection\InitialStaticAnalysis\InitialStaticAnalysisRunWasFinished;
use Infection\Event\Events\ArtefactCollection\InitialStaticAnalysis\InitialStaticAnalysisRunWasStarted;
use Infection\Event\Events\ArtefactCollection\InitialTestExecution\InitialTestSuiteWasFinished;
use Infection\Event\Events\ArtefactCollection\InitialTestExecution\InitialTestSuiteWasStarted;
use Infection\Event\Events\Ast\AstProcessingWasFinished;
use Infection\Event\Events\Ast\AstProcessingWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationAnalysisWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationAnalysisWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantProcessWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationEvaluationForMutationWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationHeuristicsWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationHeuristicsWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationForFileWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationForFileWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationTestingWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationTestingWasStarted;
use Infection\Event\Events\Reporting\ReportingWasFinished;
use Infection\Event\Events\Reporting\ReportingWasStarted;
use Infection\Event\Events\SourceCollection\SourceCollectionWasFinished;
use Infection\Event\Events\SourceCollection\SourceCollectionWasStarted;
use Infection\Framework\Iterable\IterableCounter;
use Infection\Logger\MutationAnalysis\TeamCity\NodeIdFactory;
use Infection\Process\Runner\HeuristicId;
use Infection\Process\Runner\ProcessRunner;
use Infection\Telemetry\InfectionSpanAttribute;
use Infection\Telemetry\InfectionSpanName;
use Infection\Telemetry\OpenTelemetryFactory;
use Infection\Telemetry\Subscriber\TelemetrySubscriber;
use Infection\Tests\Mutant\MutantExecutionResultBuilder;
use Infection\Tests\Mutation\MutationBuilder;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function sprintf;

#[CoversClass(TelemetrySubscriber::class)]
final class TelemetrySubscriberTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TelemetrySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->subscriber = new TelemetrySubscriber(
            (new OpenTelemetryFactory())->create(
                TelemetryEntry::createDefault(),
                $this->exporter,
            ),
        );
    }

    public function test_it_emits_a_single_parent_open_telemetry_trace(): void
    {
        $sourceFilePath = '/path/to/source.php';
        $sourceFileId = NodeIdFactory::create($sourceFilePath);
        $mutation = MutationBuilder::withMinimalTestData()
            ->withHash('mutation-A')
            ->withOriginalFilePath($sourceFilePath)
            ->build();

        $this->subscriber->onApplicationExecutionWasStarted(new ApplicationExecutionWasStarted());
        $this->subscriber->onSourceCollectionWasStarted(new SourceCollectionWasStarted());
        $this->subscriber->onSourceCollectionWasFinished(new SourceCollectionWasFinished(1));

        $this->subscriber->onArtefactCollectionWasStarted(new ArtefactCollectionWasStarted());
        $this->subscriber->onInitialTestSuiteWasStarted(new InitialTestSuiteWasStarted('PHPUnit', '12.5.0'));
        $this->subscriber->onInitialTestSuiteWasFinished(new InitialTestSuiteWasFinished('Test suite output'));
        $this->subscriber->onInitialStaticAnalysisRunWasStarted(new InitialStaticAnalysisRunWasStarted());
        $this->subscriber->onInitialStaticAnalysisRunWasFinished(new InitialStaticAnalysisRunWasFinished('Static analysis output'));
        $this->subscriber->onArtefactCollectionWasFinished(new ArtefactCollectionWasFinished());

        $this->subscriber->onMutationAnalysisWasStarted(new MutationAnalysisWasStarted());
        $this->subscriber->onMutationGenerationWasStarted(new MutationGenerationWasStarted(1));
        $this->subscriber->onAstProcessingWasStarted(new AstProcessingWasStarted($sourceFileId, $sourceFilePath));
        $this->subscriber->onAstProcessingWasFinished(new AstProcessingWasFinished($sourceFileId));
        $this->subscriber->onMutationGenerationForFileWasStarted(new MutationGenerationForFileWasStarted($sourceFilePath));
        $this->subscriber->onMutationGenerationForFileWasFinished(new MutationGenerationForFileWasFinished($sourceFilePath, [$mutation->getHash()]));
        $this->subscriber->onMutationGenerationWasFinished(new MutationGenerationWasFinished());

        $this->subscriber->onMutationTestingWasStarted(new MutationTestingWasStarted(IterableCounter::UNKNOWN_COUNT, $this->createStub(ProcessRunner::class)));
        $this->subscriber->onMutationEvaluationForMutationWasStarted(new MutationEvaluationForMutationWasStarted($mutation));
        $this->subscriber->onMutationHeuristicsWasStarted(new MutationHeuristicsWasStarted(HeuristicId::IGNORED_BY_REGEX, $mutation));
        $this->subscriber->onMutationHeuristicsWasFinished(new MutationHeuristicsWasFinished(HeuristicId::IGNORED_BY_REGEX, $mutation, escaped: true));
        $this->subscriber->onMutantProcessWasFinished(new MutantProcessWasFinished(
            MutantExecutionResultBuilder::withMinimalTestData()
                ->withMutantHash($mutation->getHash())
                ->build(),
        ));
        $this->subscriber->onMutationTestingWasFinished(new MutationTestingWasFinished());
        $this->subscriber->onMutationAnalysisWasFinished(new MutationAnalysisWasFinished());

        $this->subscriber->onReportingWasStarted(new ReportingWasStarted());
        $this->subscriber->onReportingWasFinished(new ReportingWasFinished());
        $this->subscriber->onApplicationExecutionWasFinished(new ApplicationExecutionWasFinished());

        $run = $this->span(InfectionSpanName::RUN);
        $sourceCollection = $this->span(InfectionSpanName::SOURCE_COLLECTION);
        $artefactCollection = $this->span(InfectionSpanName::ARTEFACT_COLLECTION);
        $mutationAnalysis = $this->span(InfectionSpanName::MUTATION_ANALYSIS);
        $mutationGeneration = $this->span(InfectionSpanName::MUTATION_GENERATION);
        $sourceFile = $this->span(InfectionSpanName::SOURCE_FILE);
        $mutationEvaluation = $this->span(InfectionSpanName::MUTATION_EVALUATION);
        $mutationEvaluationForMutation = $this->span(InfectionSpanName::MUTATION_EVALUATION_FOR_MUTATION);
        $heuristicSuppression = $this->span(InfectionSpanName::HEURISTIC_SUPPRESSION);
        $reporting = $this->span(InfectionSpanName::REPORTING);

        $this->assertSame('0000000000000000', $run->getParentSpanId());
        $this->assertSame($run->getSpanId(), $sourceCollection->getParentSpanId());
        $this->assertSame($run->getSpanId(), $artefactCollection->getParentSpanId());
        $this->assertSame($run->getSpanId(), $mutationAnalysis->getParentSpanId());
        $this->assertSame($run->getSpanId(), $reporting->getParentSpanId());
        $this->assertSame($mutationAnalysis->getSpanId(), $mutationGeneration->getParentSpanId());
        $this->assertSame($mutationGeneration->getSpanId(), $sourceFile->getParentSpanId());
        $this->assertSame($mutationAnalysis->getSpanId(), $mutationEvaluation->getParentSpanId());
        $this->assertSame($mutationEvaluation->getSpanId(), $mutationEvaluationForMutation->getParentSpanId());
        $this->assertSame($mutationEvaluationForMutation->getSpanId(), $heuristicSuppression->getParentSpanId());

        $this->assertSame(1, $sourceCollection->getAttributes()->get(InfectionSpanAttribute::SOURCE_COUNT));
        $this->assertSame($sourceFileId, $sourceFile->getAttributes()->get(InfectionSpanAttribute::SOURCE_FILE_ID));
        $this->assertSame($sourceFilePath, $sourceFile->getAttributes()->get(InfectionSpanAttribute::SOURCE_FILE_PATH));
        $this->assertSame($mutation->getHash(), $mutationEvaluationForMutation->getAttributes()->get(InfectionSpanAttribute::MUTATION_ID));
        $this->assertSame($sourceFilePath, $mutationEvaluationForMutation->getAttributes()->get(InfectionSpanAttribute::SOURCE_FILE_PATH));
        $this->assertCount(1, $mutationEvaluationForMutation->getLinks());
        $this->assertSame($sourceFile->getSpanId(), $mutationEvaluationForMutation->getLinks()[0]->getSpanContext()->getSpanId());
    }

    public function test_it_ends_open_spans_on_application_finish(): void
    {
        $sourceFilePath = '/path/to/source.php';
        $sourceFileId = NodeIdFactory::create($sourceFilePath);

        $this->subscriber->onApplicationExecutionWasStarted(new ApplicationExecutionWasStarted());
        $this->subscriber->onMutationAnalysisWasStarted(new MutationAnalysisWasStarted());
        $this->subscriber->onMutationGenerationWasStarted(new MutationGenerationWasStarted(1));
        $this->subscriber->onAstProcessingWasStarted(new AstProcessingWasStarted($sourceFileId, $sourceFilePath));
        $this->subscriber->onApplicationExecutionWasFinished(new ApplicationExecutionWasFinished());

        $run = $this->span(InfectionSpanName::RUN);
        $mutationAnalysis = $this->span(InfectionSpanName::MUTATION_ANALYSIS);
        $mutationGeneration = $this->span(InfectionSpanName::MUTATION_GENERATION);
        $sourceFile = $this->span(InfectionSpanName::SOURCE_FILE);
        $astProcessing = $this->span(InfectionSpanName::AST_PROCESSING);

        $this->assertSame($run->getSpanId(), $mutationAnalysis->getParentSpanId());
        $this->assertSame($mutationAnalysis->getSpanId(), $mutationGeneration->getParentSpanId());
        $this->assertSame($mutationGeneration->getSpanId(), $sourceFile->getParentSpanId());
        $this->assertSame($sourceFile->getSpanId(), $astProcessing->getParentSpanId());
        $this->assertTrue($astProcessing->hasEnded());
    }

    private function span(string $name): SpanDataInterface
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        $this->fail(sprintf('Span "%s" was not exported.', $name));
    }
}
