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

namespace Infection\Telemetry\Subscriber;

use function array_diff;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function count;
use Infection\Event\Events\Application\ApplicationExecutionWasFinished;
use Infection\Event\Events\Application\ApplicationExecutionWasFinishedSubscriber;
use Infection\Event\Events\Application\ApplicationExecutionWasStarted;
use Infection\Event\Events\Application\ApplicationExecutionWasStartedSubscriber;
use Infection\Event\Events\ArtefactCollection\ArtefactCollectionWasFinished;
use Infection\Event\Events\ArtefactCollection\ArtefactCollectionWasFinishedSubscriber;
use Infection\Event\Events\ArtefactCollection\ArtefactCollectionWasStarted;
use Infection\Event\Events\ArtefactCollection\ArtefactCollectionWasStartedSubscriber;
use Infection\Event\Events\ArtefactCollection\InitialStaticAnalysis\InitialStaticAnalysisRunWasFinished;
use Infection\Event\Events\ArtefactCollection\InitialStaticAnalysis\InitialStaticAnalysisRunWasFinishedSubscriber;
use Infection\Event\Events\ArtefactCollection\InitialStaticAnalysis\InitialStaticAnalysisRunWasStarted;
use Infection\Event\Events\ArtefactCollection\InitialStaticAnalysis\InitialStaticAnalysisRunWasStartedSubscriber;
use Infection\Event\Events\ArtefactCollection\InitialTestExecution\InitialTestSuiteWasFinished;
use Infection\Event\Events\ArtefactCollection\InitialTestExecution\InitialTestSuiteWasFinishedSubscriber;
use Infection\Event\Events\ArtefactCollection\InitialTestExecution\InitialTestSuiteWasStarted;
use Infection\Event\Events\ArtefactCollection\InitialTestExecution\InitialTestSuiteWasStartedSubscriber;
use Infection\Event\Events\Ast\AstEnrichment\AstEnrichmentWasFinished;
use Infection\Event\Events\Ast\AstEnrichment\AstEnrichmentWasFinishedSubscriber;
use Infection\Event\Events\Ast\AstEnrichment\AstEnrichmentWasStarted;
use Infection\Event\Events\Ast\AstEnrichment\AstEnrichmentWasStartedSubscriber;
use Infection\Event\Events\Ast\AstParsing\AstParsingWasFinished;
use Infection\Event\Events\Ast\AstParsing\AstParsingWasFinishedSubscriber;
use Infection\Event\Events\Ast\AstParsing\AstParsingWasStarted;
use Infection\Event\Events\Ast\AstParsing\AstParsingWasStartedSubscriber;
use Infection\Event\Events\Ast\AstProcessingWasFinished;
use Infection\Event\Events\Ast\AstProcessingWasFinishedSubscriber;
use Infection\Event\Events\Ast\AstProcessingWasStarted;
use Infection\Event\Events\Ast\AstProcessingWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationAnalysisWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationAnalysisWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationAnalysisWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationAnalysisWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantEvaluation\MutantEvaluationWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantEvaluation\MutantEvaluationWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantEvaluation\MutantEvaluationWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantEvaluation\MutantEvaluationWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantMaterialisation\MutantMaterialisationWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantMaterialisation\MutantMaterialisationWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantMaterialisation\MutantMaterialisationWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantMaterialisation\MutantMaterialisationWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantProcessWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutantProcessWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationEvaluationForMutationWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationEvaluationForMutationWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationHeuristicsWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationHeuristicsWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationHeuristicsWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationEvaluation\MutationHeuristicsWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationForFileWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationForFileWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationForFileWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationForFileWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationGeneration\MutationGenerationWasStartedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationTestingWasFinished;
use Infection\Event\Events\MutationAnalysis\MutationTestingWasFinishedSubscriber;
use Infection\Event\Events\MutationAnalysis\MutationTestingWasStarted;
use Infection\Event\Events\MutationAnalysis\MutationTestingWasStartedSubscriber;
use Infection\Event\Events\Reporting\ReportingWasFinished;
use Infection\Event\Events\Reporting\ReportingWasFinishedSubscriber;
use Infection\Event\Events\Reporting\ReportingWasStarted;
use Infection\Event\Events\Reporting\ReportingWasStartedSubscriber;
use Infection\Event\Events\SourceCollection\SourceCollectionWasFinished;
use Infection\Event\Events\SourceCollection\SourceCollectionWasFinishedSubscriber;
use Infection\Event\Events\SourceCollection\SourceCollectionWasStarted;
use Infection\Event\Events\SourceCollection\SourceCollectionWasStartedSubscriber;
use Infection\Telemetry\InfectionSpanAttribute;
use Infection\Telemetry\InfectionSpanName;
use Infection\Telemetry\InfectionTelemetry;
use Infection\Telemetry\SpanHandle;
use Infection\Telemetry\SpanLink;
use function spl_object_id;

