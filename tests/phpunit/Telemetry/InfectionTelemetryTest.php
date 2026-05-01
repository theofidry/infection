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

namespace Infection\Tests\Telemetry;

use Infection\Configuration\Entry\TelemetryEntry;
use Infection\Telemetry\InfectionTelemetry;
use Infection\Telemetry\OpenTelemetryFactory;
use Infection\Telemetry\SpanLink;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InfectionTelemetry::class)]
#[CoversClass(OpenTelemetryFactory::class)]
final class InfectionTelemetryTest extends TestCase
{
    public function test_noop_telemetry_is_disabled_and_accepts_operations(): void
    {
        $telemetry = InfectionTelemetry::noop();

        $rootSpan = $telemetry->startRootSpan('infection.run');
        $childSpan = $telemetry->startChildSpan($rootSpan, 'source_collection');

        $telemetry->recordException($childSpan, new RuntimeException('Noop'));
        $telemetry->end($childSpan);
        $telemetry->end($rootSpan);

        $this->assertFalse($telemetry->isEnabled());
    }

    public function test_it_creates_and_flushes_configured_spans(): void
    {
        $exporter = new InMemoryExporter();
        $telemetry = (new OpenTelemetryFactory())->create(
            self::telemetryConfig(),
            $exporter,
            '1.2.3',
        );

        $rootSpan = $telemetry->startRootSpan('infection.run', ['infection.thread_count' => 2]);
        $childSpan = $telemetry->startChildSpan($rootSpan, 'source_collection', ['infection.source_file.count' => 1]);

        $telemetry->end($childSpan, ['infection.source_file.collected_count' => 1]);
        $telemetry->end($rootSpan);

        $this->assertTrue($telemetry->forceFlush());

        $spans = $exporter->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('source_collection', $spans[0]->getName());
        $this->assertSame('infection.run', $spans[1]->getName());
        $this->assertSame($spans[1]->getSpanId(), $spans[0]->getParentSpanId());
        $this->assertSame(2, $spans[1]->getAttributes()->get('infection.thread_count'));
        $this->assertSame(1, $spans[0]->getAttributes()->get('infection.source_file.collected_count'));
        $this->assertSame('infection', $spans[1]->getResource()->getAttributes()->get(ResourceAttributes::SERVICE_NAME));
        $this->assertSame('1.2.3', $spans[1]->getResource()->getAttributes()->get(ResourceAttributes::SERVICE_VERSION));
    }

    public function test_it_records_exceptions_and_span_links(): void
    {
        $exporter = new InMemoryExporter();
        $telemetry = (new OpenTelemetryFactory())->create(self::telemetryConfig(), $exporter);

        $rootSpan = $telemetry->startRootSpan('infection.run');
        $linkedSpan = $telemetry->startChildSpan($rootSpan, 'source_file');
        $childSpan = $telemetry->startChildSpan(
            $rootSpan,
            'mutation_evaluation',
            links: [new SpanLink($linkedSpan, ['infection.link.reason' => 'source_file'])],
        );

        $telemetry->recordException($childSpan, new RuntimeException('Broken'));
        $telemetry->end($childSpan);
        $telemetry->end($linkedSpan);
        $telemetry->end($rootSpan);
        $telemetry->forceFlush();

        $spans = $exporter->getSpans();

        $this->assertSame('mutation_evaluation', $spans[0]->getName());
        $this->assertSame('Error', $spans[0]->getStatus()->getCode());
        $this->assertCount(1, $spans[0]->getEvents());
        $this->assertCount(1, $spans[0]->getLinks());
        $this->assertSame('source_file', $spans[1]->getName());
        $this->assertSame($spans[1]->getSpanId(), $spans[0]->getLinks()[0]->getSpanContext()->getSpanId());
    }

    private static function telemetryConfig(): TelemetryEntry
    {
        return TelemetryEntry::createDefault();
    }
}
