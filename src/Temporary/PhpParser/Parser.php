<?php

declare(strict_types=1);

namespace Infection\Temporary\PhpParser;

use function array_map;
use Infection\Mutant\Exception\ParserException;
use Infection\Mutant\MutantCreator;
use Infection\Temporary\Mutant;
use Infection\TestFramework\Coverage\CodeCoverageData;
use Infection\Traverser\PriorityNodeTraverser;
use Infection\Visitor\FullyQualifiedClassNameVisitor;
use Infection\Visitor\MutationsCollectorVisitor;
use Infection\Visitor\NotMutableIgnoreVisitor;
use Infection\Visitor\ParentConnectorVisitor;
use Infection\Visitor\ReflectionVisitor;
use PhpParser\Node;
use PhpParser\Parser as PhpParser;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @internal
 */
final class Parser
{
    private $phpParser;
    private $mutators;
    private $coverageData;
    private $onlyCovered;
    private $extraNodeVisitors;
    private $mutantCreator;

    public function __construct(
        PhpParser $phpParser,
        array $mutators,
        CodeCoverageData $coverageData,
        bool $onlyCovered,
        array $extraNodeVisitors,
        MutantCreator $mutantCreator
    )
    {
        $this->phpParser = $phpParser;
        $this->mutators = $mutators;
        $this->coverageData = $coverageData;
        $this->onlyCovered = $onlyCovered;
        $this->extraNodeVisitors = $extraNodeVisitors;
        $this->mutantCreator = $mutantCreator;
    }

    /**
     * @return Mutant[]
     */
    public function parse(SplFileInfo $fileInfo): array
    {
        try {
            /** @var Node[] $initialStatements */
            $initialStatements = $this->phpParser->parse($fileInfo->getContents());
        } catch (\Throwable $t) {
            throw ParserException::fromInvalidFile($fileInfo, $t);
        }

        $traverser = new PriorityNodeTraverser();
        $filePath = $fileInfo->getRealPath();

        $mutationsCollectorVisitor = new MutationsCollectorVisitor(
            $this->mutators,
            $filePath,
            $initialStatements,
            $this->coverageData,
            $this->onlyCovered
        );

        $traverser->addVisitor(new NotMutableIgnoreVisitor(), 50);
        $traverser->addVisitor(new ParentConnectorVisitor(), 40);
        $traverser->addVisitor(new FullyQualifiedClassNameVisitor(), 30);
        $traverser->addVisitor(new ReflectionVisitor(), 20);
        $traverser->addVisitor($mutationsCollectorVisitor, 10);

        foreach ($this->extraNodeVisitors as $priority => $visitor) {
            $traverser->addVisitor($visitor, $priority);
        }

        $traverser->traverse($initialStatements);

        return array_map(
            function ($mutantNode) {
                return $this->mutantCreator->create($mutantNode, $this->coverageData);
            },
            $mutationsCollectorVisitor->getMutations()
        );
    }
}