/**
 * @internal
 */
final class TelemetrySubscriber implements ApplicationExecutionWasFinishedSubscriber, ApplicationExecutionWasStartedSubscriber, ArtefactCollectionWasFinishedSubscriber, ArtefactCollectionWasStartedSubscriber, AstEnrichmentWasFinishedSubscriber, AstEnrichmentWasStartedSubscriber, AstParsingWasFinishedSubscriber, AstParsingWasStartedSubscriber, AstProcessingWasFinishedSubscriber, AstProcessingWasStartedSubscriber, InitialStaticAnalysisRunWasFinishedSubscriber, InitialStaticAnalysisRunWasStartedSubscriber, InitialTestSuiteWasFinishedSubscriber, InitialTestSuiteWasStartedSubscriber, MutantEvaluationWasFinishedSubscriber, MutantEvaluationWasStartedSubscriber, MutantMaterialisationWasFinishedSubscriber, MutantMaterialisationWasStartedSubscriber, MutantProcessWasFinishedSubscriber, MutationAnalysisWasFinishedSubscriber, MutationAnalysisWasStartedSubscriber, MutationEvaluationForMutationWasStartedSubscriber, MutationGenerationForFileWasFinishedSubscriber, MutationGenerationForFileWasStartedSubscriber, MutationGenerationWasFinishedSubscriber, MutationGenerationWasStartedSubscriber, MutationHeuristicsWasFinishedSubscriber, MutationHeuristicsWasStartedSubscriber, MutationTestingWasFinishedSubscriber, MutationTestingWasStartedSubscriber, ReportingWasFinishedSubscriber, ReportingWasStartedSubscriber, SourceCollectionWasFinishedSubscriber, SourceCollectionWasStartedSubscriber
{
    private SpanHandle $runSpan;

    private SpanHandle $sourceCollectionSpan;

    private SpanHandle $artefactCollectionSpan;

    private SpanHandle $initialTestSuiteSpan;

    private SpanHandle $initialStaticAnalysisRunSpan;

    private SpanHandle $mutationAnalysisSpan;

    private SpanHandle $mutationGenerationSpan;

    private SpanHandle $mutationEvaluationSpan;

    private SpanHandle $reportingSpan;

    private bool $runSpanStarted = false;

    /** @var array<string, SpanHandle> key=sourceFileId */
    private array $sourceFileSpans = [];

    /** @var array<string, SpanHandle> key=sourceFileId */
    private array $astProcessingSpans = [];

    /** @var array<string, SpanHandle> key=sourceFileId */
    private array $astParsingSpans = [];

    /** @var array<string, SpanHandle> key=sourceFileId */
    private array $astEnrichmentSpans = [];

    /** @var array<string, SpanHandle> key=sourceFileId */
    private array $sourceFileMutationGenerationSpans = [];

    /** @var array<string, SpanHandle> key=mutationId */
    private array $individualMutationEvaluationSpans = [];

