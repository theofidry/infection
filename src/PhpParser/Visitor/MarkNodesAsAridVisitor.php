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

namespace Infection\PhpParser\Visitor;

use function in_array;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;

/**
 * Experimental syntactic classifier for mutations in logging calls.
 *
 * @internal
 */
final class MarkNodesAsAridVisitor extends NodeVisitorAbstract
{
    public const string ARID = 'arid';

    private const array LOG_METHOD_NAMES = [
        'alert',
        'critical',
        'debug',
        'emergency',
        'error',
        'info',
        'log',
        'notice',
        'warning',
    ];

    public function enterNode(Node $node): ?int
    {
        $parent = ParentConnector::findParent($node);

        if (($parent !== null && self::isArid($parent)) || self::isLoggingCall($node)) {
            $node->setAttribute(self::ARID, 1);
        }

        return null;
    }

    public static function isArid(Node $node): bool
    {
        return $node->getAttribute(self::ARID, 0) === 1;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function attributesAreArid(array $attributes): bool
    {
        return ($attributes[self::ARID] ?? 0) === 1;
    }

    private static function isLoggingCall(Node $node): bool
    {
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            return $node->name->toLowerString() === 'error_log';
        }

        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall) {
            return false;
        }

        if (!$node->name instanceof Identifier) {
            return false;
        }

        return in_array($node->name->toLowerString(), self::LOG_METHOD_NAMES, true);
    }
}
