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

namespace Infection\Configuration\Entry;

final readonly class TelemetryEntry
{
    /**
     * @param array<string, bool|float|int|string> $resourceAttributes
     */
    public function __construct(
        public bool $enabled,
        public string $serviceName,
        public array $resourceAttributes,
        public TelemetryTracesEntry $traces,
        public TelemetryOtlpEntry $otlp,
        public TelemetryBatchSpanProcessorEntry $batchSpanProcessor,
        public TelemetryLimitsEntry $limits,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            enabled: true,
            serviceName: 'infection',
            resourceAttributes: [],
            traces: new TelemetryTracesEntry(
                exporter: 'otlp',
                sampler: 'parentbased_always_on',
                samplerArg: null,
            ),
            otlp: new TelemetryOtlpEntry(
                endpoint: 'http://localhost:4318',
                tracesEndpoint: null,
                protocol: 'http/protobuf',
                headers: [],
                compression: 'none',
                timeout: 10000,
            ),
            batchSpanProcessor: new TelemetryBatchSpanProcessorEntry(
                scheduleDelay: 5000,
                exportTimeout: 30000,
                maxQueueSize: 2048,
                maxExportBatchSize: 512,
            ),
            limits: new TelemetryLimitsEntry(
                attributeValueLength: null,
                attributeCount: 128,
                spanAttributeCount: 128,
                spanEventCount: 128,
                spanLinkCount: 128,
            ),
        );
    }

    public function withAbsolutePaths(): self
    {
        return $this;
    }
}