    /** @var array<string, SpanHandle> key=processSpanId */
    private array $individualMutantEvaluationSpans = [];

    /** @var array<string, array<string, SpanHandle>> key1=mutationId, key2=heuristicIdName */
    private array $mutationHeuristicsSpans = [];

    /** @var array<string, SpanHandle> key=mutationId */
    private array $mutationMaterialisationSpans = [];

    /** @var array<string, array<string, true>> */
    private array $finishedMutationHashesBySourceFileId = [];

    /** @var array<string, array<string, true>> */
    private array $remainingMutationHashesBySourceFileId = [];

    /** @var array<string, string> */
    private array $sourceFileIdByMutationHash = [];

    public function __construct(
        private readonly InfectionTelemetry $telemetry,
    ) {
        $this->runSpan = SpanHandle::invalid();
        $this->sourceCollectionSpan = SpanHandle::invalid();
        $this->artefactCollectionSpan = SpanHandle::invalid();
        $this->initialTestSuiteSpan = SpanHandle::invalid();
        $this->initialStaticAnalysisRunSpan = SpanHandle::invalid();
        $this->mutationAnalysisSpan = SpanHandle::invalid();
        $this->mutationGenerationSpan = SpanHandle::invalid();
        $this->mutationEvaluationSpan = SpanHandle::invalid();
        $this->reportingSpan = SpanHandle::invalid();
    }

    public function onApplicationExecutionWasStarted(ApplicationExecutionWasStarted $event): void
    {
        $this->ensureRunSpan();
    }

    public function onApplicationExecutionWasFinished(ApplicationExecutionWasFinished $event): void
    {
        $this->endOpenSpans();

        if ($this->runSpanStarted) {
            $this->telemetry->end($this->runSpan);
            $this->runSpanStarted = false;
        }

        $this->telemetry->shutdown();
    }

    public function onSourceCollectionWasStarted(SourceCollectionWasStarted $event): void
    {
        $this->sourceCollectionSpan = $this->startRunChild(InfectionSpanName::SOURCE_COLLECTION);
    }

    public function onSourceCollectionWasFinished(SourceCollectionWasFinished $event): void
    {
        $this->telemetry->end(
            $this->sourceCollectionSpan,
            [InfectionSpanAttribute::SOURCE_COUNT => $event->sourcesCount],
        );
    }

    public function onArtefactCollectionWasStarted(ArtefactCollectionWasStarted $event): void
    {
        $this->artefactCollectionSpan = $this->startRunChild(InfectionSpanName::ARTEFACT_COLLECTION);
    }

    public function onArtefactCollectionWasFinished(ArtefactCollectionWasFinished $event): void
    {
        $this->telemetry->end($this->artefactCollectionSpan);
    }

    public function onInitialTestSuiteWasStarted(InitialTestSuiteWasStarted $event): void
    {
        $this->initialTestSuiteSpan = $this->telemetry->startChildSpan(
            $this->artefactCollectionSpan,
            InfectionSpanName::INITIAL_TESTS,
            [
                InfectionSpanAttribute::TEST_FRAMEWORK_NAME => $event->testFrameworkName,
                InfectionSpanAttribute::TEST_FRAMEWORK_VERSION => $event->testFrameworkVersion,
            ],
        );
    }

    public function onInitialTestSuiteWasFinished(InitialTestSuiteWasFinished $event): void
    {
        $this->telemetry->end($this->initialTestSuiteSpan);
    }

    public function onInitialStaticAnalysisRunWasStarted(InitialStaticAnalysisRunWasStarted $event): void
    {
        $this->initialStaticAnalysisRunSpan = $this->telemetry->startChildSpan(
            $this->artefactCollectionSpan,
            InfectionSpanName::INITIAL_STATIC_ANALYSIS,
        );
    }

    public function onInitialStaticAnalysisRunWasFinished(InitialStaticAnalysisRunWasFinished $event): void
    {
        $this->telemetry->end($this->initialStaticAnalysisRunSpan);
    }

