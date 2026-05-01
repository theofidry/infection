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

namespace Infection\Telemetry\Configuration;

use function array_key_exists;
use function ctype_digit;
use function explode;
use Infection\Configuration\Entry\TelemetryBatchSpanProcessorEntry;
use Infection\Configuration\Entry\TelemetryEntry;
use Infection\Configuration\Entry\TelemetryLimitsEntry;
use Infection\Configuration\Entry\TelemetryOtlpEntry;
use Infection\Configuration\Entry\TelemetryTracesEntry;
use function sprintf;
use function strcasecmp;
use function trim;

final class OpenTelemetryConfigurationResolver
{
    private const array SUPPORTED_ENV_VARS = [
        'OTEL_SDK_DISABLED',
        'OTEL_SERVICE_NAME',
        'OTEL_RESOURCE_ATTRIBUTES',
        'OTEL_LOG_LEVEL',
        'OTEL_TRACES_EXPORTER',
        'OTEL_TRACES_SAMPLER',
        'OTEL_TRACES_SAMPLER_ARG',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL',
        'OTEL_EXPORTER_OTLP_HEADERS',
        'OTEL_EXPORTER_OTLP_TRACES_HEADERS',
        'OTEL_EXPORTER_OTLP_COMPRESSION',
        'OTEL_EXPORTER_OTLP_TRACES_COMPRESSION',
        'OTEL_EXPORTER_OTLP_TIMEOUT',
        'OTEL_EXPORTER_OTLP_TRACES_TIMEOUT',
        'OTEL_BSP_SCHEDULE_DELAY',
        'OTEL_BSP_EXPORT_TIMEOUT',
        'OTEL_BSP_MAX_QUEUE_SIZE',
        'OTEL_BSP_MAX_EXPORT_BATCH_SIZE',
        'OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT',
        'OTEL_ATTRIBUTE_COUNT_LIMIT',
        'OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT',
        'OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT',
        'OTEL_SPAN_EVENT_COUNT_LIMIT',
        'OTEL_SPAN_LINK_COUNT_LIMIT',
        'OTEL_EVENT_ATTRIBUTE_COUNT_LIMIT',
        'OTEL_LINK_ATTRIBUTE_COUNT_LIMIT',
    ];

