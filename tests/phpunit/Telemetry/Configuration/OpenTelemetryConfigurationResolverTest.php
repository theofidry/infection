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

namespace Infection\Tests\Telemetry\Configuration;

use Infection\Configuration\Entry\TelemetryBatchSpanProcessorEntry;
use Infection\Configuration\Entry\TelemetryEntry;
use Infection\Configuration\Entry\TelemetryLimitsEntry;
use Infection\Configuration\Entry\TelemetryOtlpEntry;
use Infection\Configuration\Entry\TelemetryTracesEntry;
use Infection\Telemetry\Configuration\InvalidTelemetryConfiguration;
use Infection\Telemetry\Configuration\OpenTelemetryConfigurationResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenTelemetryConfigurationResolver::class)]
#[CoversClass(InvalidTelemetryConfiguration::class)]
final class OpenTelemetryConfigurationResolverTest extends TestCase
{
    public function test_it_returns_explicit_config_over_environment_config(): void
    {
        $explicitConfig = TelemetryEntry::createDefault();

        $actual = (new OpenTelemetryConfigurationResolver())->resolve(
            $explicitConfig,
            [
                'OTEL_SERVICE_NAME' => 'ignored',
            ],
        );

        $this->assertSame($explicitConfig, $actual);
    }

    public function test_sdk_disabled_environment_variable_overrides_explicit_config(): void
    {
        $actual = (new OpenTelemetryConfigurationResolver())->resolve(
            TelemetryEntry::createDefault(),
            [
                'OTEL_SDK_DISABLED' => 'true',
            ],
        );

        $this->assertNull($actual);
    }

    public function test_it_returns_null_without_explicit_or_environment_config(): void
    {
        $actual = (new OpenTelemetryConfigurationResolver())->resolve(null, []);

        $this->assertNull($actual);
    }

    public function test_it_returns_null_when_environment_disables_the_sdk(): void
    {
        $actual = (new OpenTelemetryConfigurationResolver())->resolve(
            null,
            [
                'OTEL_SDK_DISABLED' => 'true',
                'OTEL_SERVICE_NAME' => 'infection',
            ],
        );

        $this->assertNull($actual);
    }

    public function test_it_resolves_environment_config_with_trace_specific_otlp_overrides(): void
    {
        $actual = (new OpenTelemetryConfigurationResolver())->resolve(
            null,
            [
                'OTEL_SERVICE_NAME' => 'custom-infection',
                'OTEL_RESOURCE_ATTRIBUTES' => 'service.namespace=infection,deployment.environment=ci',
                'OTEL_TRACES_EXPORTER' => 'otlp',
                'OTEL_TRACES_SAMPLER' => 'traceidratio',
                'OTEL_TRACES_SAMPLER_ARG' => '0.25',
                'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector:4318',
                'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'http://collector:4318/v1/traces',
                'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc',
                'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL' => 'http/protobuf',
                'OTEL_EXPORTER_OTLP_HEADERS' => 'authorization=Bearer generic,x-team=infection',
                'OTEL_EXPORTER_OTLP_TRACES_HEADERS' => 'authorization=Bearer trace',
                'OTEL_EXPORTER_OTLP_COMPRESSION' => 'gzip',
                'OTEL_EXPORTER_OTLP_TRACES_COMPRESSION' => 'none',
                'OTEL_EXPORTER_OTLP_TIMEOUT' => '2000',
                'OTEL_EXPORTER_OTLP_TRACES_TIMEOUT' => '1000',
                'OTEL_BSP_SCHEDULE_DELAY' => '11',
                'OTEL_BSP_EXPORT_TIMEOUT' => '12',
                'OTEL_BSP_MAX_QUEUE_SIZE' => '13',
                'OTEL_BSP_MAX_EXPORT_BATCH_SIZE' => '14',
                'OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT' => '15',
                'OTEL_ATTRIBUTE_COUNT_LIMIT' => '16',
                'OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT' => '17',
                'OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT' => '18',
                'OTEL_SPAN_EVENT_COUNT_LIMIT' => '19',
                'OTEL_SPAN_LINK_COUNT_LIMIT' => '20',
            ],
        );

        $this->assertEquals(
            new TelemetryEntry(
                enabled: true,
                serviceName: 'custom-infection',
                resourceAttributes: [
                    'service.namespace' => 'infection',
                    'deployment.environment' => 'ci',
                ],
                traces: new TelemetryTracesEntry(
                    exporter: 'otlp',
                    sampler: 'traceidratio',
                    samplerArg: '0.25',
                ),
                otlp: new TelemetryOtlpEntry(
                    endpoint: 'http://collector:4318',
                    tracesEndpoint: 'http://collector:4318/v1/traces',
                    protocol: 'http/protobuf',
                    headers: [
                        'authorization' => 'Bearer trace',
                        'x-team' => 'infection',
                    ],
                    compression: 'none',
                    timeout: 1000,
                ),
                batchSpanProcessor: new TelemetryBatchSpanProcessorEntry(
                    scheduleDelay: 11,
                    exportTimeout: 12,
                    maxQueueSize: 13,
                    maxExportBatchSize: 14,
                ),
                limits: new TelemetryLimitsEntry(
                    attributeValueLength: 17,
                    attributeCount: 16,
                    spanAttributeCount: 18,
                    spanEventCount: 19,
                    spanLinkCount: 20,
                ),
            ),
            $actual,
        );
    }

    public function test_it_rejects_invalid_integer_environment_values(): void
    {
        $this->expectException(InvalidTelemetryConfiguration::class);
        $this->expectExceptionMessage('Invalid OTEL_BSP_MAX_QUEUE_SIZE value "0": expected a positive integer.');

        (new OpenTelemetryConfigurationResolver())->resolve(
            null,
            [
                'OTEL_SERVICE_NAME' => 'infection',
                'OTEL_BSP_MAX_QUEUE_SIZE' => '0',
            ],
        );
    }
}
