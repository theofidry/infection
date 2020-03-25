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

namespace Infection\Tests\Process\Builder;

use function current;
use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\AbstractTestFramework\TestFrameworkAdapter;
use Infection\Event\MutationProcessWasFinished;
use Infection\Mutation\Mutation;
use Infection\Mutation\MutationCalculatedState;
use Infection\Mutation\MutationExecutionResult;
use Infection\Mutation\MutationExecutionResultFactory;
use Infection\Mutator\ZeroIteration\For_;
use Infection\Process\Builder\MutationProcessFactory;
use Infection\Tests\Fixtures\Event\EventDispatcherCollector;
use Infection\Tests\Mutator\MutatorName;
use const PHP_OS_FAMILY;
use PHPUnit\Framework\TestCase;

final class MutationProcessFactoryTest extends TestCase
{
    public function test_it_creates_a_process_with_timeout(): void
    {
        $hash = '0800f';
        $mutationFilePath = '/path/to/mutation';

        $mutation = new Mutation(
            $originalFilePath = 'path/to/Foo.php',
            MutatorName::getName(For_::class),
            [
                'startLine' => $originalStartingLine = 10,
                'endLine' => 15,
                'startTokenPos' => 0,
                'endTokenPos' => 8,
                'startFilePos' => 2,
                'endFilePos' => 4,
            ],
            $tests = [
                new TestLocation(
                    'FooTest::test_it_can_instantiate',
                    '/path/to/acme/FooTest.php',
                    0.01
                ),
            ],
            static function () use ($hash, $mutationFilePath): MutationCalculatedState {
                return new MutationCalculatedState(
                    $hash,
                    $mutationFilePath,
                    'notCovered#0',
                    <<<'DIFF'
--- Original
+++ New
@@ @@

- echo 'original';
+ echo 'killed#0';

DIFF
                );
            }
        );

        $testFrameworkExtraOptions = '--verbose';

        $testFrameworkAdapterMock = $this->createMock(TestFrameworkAdapter::class);
        $testFrameworkAdapterMock
            ->method('getMutantCommandLine')
            ->with(
                $tests,
                $mutationFilePath,
                $hash,
                $originalFilePath,
                $testFrameworkExtraOptions
            )
            ->willReturn(['/usr/bin/php', 'bin/phpunit', '--filter', '/path/to/acme/FooTest.php'])
        ;

        $eventDispatcher = new EventDispatcherCollector();

        $executionResultMock = $this->createMock(MutationExecutionResult::class);
        $executionResultMock
            ->expects($this->never())
            ->method($this->anything())
        ;

        $resultFactoryMock = $this->createMock(MutationExecutionResultFactory::class);
        $resultFactoryMock
            ->method('createFromProcess')
            ->willReturn($executionResultMock)
        ;

        $factory = new MutationProcessFactory(
            $testFrameworkAdapterMock,
            100,
            $eventDispatcher,
            $resultFactoryMock
        );

        $mutationProcess = $factory->createProcessForMutation($mutation, $testFrameworkExtraOptions);

        $process = $mutationProcess->getProcess();

        $this->assertSame(
            PHP_OS_FAMILY === 'Windows'
                ? '"/usr/bin/php" "bin/phpunit" --filter "/path/to/acme/FooTest.php"'
                : "'/usr/bin/php' 'bin/phpunit' '--filter' '/path/to/acme/FooTest.php'",
            $process->getCommandLine()
        );
        $this->assertSame(100., $process->getTimeout());
        $this->assertFalse($process->isStarted());

        $this->assertSame($mutation, $mutationProcess->getMutation());
        $this->assertFalse($mutationProcess->isTimedOut());

        $this->assertSame([], $eventDispatcher->getEvents());

        $mutationProcess->terminateProcess();

        $eventsAfterCallbackCall = $eventDispatcher->getEvents();

        $this->assertCount(1, $eventsAfterCallbackCall);

        $event = current($eventsAfterCallbackCall);

        $this->assertInstanceOf(MutationProcessWasFinished::class, $event);
        $this->assertSame($executionResultMock, $event->getExecutionResult());
    }
}