    public function onMutationAnalysisWasStarted(MutationAnalysisWasStarted $event): void
    {
        $this->mutationAnalysisSpan = $this->startRunChild(InfectionSpanName::MUTATION_ANALYSIS);
    }

    public function onMutationAnalysisWasFinished(MutationAnalysisWasFinished $event): void
    {
        $this->telemetry->end($this->mutationAnalysisSpan);
    }

    public function onMutationGenerationWasStarted(MutationGenerationWasStarted $event): void
    {
        $this->mutationGenerationSpan = $this->telemetry->startChildSpan(
            $this->mutationAnalysisSpan,
            InfectionSpanName::MUTATION_GENERATION,
            ['infection.source_file.mutable_count' => $event->mutableFilesCount],
        );
    }

    public function onMutationGenerationWasFinished(MutationGenerationWasFinished $event): void
    {
        $this->telemetry->end($this->mutationGenerationSpan);
    }

    public function onAstProcessingWasStarted(AstProcessingWasStarted $event): void
    {
        $sourceFileId = $event->sourceFileId;
        $sourceFileAttributes = self::sourceFileAttributes($sourceFileId, $event->sourceFilePath);

        $this->sourceFileSpans[$sourceFileId] = $this->telemetry->startChildSpan(
            $this->mutationGenerationSpan,
            InfectionSpanName::SOURCE_FILE,
            $sourceFileAttributes,
        );

        $this->astProcessingSpans[$sourceFileId] = $this->telemetry->startChildSpan(
            $this->sourceFileSpans[$sourceFileId],
            InfectionSpanName::AST_PROCESSING,
            $sourceFileAttributes,
        );
    }

    public function onAstParsingWasStarted(AstParsingWasStarted $event): void
    {
        $this->astParsingSpans[$event->sourceFileId] = $this->telemetry->startChildSpan(
            $this->astProcessingSpans[$event->sourceFileId],
            InfectionSpanName::AST_PARSING,
            self::sourceFileAttributes($event->sourceFileId, $event->sourceFilePath),
        );
    }

    public function onAstParsingWasFinished(AstParsingWasFinished $event): void
    {
        $sourceFileId = $event->sourceFileId;

        $this->telemetry->end($this->astParsingSpans[$sourceFileId]);
        unset($this->astParsingSpans[$sourceFileId]);
    }

    public function onAstEnrichmentWasStarted(AstEnrichmentWasStarted $event): void
    {
        $this->astEnrichmentSpans[$event->sourceFileId] = $this->telemetry->startChildSpan(
            $this->astProcessingSpans[$event->sourceFileId],
            InfectionSpanName::AST_ENRICHMENT,
            self::sourceFileAttributes($event->sourceFileId, $event->sourceFilePath),
        );
    }

    public function onAstEnrichmentWasFinished(AstEnrichmentWasFinished $event): void
    {
        $sourceFileId = $event->sourceFileId;

        $this->telemetry->end($this->astEnrichmentSpans[$sourceFileId]);
        unset($this->astEnrichmentSpans[$sourceFileId]);
    }

    public function onAstProcessingWasFinished(AstProcessingWasFinished $event): void
    {
        $sourceFileId = $event->sourceFileId;

        $this->telemetry->end($this->astProcessingSpans[$sourceFileId]);
        unset($this->astProcessingSpans[$sourceFileId]);
    }

    public function onMutationGenerationForFileWasStarted(MutationGenerationForFileWasStarted $event): void
    {
        $this->sourceFileMutationGenerationSpans[$event->sourceFileId] = $this->telemetry->startChildSpan(
            $this->sourceFileSpans[$event->sourceFileId],
            InfectionSpanName::MUTATION_GENERATION_FOR_SOURCE_FILE,
            self::sourceFileAttributes($event->sourceFileId, $event->sourceRealPath),
        );
    }

