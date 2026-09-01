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

namespace Infection\Tests\PhpParser\Visitor\ExcludeNonSelectedSourceNodesVisitor;

use Infection\Configuration\SourceSymbolSelector;
use Infection\PhpParser\Visitor\ExcludeNonSelectedSourceNodesVisitor;
use Infection\PhpParser\Visitor\LabelNodesAsEligibleVisitor;
use Infection\PhpParser\Visitor\NameResolverFactory;
use Infection\Source\Matcher\SourceSymbolMatcher;
use Infection\Tests\PhpParser\Visitor\VisitorTestCase\VisitorTestCase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ExcludeNonSelectedSourceNodesVisitor::class)]
final class ExcludeNonSelectedSourceNodesVisitorTest extends VisitorTestCase
{
    public function test_it_excludes_nodes_outside_the_selected_method(): void
    {
        $nodes = $this->parse(<<<'PHP'
            <?php
            namespace App;
            final class Mailer {
                public function send(): int { return 1; }
                public function receive(): int { return 2; }
            }
            PHP);

        (new NodeTraverser(
            new LabelNodesAsEligibleVisitor(),
            NameResolverFactory::create(),
            new ExcludeNonSelectedSourceNodesVisitor(
                new SourceSymbolMatcher([new SourceSymbolSelector('Mailer', 'send', null)]),
            ),
        ))->traverse($nodes);

        $methods = (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\ClassMethod::class);

        $this->assertCount(2, $methods);
        $this->assertTrue(LabelNodesAsEligibleVisitor::isEligible($methods[0]));
        $this->assertFalse(LabelNodesAsEligibleVisitor::isEligible($methods[1]));
    }

    public function test_a_short_class_name_selects_every_matching_declaration(): void
    {
        $nodes = $this->parse(<<<'PHP'
            <?php
            namespace App { final class Differ { public function diff(): int { return 1; } } }
            namespace Vendor { final class Differ { public function diff(): int { return 2; } } }
            PHP);

        (new NodeTraverser(
            new LabelNodesAsEligibleVisitor(),
            NameResolverFactory::create(),
            new ExcludeNonSelectedSourceNodesVisitor(
                new SourceSymbolMatcher([new SourceSymbolSelector('Differ', 'diff', null)]),
            ),
        ))->traverse($nodes);

        $methods = (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\ClassMethod::class);

        $this->assertCount(2, $methods);
        $this->assertTrue(LabelNodesAsEligibleVisitor::isEligible($methods[0]));
        $this->assertTrue(LabelNodesAsEligibleVisitor::isEligible($methods[1]));
    }

    public function test_multiple_selectors_are_combined_as_a_union(): void
    {
        $nodes = $this->parse(<<<'PHP'
            <?php
            namespace App;
            final class Differ {
                public function diff(): int { return 1; }
                public function diffToArray(): array { return []; }
                public function other(): int { return 2; }
            }
            PHP);

        (new NodeTraverser(
            new LabelNodesAsEligibleVisitor(),
            NameResolverFactory::create(),
            new ExcludeNonSelectedSourceNodesVisitor(
                new SourceSymbolMatcher([
                    new SourceSymbolSelector('Differ', 'diff', null),
                    new SourceSymbolSelector('App\\Differ', 'diffToArray', null),
                ]),
            ),
        ))->traverse($nodes);

        $methods = (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\ClassMethod::class);

        $this->assertCount(3, $methods);
        $this->assertTrue(LabelNodesAsEligibleVisitor::isEligible($methods[0]));
        $this->assertTrue(LabelNodesAsEligibleVisitor::isEligible($methods[1]));
        $this->assertFalse(LabelNodesAsEligibleVisitor::isEligible($methods[2]));
    }

    public function test_an_anonymous_class_does_not_inherit_the_enclosing_symbol_context(): void
    {
        $nodes = $this->parse(<<<'PHP'
            <?php
            namespace App;
            final class Differ {
                public function diff(): int {
                    $value = new class { public function nested(): int { return 1; } };

                    return 2;
                }
            }
            PHP);

        (new NodeTraverser(
            new LabelNodesAsEligibleVisitor(),
            NameResolverFactory::create(),
            new ExcludeNonSelectedSourceNodesVisitor(
                new SourceSymbolMatcher([new SourceSymbolSelector('Differ', 'diff', null)]),
            ),
        ))->traverse($nodes);

        $returns = (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\Return_::class);

        $this->assertCount(2, $returns);
        $this->assertFalse(LabelNodesAsEligibleVisitor::isEligible($returns[0]));
        $this->assertTrue(LabelNodesAsEligibleVisitor::isEligible($returns[1]));
    }
}
