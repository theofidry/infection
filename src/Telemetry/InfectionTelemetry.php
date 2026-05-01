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

use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

final readonly class InfectionTelemetry
{
    public function __construct(
        private TracerInterface $tracer,
        private ?TracerProviderInterface $tracerProvider = null,
    ) {
    }

    public static function noop(): self
    {
        return new self(NoopTracer::getInstance());
    }

    public function isEnabled(): bool
    {
        return $this->tracer->isEnabled();
    }

    /**
     * @param array<string, bool|float|int|string|array|null> $attributes
     */
    public function startRootSpan(string $name, array $attributes = []): SpanHandle
    {
        if (!$this->isEnabled()) {
            return SpanHandle::invalid();
        }

        $span = $this->tracer
            ->spanBuilder($name)
            ->setParent(false)
            ->setAttributes($attributes)
            ->startSpan();

        return new SpanHandle(
            $span,
            $span->storeInContext(Context::getCurrent()),
        );
    }

    /**
     * @param array<string, bool|float|int|string|array|null> $attributes
     * @param list<SpanLink> $links
     */
    public function startChildSpan(
        SpanHandle $parent,
        string $name,
        array $attributes = [],
        array $links = [],
    ): SpanHandle {
        if (!$this->isEnabled()) {
            return SpanHandle::invalid();
        }

        $builder = $this->tracer
            ->spanBuilder($name)
            ->setParent($parent->context)
            ->setAttributes($attributes);

        foreach ($links as $link) {
            $builder->addLink($link->spanHandle->span->getContext(), $link->attributes);
        }

        $span = $builder->startSpan();

        return new SpanHandle(
            $span,
            $span->storeInContext($parent->context),
        );
    }

    /**
     * @param array<string, bool|float|int|string|array|null> $attributes
     */
    public function end(SpanHandle $spanHandle, array $attributes = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($attributes !== []) {
            $spanHandle->span->setAttributes($attributes);
        }

        $spanHandle->span->end();
    }

    public function recordException(SpanHandle $spanHandle, Throwable $throwable): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $spanHandle->span->recordException($throwable);
        $spanHandle->span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
    }

    public function forceFlush(): bool
    {
        return $this->tracerProvider?->forceFlush() ?? true;
    }

    public function shutdown(): bool
    {
        return $this->tracerProvider?->shutdown() ?? true;
    }
}