    public function onMutationGenerationForFileWasFinished(MutationGenerationForFileWasFinished $event): void
    {
        $this->telemetry->end(
            $this->sourceFileMutationGenerationSpans[$event->sourceFileId],
            [
                InfectionSpanAttribute::MUTATION_IDS => $event->mutationHashes,
                InfectionSpanAttribute::MUTATION_COUNT => count($event->mutationHashes),
            ],
        );
        unset($this->sourceFileMutationGenerationSpans[$event->sourceFileId]);

        $this->registerMutationsForSourceFile($event->sourceFileId, $event->mutationHashes);
        $this->endFileSpanIfAllMutationsAreEvaluated($event->sourceFileId);
    }

    public function onMutationTestingWasStarted(MutationTestingWasStarted $event): void
    {
        $this->mutationEvaluationSpan = $this->telemetry->startChildSpan(
            $this->mutationAnalysisSpan,
            InfectionSpanName::MUTATION_EVALUATION,
            [InfectionSpanAttribute::MUTATION_COUNT => $event->mutationCount],
        );
    }

    public function onMutationTestingWasFinished(MutationTestingWasFinished $event): void
    {
        $this->telemetry->end($this->mutationEvaluationSpan);
    }

    public function onMutationEvaluationForMutationWasStarted(MutationEvaluationForMutationWasStarted $event): void
    {
        $mutation = $event->mutation;
        $mutationId = $mutation->getHash();
        $sourceFileId = $event->sourceFileId;

        $this->sourceFileIdByMutationHash[$mutationId] = $sourceFileId;

        $this->individualMutationEvaluationSpans[$mutationId] = $this->telemetry->startChildSpan(
            $this->mutationEvaluationSpan,
            InfectionSpanName::MUTATION_EVALUATION_FOR_MUTATION,
            [
                InfectionSpanAttribute::SOURCE_FILE_ID => $sourceFileId,
                InfectionSpanAttribute::SOURCE_FILE_PATH => $mutation->getOriginalFilePath(),
                InfectionSpanAttribute::MUTATION_ID => $mutationId,
                InfectionSpanAttribute::MUTATOR_CLASS => $mutation->getMutatorClass(),
                InfectionSpanAttribute::MUTATOR_NAME => $mutation->getMutatorName(),
            ],
            $this->sourceFileLinks($sourceFileId),
        );
    }

    public function onMutationHeuristicsWasStarted(MutationHeuristicsWasStarted $event): void
    {
        $mutationId = $event->mutation->getHash();
        $heuristicId = $event->heuristicId;

        $this->mutationHeuristicsSpans[$mutationId][$heuristicId->name] = $this->telemetry->startChildSpan(
            $this->individualMutationEvaluationSpans[$mutationId],
            InfectionSpanName::HEURISTIC_SUPPRESSION,
            [
                InfectionSpanAttribute::MUTATION_ID => $mutationId,
                InfectionSpanAttribute::HEURISTIC_ID => $heuristicId->name,
                InfectionSpanAttribute::HEURISTIC_NAME => $heuristicId->value,
            ],
        );
    }

    public function onMutationHeuristicsWasFinished(MutationHeuristicsWasFinished $event): void
    {
        $mutationId = $event->mutation->getHash();
        $sourceFileId = $this->sourceFileIdByMutationHash[$mutationId];
        $heuristicName = $event->heuristicId->name;

        $this->telemetry->end($this->mutationHeuristicsSpans[$mutationId][$heuristicName]);
        unset($this->mutationHeuristicsSpans[$mutationId][$heuristicName]);

        if (!$event->escaped) {
            $this->telemetry->end($this->individualMutationEvaluationSpans[$mutationId]);
            unset($this->individualMutationEvaluationSpans[$mutationId]);
            $this->markMutationAsFinished($sourceFileId, $mutationId);
        }

        $this->endFileSpanIfAllMutationsAreEvaluated($sourceFileId);
    }

