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

namespace Infection\Telemetry;

use OpenTelemetry\SemConv\Attributes\CodeAttributes;

final class InfectionSpanAttribute
{
    public const string SOURCE_COUNT = 'infection.source.count';

    public const string SOURCE_FILE_ID = 'infection.source_file.id';

    public const string SOURCE_FILE_PATH = CodeAttributes::CODE_FILE_PATH;

    public const string MUTATION_ID = 'infection.mutation.id';

    public const string MUTATION_IDS = 'infection.mutation.ids';

    public const string MUTATION_COUNT = 'infection.mutation.count';

    public const string MUTATOR_CLASS = 'infection.mutator.class';

    public const string MUTATOR_NAME = 'infection.mutator.name';

    public const string HEURISTIC_ID = 'infection.heuristic.id';

    public const string HEURISTIC_NAME = 'infection.heuristic.name';

    public const string TEST_FRAMEWORK_NAME = 'infection.test_framework.name';

    public const string TEST_FRAMEWORK_VERSION = 'infection.test_framework.version';

    public const string PROCESS_COMMAND_LINE = 'infection.process.command_line';

    public const string MUTATION_DIFF = 'infection.mutation.diff';

    public const string MUTATION_RESULT = 'infection.mutation.result';

    private function __construct()
    {
    }
}