    /**
     * @param array<string, string> $environment
     */
    public function resolve(?TelemetryEntry $explicitConfig, array $environment): ?TelemetryEntry
    {
        if ($this->isSdkDisabled($environment)) {
            return null;
        }

        if ($explicitConfig !== null) {
            return $explicitConfig;
        }

        if (!$this->hasSupportedEnvironmentConfiguration($environment)) {
            return null;
        }

        return new TelemetryEntry(
            enabled: true,
            serviceName: $this->envString($environment, 'OTEL_SERVICE_NAME') ?? 'infection',
            resourceAttributes: $this->parseKeyValueList($this->envString($environment, 'OTEL_RESOURCE_ATTRIBUTES')),
            traces: new TelemetryTracesEntry(
                exporter: $this->envString($environment, 'OTEL_TRACES_EXPORTER') ?? 'otlp',
                sampler: $this->envString($environment, 'OTEL_TRACES_SAMPLER') ?? 'parentbased_always_on',
                samplerArg: $this->envString($environment, 'OTEL_TRACES_SAMPLER_ARG'),
            ),
            otlp: new TelemetryOtlpEntry(
                endpoint: $this->envString($environment, 'OTEL_EXPORTER_OTLP_ENDPOINT') ?? 'http://localhost:4318',
                tracesEndpoint: $this->envString($environment, 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
                protocol: $this->envString($environment, 'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL')
                    ?? $this->envString($environment, 'OTEL_EXPORTER_OTLP_PROTOCOL')
                    ?? 'http/protobuf',
                headers: $this->parseHeaders(
                    $this->envString($environment, 'OTEL_EXPORTER_OTLP_HEADERS'),
                    $this->envString($environment, 'OTEL_EXPORTER_OTLP_TRACES_HEADERS'),
                ),
                compression: $this->envString($environment, 'OTEL_EXPORTER_OTLP_TRACES_COMPRESSION')
                    ?? $this->envString($environment, 'OTEL_EXPORTER_OTLP_COMPRESSION')
                    ?? 'none',
                timeout: $this->envInt($environment, 'OTEL_EXPORTER_OTLP_TRACES_TIMEOUT')
                    ?? $this->envInt($environment, 'OTEL_EXPORTER_OTLP_TIMEOUT')
                    ?? 10000,
            ),
            batchSpanProcessor: new TelemetryBatchSpanProcessorEntry(
                scheduleDelay: $this->envInt($environment, 'OTEL_BSP_SCHEDULE_DELAY') ?? 5000,
                exportTimeout: $this->envInt($environment, 'OTEL_BSP_EXPORT_TIMEOUT') ?? 30000,
                maxQueueSize: $this->envPositiveInt($environment, 'OTEL_BSP_MAX_QUEUE_SIZE') ?? 2048,
                maxExportBatchSize: $this->envPositiveInt($environment, 'OTEL_BSP_MAX_EXPORT_BATCH_SIZE') ?? 512,
            ),
            limits: new TelemetryLimitsEntry(
                attributeValueLength: $this->envInt($environment, 'OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT')
                    ?? $this->envInt($environment, 'OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT'),
                attributeCount: $this->envInt($environment, 'OTEL_ATTRIBUTE_COUNT_LIMIT') ?? 128,
                spanAttributeCount: $this->envInt($environment, 'OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT') ?? 128,
                spanEventCount: $this->envInt($environment, 'OTEL_SPAN_EVENT_COUNT_LIMIT') ?? 128,
                spanLinkCount: $this->envInt($environment, 'OTEL_SPAN_LINK_COUNT_LIMIT') ?? 128,
            ),
        );
    }

    /**
     * @param array<string, string> $environment
     */
    private function hasSupportedEnvironmentConfiguration(array $environment): bool
    {
        foreach (self::SUPPORTED_ENV_VARS as $name) {
            if ($this->envString($environment, $name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $environment
     */
    private function isSdkDisabled(array $environment): bool
    {
        return strcasecmp($this->envString($environment, 'OTEL_SDK_DISABLED') ?? '', 'true') === 0;
    }

    /**
     * @param array<string, string> $environment
     */
    private function envString(array $environment, string $name): ?string
    {
        if (!array_key_exists($name, $environment)) {
            return null;
        }

        $value = trim($environment[$name]);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $environment
     */
    private function envInt(array $environment, string $name): ?int
    {
        $value = $this->envString($environment, $name);

        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new InvalidTelemetryConfiguration(sprintf('Invalid %s value "%s": expected a non-negative integer.', $name, $value));
        }

        return (int) $value;
    }

    /**
     * @param array<string, string> $environment
     */
    private function envPositiveInt(array $environment, string $name): ?int
    {
        $value = $this->envInt($environment, $name);

        if ($value === null) {
            return null;
        }

        if ($value < 1) {
            throw new InvalidTelemetryConfiguration(sprintf('Invalid %s value "%s": expected a positive integer.', $name, $value));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(?string $genericHeaders, ?string $traceHeaders): array
    {
        return [
            ...$this->parseKeyValueList($genericHeaders),
            ...$this->parseKeyValueList($traceHeaders),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValueList(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = [];

        foreach (explode(',', $value) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            [$key, $itemValue] = explode('=', $pair, 2) + [1 => null];
            $key = trim((string) $key);
            $itemValue = $itemValue === null ? null : trim($itemValue);

            if ($key === '' || $itemValue === null || $itemValue === '') {
                continue;
            }

            $items[$key] = $itemValue;
        }

        return $items;
    }
}
