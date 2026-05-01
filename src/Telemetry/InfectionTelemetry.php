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

use function filter_var;
use function getenv;
use function putenv;
use function strtolower;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use const FILTER_VALIDATE_BOOL;
use const FILTER_NULL_ON_FAILURE;

/**
 * @internal
 */
final readonly class InfectionTelemetry
{
    private function __construct(
        private bool $enabled,
        private TracerInterface $tracer,
        private ?TracerProviderInterface $tracerProvider,
    ) {
    }

    public static function fromEnvironment(): self
    {
        if (self::isSdkDisabled() || !self::isRequested()) {
            return self::disabled();
        }

        self::setDefaultServiceName();
        $tracerProvider = (new TracerProviderFactory())->create();

        return new self(
            true,
            $tracerProvider->getTracer('infection'),
            $tracerProvider,
        );
    }

    public static function disabled(): self
    {
        $tracerProvider = new NoopTracerProvider();

        return new self(
            false,
            $tracerProvider->getTracer('infection'),
            null,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->tracer->isEnabled();
    }

    /**
     * @param array<string, bool|int|float|string> $attributes
     */
    public function startRootSpan(string $name, array $attributes = []): SpanHandle
    {
        return new SpanHandle(
            $this->tracer
                ->spanBuilder($name)
                ->setParent(false)
                ->setAttributes($attributes)
                ->startSpan(),
        );
    }

    /**
     * @param array<string, bool|int|float|string> $attributes
     */
    public function startChildSpan(SpanHandle $parent, string $name, array $attributes = []): SpanHandle
    {
        return new SpanHandle(
            $this->tracer
                ->spanBuilder($name)
                ->setParent($parent->context())
                ->setAttributes($attributes)
                ->startSpan(),
        );
    }

    /**
     * @param array<string, bool|int|float|string> $attributes
     */
    public function end(SpanHandle $span, array $attributes = []): void
    {
        if ($attributes !== []) {
            $span->span->setAttributes($attributes);
        }

        $span->span->end();
    }

    public function shutdown(): void
    {
        $this->tracerProvider?->shutdown();
    }

    private static function isSdkDisabled(): bool
    {
        $value = getenv('OTEL_SDK_DISABLED');

        return $value !== false && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
    }

    private static function isRequested(): bool
    {
        $tracesExporter = getenv('OTEL_TRACES_EXPORTER');

        if ($tracesExporter !== false) {
            return strtolower($tracesExporter) !== 'none';
        }

        return self::hasAnyEnv(
            'OTEL_EXPORTER_OTLP_ENDPOINT',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
            'OTEL_EXPORTER_OTLP_PROTOCOL',
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL',
        );
    }

    private static function hasAnyEnv(string ...$names): bool
    {
        foreach ($names as $name) {
            if (getenv($name) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function setDefaultServiceName(): void
    {
        if (getenv('OTEL_SERVICE_NAME') !== false) {
            return;
        }

        putenv('OTEL_SERVICE_NAME=infection');
        $_SERVER['OTEL_SERVICE_NAME'] = 'infection';
        $_ENV['OTEL_SERVICE_NAME'] = 'infection';
    }
}