    public function onMutantMaterialisationWasStarted(MutantMaterialisationWasStarted $event): void
    {
        $mutationId = $event->mutant->getMutation()->getHash();

        $this->mutationMaterialisationSpans[$mutationId] = $this->telemetry->startChildSpan(
            $this->individualMutationEvaluationSpans[$mutationId],
            InfectionSpanName::MUTATION_MATERIALISATION,
            [InfectionSpanAttribute::MUTATION_ID => $mutationId],
        );
    }

    public function onMutantMaterialisationWasFinished(MutantMaterialisationWasFinished $event): void
    {
        $mutationId = $event->mutant->getMutation()->getHash();

        $this->telemetry->end($this->mutationMaterialisationSpans[$mutationId]);
        unset($this->mutationMaterialisationSpans[$mutationId]);
    }

    public function onMutantEvaluationWasStarted(MutantEvaluationWasStarted $event): void
    {
        $mutantProcess = $event->mutantProcessContainer->getCurrent();
        $mutationId = $mutantProcess->getMutant()->getMutation()->getHash();
        $processSpanId = self::processSpanId($mutationId, $mutantProcess);

        $this->individualMutantEvaluationSpans[$processSpanId] = $this->telemetry->startChildSpan(
            $this->individualMutationEvaluationSpans[$mutationId],
            InfectionSpanName::MUTANT_EVALUATION,
            [
                InfectionSpanAttribute::MUTATION_ID => $mutationId,
                InfectionSpanAttribute::TEST_FRAMEWORK_NAME => $mutantProcess->testFrameworkName,
                InfectionSpanAttribute::PROCESS_COMMAND_LINE => $mutantProcess->getProcess()->getCommandLine(),
            ],
        );
    }

    public function onMutantEvaluationWasFinished(MutantEvaluationWasFinished $event): void
    {
        $mutantProcess = $event->mutantProcessContainer->getCurrent();
        $processSpanId = self::processSpanId($mutantProcess->getMutant()->getMutation()->getHash(), $mutantProcess);

        $this->telemetry->end($this->individualMutantEvaluationSpans[$processSpanId]);
        unset($this->individualMutantEvaluationSpans[$processSpanId]);
    }

    public function onMutantProcessWasFinished(MutantProcessWasFinished $event): void
    {
        $mutationId = $event->executionResult->getMutantHash();
        $sourceFileId = $this->sourceFileIdByMutationHash[$mutationId];

        $this->telemetry->end(
            $this->individualMutationEvaluationSpans[$mutationId],
            [
                InfectionSpanAttribute::MUTATION_DIFF => $event->executionResult->getMutantDiff(),
                InfectionSpanAttribute::MUTATION_RESULT => $event->executionResult->getDetectionStatus()->value,
            ],
        );
        unset($this->individualMutationEvaluationSpans[$mutationId]);

        $this->markMutationAsFinished($sourceFileId, $mutationId);
        $this->endFileSpanIfAllMutationsAreEvaluated($sourceFileId);
    }

    public function onReportingWasStarted(ReportingWasStarted $event): void
    {
        $this->reportingSpan = $this->startRunChild(InfectionSpanName::REPORTING);
    }

    public function onReportingWasFinished(ReportingWasFinished $event): void
    {
        $this->telemetry->end($this->reportingSpan);
    }

    private function ensureRunSpan(): void
    {
        if (!$this->runSpanStarted) {
            $this->runSpan = $this->telemetry->startRootSpan(InfectionSpanName::RUN);
            $this->runSpanStarted = true;
        }
    }

    private function startRunChild(string $name): SpanHandle
    {
        $this->ensureRunSpan();

        return $this->telemetry->startChildSpan($this->runSpan, $name);
    }

