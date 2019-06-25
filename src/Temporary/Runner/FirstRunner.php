<?php

declare(strict_types=1);

namespace Infection\Temporary\Runner;

use Infection\EventDispatcher\EventDispatcherInterface;
use Infection\Events\MutantCreated;
use Infection\Events\MutantsCreatingFinished;
use Infection\Events\MutantsCreatingStarted;
use Infection\Events\MutationGeneratingFinished;
use Infection\Events\MutationGeneratingStarted;
use Infection\Mutant\MutantCreator;
use Infection\Temporary\Configuration;
use Infection\Temporary\FileCollector;
use Infection\Temporary\Mutant;
use Infection\Temporary\MutantGenerator\CoverageBasedMutantGenerator;
use Infection\Temporary\MutantProcess;
use Infection\Temporary\PhpParser\Parser;
use Infection\Temporary\ProcessBuilder;
use Infection\Temporary\ProcessExecutor;
use Infection\TestFramework\Coverage\CodeCoverageData;
use PhpParser\Parser as PhpParser;
use function array_map;
use function count;

/**
 * @internal
 */
final class FirstRunner implements Runner
{
    private $fileCollector;
    private $config;
    private $coverageData;
    private $eventDispatcher;
    private $phpParser;
    private $extraNodeVisitors;
    private $mutators;
    private $mutantCreator;
    private $processBuilder;
    private $processExecutor;

    public function __construct(
        FileCollector $fileCollector,
        Configuration $config,
        CodeCoverageData $coverageData,
        EventDispatcherInterface $eventDispatcher,
        PhpParser $phpParser,
        array $mutators,
        array $extraNodeVisitors,
        MutantCreator $mutantCreator,
        ProcessBuilder $processBuilder,
        ProcessExecutor $processExecutor
    )
    {
        $this->fileCollector = $fileCollector;
        $this->config = $config;
        $this->coverageData = $coverageData;
        $this->eventDispatcher = $eventDispatcher;
        $this->phpParser = $phpParser;
        $this->mutators = $mutators;
        $this->extraNodeVisitors = $extraNodeVisitors;
        $this->mutantCreator = $mutantCreator;
        $this->processBuilder = $processBuilder;
        $this->processExecutor = $processExecutor;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): Result
    {
        $sourceFiles = $this->fileCollector->collectFiles(
            $this->config->getSourceDirectories(),
            $this->config->getExcludeDirectories(),
            $this->config->getFilter()
        );

        // TODO: the file count may be wrong... It is the source files count but not the mutated
        // files count
        $this->eventDispatcher->dispatch(new MutationGeneratingStarted(count($sourceFiles)));

        $mutants = (new CoverageBasedMutantGenerator(
            new Parser(
                $this->phpParser,
                $this->mutators,
                $this->coverageData,
                $this->config->getOnlyCovered(),
                $this->extraNodeVisitors,
                $this->mutantCreator
            ),
            $sourceFiles,
            $this->coverageData,
            $this->config->getOnlyCovered()
        ))->generate();

        $this->eventDispatcher->dispatch(new MutationGeneratingFinished());

        $this->eventDispatcher->dispatch(new MutantsCreatingStarted(count($mutants)));

        $mutantProcesses = array_map(
            function (Mutant $mutant): MutantProcess {
                $process = $this->processBuilder->create($mutant);

                $this->eventDispatcher->dispatch(new MutantCreated());

                return $process;
            },
            $mutants
        );

        $this->eventDispatcher->dispatch(new MutantsCreatingFinished());

        $this->processExecutor->execute($mutantProcesses);

        return new Result();
    }
}