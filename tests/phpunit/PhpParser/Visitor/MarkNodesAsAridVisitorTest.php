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

namespace Infection\Tests\PhpParser\Visitor;

use Infection\PhpParser\Visitor\MarkNodesAsAridVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkNodesAsAridVisitor::class)]
final class MarkNodesAsAridVisitorTest extends TestCase
{
    #[DataProvider('callProvider')]
    public function test_it_marks_logging_calls_and_their_descendants_as_arid(Node\Expr\CallLike $call, bool $expected): void
    {
        $argument = $call->getArgs()[0]->value;

        $visitor = new MarkNodesAsAridVisitor();
        $traverser = new NodeTraverser(new ParentConnectingVisitor(), $visitor);
        $traverser->traverse([new Node\Stmt\Expression($call)]);

        $this->assertSame($expected, MarkNodesAsAridVisitor::isArid($call));
        $this->assertSame($expected, MarkNodesAsAridVisitor::isArid($argument));
        $this->assertSame($expected, MarkNodesAsAridVisitor::attributesAreArid($argument->getAttributes()));
    }

    public static function callProvider(): iterable
    {
        yield 'error_log function' => [
            new Node\Expr\FuncCall(new Node\Name('ERROR_LOG'), [new Node\Arg(new Node\Scalar\String_('message'))]),
            true,
        ];

        yield 'PSR logger method' => [
            new Node\Expr\MethodCall(new Node\Expr\Variable('logger'), 'warning', [new Node\Arg(new Node\Scalar\String_('message'))]),
            true,
        ];

        yield 'PSR logger nullsafe method' => [
            new Node\Expr\NullsafeMethodCall(new Node\Expr\Variable('logger'), 'info', [new Node\Arg(new Node\Scalar\String_('message'))]),
            true,
        ];

        yield 'PSR logger static method' => [
            new Node\Expr\StaticCall(new Node\Name('Logger'), 'debug', [new Node\Arg(new Node\Scalar\String_('message'))]),
            true,
        ];

        yield 'unrelated method with a literal name' => [
            new Node\Expr\MethodCall(new Node\Expr\Variable('service'), 'execute', [new Node\Arg(new Node\Scalar\String_('message'))]),
            false,
        ];

        yield 'dynamic method name' => [
            new Node\Expr\MethodCall(new Node\Expr\Variable('logger'), new Node\Expr\Variable('method'), [new Node\Arg(new Node\Scalar\String_('message'))]),
            false,
        ];
    }
}
