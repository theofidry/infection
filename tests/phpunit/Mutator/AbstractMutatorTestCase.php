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

namespace Infection\Tests\Mutator;

use function array_shift;
use function count;
use function escapeshellarg;
use function exec;
use function get_class;
use Infection\Console\InfectionContainer;
use Infection\Mutator\Util\Mutator;
use Infection\Mutator\Util\MutatorConfig;
use Infection\Tests\Fixtures\SimpleMutation;
use Infection\Tests\Fixtures\SimpleMutationsCollectorVisitor;
use Infection\Tests\Fixtures\SimpleMutatorVisitor;
use Infection\Visitor\CloneVisitor;
use Infection\Visitor\FullyQualifiedClassNameVisitor;
use Infection\Visitor\NotMutableIgnoreVisitor;
use Infection\Visitor\ParentConnectorVisitor;
use Infection\Visitor\ReflectionVisitor;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\PrettyPrinterAbstract;
use PHPUnit\Framework\TestCase;
use function rtrim;
use function Safe\sprintf;

abstract class AbstractMutatorTestCase extends TestCase
{
    /**
     * @var Mutator
     */
    protected $mutator;

    /**
     * @var Parser|null
     */
    private static $parser;

    /**
     * @var PrettyPrinterAbstract|null
     */
    private static $printer;

    protected function setUp(): void
    {
        $this->mutator = $this->createMutator();
    }

    final public function doTest(string $inputCode, $expectedCode = null, array $settings = []): void
    {
        $expectedCodeSamples = (array) $expectedCode;

        $inputCode = rtrim($inputCode, "\n");

        if ($inputCode === $expectedCode) {
            $this->fail('Input code cant be the same as mutated code');
        }

        $mutants = $this->mutate($inputCode, $settings);

        $this->assertCount(
            count($mutants),
            $expectedCodeSamples,
            sprintf(
                'Failed asserting that the number of code samples (%d) equals the number of mutants (%d) created by the mutator.',
                count($expectedCodeSamples),
                count($mutants)
            )
        );

        if ($expectedCode === null) {
            return;
        }

        foreach ($mutants as $realMutatedCode) {
            $expectedCodeSample = array_shift($expectedCodeSamples);

            if ($expectedCodeSample === null) {
                $this->fail('The number of expected mutated code samples must equal the number of generated Mutants by mutator.');
            }

            $expectedCodeSample = rtrim($expectedCodeSample, "\n");

            $this->assertSame($expectedCodeSample, $realMutatedCode);
            $this->assertSyntaxIsValid($realMutatedCode);
        }
    }

    final protected function createMutator(array $settings = []): Mutator
    {
        $class = get_class($this);
        $mutator = substr(str_replace('\Tests', '', $class), 0, -4);

        return new $mutator(new MutatorConfig($settings));
    }

    /**
     * @return string[]
     */
    final protected function mutate(string $code, array $settings = []): array
    {
        $mutations = $this->getMutationsFromCode($code, $settings);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloneVisitor());

        $mutants = [];

        foreach ($mutations as $mutation) {
            $mutatorVisitor = new SimpleMutatorVisitor($mutation);

            $traverser->addVisitor($mutatorVisitor);

            $mutatedStatements = $traverser->traverse($mutation->getOriginalFileAst());

            $mutants[] = $this->getPrinter()->prettyPrintFile($mutatedStatements);
        }

        return $mutants;
    }

    private function getParser(): Parser
    {
        if (null === self::$parser) {
            self::$parser = InfectionContainer::create()[Parser::class];
        }

        return self::$parser;
    }

    private function getPrinter(): PrettyPrinterAbstract
    {
        if (null === self::$printer) {
            self::$printer = new Standard();
        }

        return self::$printer;
    }

    /**
     * @return SimpleMutation[]
     */
    private function getMutationsFromCode(string $code, array $settings): array
    {
        $nodes = $this->getParser()->parse($code);

        $traverser = new NodeTraverser();

        $mutationsCollectorVisitor = new SimpleMutationsCollectorVisitor(
            $this->createMutator($settings),
            $nodes
        );

        $traverser->addVisitor(new NotMutableIgnoreVisitor());
        $traverser->addVisitor(new ParentConnectorVisitor());
        $traverser->addVisitor(new FullyQualifiedClassNameVisitor());
        $traverser->addVisitor(new ReflectionVisitor());
        $traverser->addVisitor($mutationsCollectorVisitor);

        $traverser->traverse($nodes);

        return $mutationsCollectorVisitor->getMutations();
    }

    private function assertSyntaxIsValid(string $realMutatedCode): void
    {
        exec(
            sprintf('echo %s | php -l', escapeshellarg($realMutatedCode)),
            $output,
            $returnCode
        );

        $this->assertSame(
            0,
            $returnCode,
            sprintf(
                'Mutator "%s" produces invalid code',
                $this->createMutator()::getName()
            )
        );
    }
}