    private function endOpenSpans(): void
    {
        foreach ($this->individualMutantEvaluationSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->mutationMaterialisationSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->mutationHeuristicsSpans as $spansByHeuristic) {
            foreach ($spansByHeuristic as $span) {
                $this->telemetry->end($span);
            }
        }

        foreach ($this->individualMutationEvaluationSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->sourceFileMutationGenerationSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->astEnrichmentSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->astParsingSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->astProcessingSpans as $span) {
            $this->telemetry->end($span);
        }

        foreach ($this->sourceFileSpans as $span) {
            $this->telemetry->end($span);
        }

        $this->telemetry->end($this->reportingSpan);
        $this->telemetry->end($this->mutationEvaluationSpan);
        $this->telemetry->end($this->mutationGenerationSpan);
        $this->telemetry->end($this->mutationAnalysisSpan);
        $this->telemetry->end($this->initialStaticAnalysisRunSpan);
        $this->telemetry->end($this->initialTestSuiteSpan);
        $this->telemetry->end($this->artefactCollectionSpan);
        $this->telemetry->end($this->sourceCollectionSpan);

        $this->individualMutantEvaluationSpans = [];
        $this->mutationMaterialisationSpans = [];
        $this->mutationHeuristicsSpans = [];
        $this->individualMutationEvaluationSpans = [];
        $this->sourceFileMutationGenerationSpans = [];
        $this->astEnrichmentSpans = [];
        $this->astParsingSpans = [];
        $this->astProcessingSpans = [];
        $this->sourceFileSpans = [];
    }

    /**
     * @param list<string> $mutationHashes
     */
    private function registerMutationsForSourceFile(string $sourceFileId, array $mutationHashes): void
    {
        $finishedMutationHashes = array_keys($this->finishedMutationHashesBySourceFileId[$sourceFileId] ?? []);
        $remainingMutationHashes = array_diff($mutationHashes, $finishedMutationHashes);

        $this->remainingMutationHashesBySourceFileId[$sourceFileId] = array_fill_keys($remainingMutationHashes, true);
    }

    private function markMutationAsFinished(string $sourceFileId, string $mutationHash): void
    {
        $this->finishedMutationHashesBySourceFileId[$sourceFileId][$mutationHash] = true;

        if (array_key_exists($sourceFileId, $this->remainingMutationHashesBySourceFileId)) {
            unset($this->remainingMutationHashesBySourceFileId[$sourceFileId][$mutationHash]);
        }
    }

    private function endFileSpanIfAllMutationsAreEvaluated(string $sourceFileId): void
    {
        if (
            !array_key_exists($sourceFileId, $this->sourceFileSpans)
            || !array_key_exists($sourceFileId, $this->remainingMutationHashesBySourceFileId)
            || count($this->remainingMutationHashesBySourceFileId[$sourceFileId]) !== 0
        ) {
            return;
        }

        $this->telemetry->end($this->sourceFileSpans[$sourceFileId]);

        $mutationHashes = array_keys($this->finishedMutationHashesBySourceFileId[$sourceFileId] ?? []);

        foreach ($mutationHashes as $mutationHash) {
            unset($this->sourceFileIdByMutationHash[$mutationHash]);
        }

        unset(
            $this->sourceFileSpans[$sourceFileId],
            $this->remainingMutationHashesBySourceFileId[$sourceFileId],
            $this->finishedMutationHashesBySourceFileId[$sourceFileId],
        );
    }

    /**
     * @return array<string, string>
     */
    private static function sourceFileAttributes(string $sourceFileId, string $sourceFilePath): array
    {
        return [
            InfectionSpanAttribute::SOURCE_FILE_ID => $sourceFileId,
            InfectionSpanAttribute::SOURCE_FILE_PATH => $sourceFilePath,
        ];
    }

    /**
     * @return list<SpanLink>
     */
    private function sourceFileLinks(string $sourceFileId): array
    {
        if (!array_key_exists($sourceFileId, $this->sourceFileSpans)) {
            return [];
        }

        return [
            new SpanLink(
                $this->sourceFileSpans[$sourceFileId],
                [InfectionSpanAttribute::SOURCE_FILE_ID => $sourceFileId],
            ),
        ];
    }

    private static function processSpanId(string $mutationId, object $mutantProcess): string
    {
        return $mutationId . spl_object_id($mutantProcess);
    }
}
