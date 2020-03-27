<?php

declare(strict_types=1);

namespace Infection\Tests\Fixtures;

use Infection\Mutation\Mutation;
use Infection\Mutation\MutationAttributeKeys;
use Infection\PhpParser\MutatedNode;
use PhpParser\Node;

class SimpleMutation extends Mutation
{
    private $originalFileAst;
    private $mutatedNode;
    private $attributes;
    private $mutatedNodeClass;

    /**
     * @param Node[]       $originalFileAst
     * @param class-string $mutatorName
     * @param array<string|int|float> $attributes
     * @param class-string      $mutatedNodeClass
     */
    public function __construct(
        array $originalFileAst,
        string $mutatorName,
        MutatedNode $mutatedNode,
        array $attributes,
        string $mutatedNodeClass
    ) {
        parent::__construct(
            '/path/to/Foo.php',
            $mutatorName,
            (int) $attributes[MutationAttributeKeys::START_LINE],
            [],
            'hash',
            '/path/to/MutatedFoo.php',
            'mutatedCode',
            'diff'
        );

        $this->originalFileAst = $originalFileAst;
        $this->mutatedNode = $mutatedNode;
        $this->attributes = $attributes;
        $this->mutatedNodeClass = $mutatedNodeClass;
    }

    /**
     * @return Node[]
     */
    public function getOriginalFileAst(): array
    {
        return $this->originalFileAst;
    }

    public function getMutatedNode(): MutatedNode
    {
        return $this->mutatedNode;
    }

    /**
     * @return array<string|int|float>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getMutatedNodeClass(): string
    {
        return $this->mutatedNodeClass;
    }
}
