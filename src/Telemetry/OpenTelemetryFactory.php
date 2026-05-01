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

use Infection\Configuration\Entry\TelemetryEntry;
use Infection\Telemetry\Configuration\InvalidTelemetryConfiguration;
use function in_array;
use function is_numeric;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanLimits;
use OpenTelemetry\SDK\Trace\SpanLimitsBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use RuntimeException;
use function sprintf;

final class OpenTelemetryFactory
{
    public function create(
        ?TelemetryEntry $config,
        ?SpanExporterInterface $spanExporter = null,
        ?string $serviceVersion = null,
    ): InfectionTelemetry {
        if ($config === null || !$config->enabled || $config->traces->exporter === 'none') {
            return InfectionTelemetry::noop();
        }

        if ($config->traces->exporter !== 'otlp') {
            throw new InvalidTelemetryConfiguration(sprintf(
                'Unsupported telemetry traces exporter "%s". Supported values are "otlp" and "none".',
                $config->traces->exporter,
            ));
        }

        $spanExporter ??= self::createOtlpSpanExporter($config);

        $tracerProvider = new TracerProvider(
            new BatchSpanProcessor(
                $spanExporter,
                SystemClock::create(),
                $config->batchSpanProcessor->maxQueueSize,
                $config->batchSpanProcessor->scheduleDelay,
                $config->batchSpanProcessor->exportTimeout,
                $config->batchSpanProcessor->maxExportBatchSize,
            ),
            self::createSampler($config),
            self::createResource($config, $serviceVersion),
            self::createSpanLimits($config),
        );

        return new InfectionTelemetry(
            $tracerProvider->getTracer('infection'),
            $tracerProvider,
        );
    }

    private static function createOtlpSpanExporter(TelemetryEntry $config): SpanExporter
    {
        if (!in_array($config->otlp->protocol, [Protocols::GRPC, Protocols::HTTP_PROTOBUF, Protocols::HTTP_JSON], true)) {
            throw new InvalidTelemetryConfiguration(sprintf(
                'Unsupported telemetry OTLP protocol "%s". Supported values are "grpc", "http/protobuf" and "http/json".',
                $config->otlp->protocol,
            ));
        }

        $endpoint = $config->otlp->tracesEndpoint ?? match ($config->otlp->protocol) {
            Protocols::GRPC => $config->otlp->endpoint . OtlpUtil::method(Signals::TRACE),
            default => HttpEndpointResolver::create()->resolveToString($config->otlp->endpoint, Signals::TRACE),
        };

        try {
            $transportFactory = Registry::transportFactory($config->otlp->protocol);
        } catch (RuntimeException $exception) {
            throw new InvalidTelemetryConfiguration(sprintf(
                'No OpenTelemetry transport is registered for OTLP protocol "%s". Install and enable the matching transport package.',
                $config->otlp->protocol,
            ), previous: $exception);
        }

        $transport = $transportFactory->create(
            $endpoint,
            Protocols::contentType($config->otlp->protocol),
            [...$config->otlp->headers, ...OtlpUtil::getUserAgentHeader()],
            $config->otlp->compression,
            $config->otlp->timeout / 1000,
        );

        return new SpanExporter($transport);
    }

    private static function createSampler(TelemetryEntry $config): SamplerInterface
    {
        $ratio = static fn (): float => self::parseSamplerRatio($config->traces->samplerArg);

        return match ($config->traces->sampler) {
            'always_on' => new AlwaysOnSampler(),
            'always_off' => new AlwaysOffSampler(),
            'traceidratio' => new TraceIdRatioBasedSampler($ratio()),
            'parentbased_always_on' => new ParentBased(new AlwaysOnSampler()),
            'parentbased_always_off' => new ParentBased(new AlwaysOffSampler()),
            'parentbased_traceidratio' => new ParentBased(new TraceIdRatioBasedSampler($ratio())),
            default => throw new InvalidTelemetryConfiguration(sprintf(
                'Unsupported telemetry traces sampler "%s".',
                $config->traces->sampler,
            )),
        };
    }

    private static function createResource(TelemetryEntry $config, ?string $serviceVersion): ResourceInfo
    {
        $attributes = [
            ...$config->resourceAttributes,
            ResourceAttributes::SERVICE_NAME => $config->serviceName,
        ];

        if ($serviceVersion !== null) {
            $attributes[ResourceAttributes::SERVICE_VERSION] = $serviceVersion;
        }

        return ResourceInfoFactory::defaultResource()
            ->merge(ResourceInfo::create(Attributes::create($attributes)));
    }

    private static function createSpanLimits(TelemetryEntry $config): SpanLimits
    {
        $builder = (new SpanLimitsBuilder())
            ->setAttributeCountLimit($config->limits->spanAttributeCount)
            ->setEventCountLimit($config->limits->spanEventCount)
            ->setLinkCountLimit($config->limits->spanLinkCount)
            ->setAttributePerEventCountLimit($config->limits->attributeCount)
            ->setAttributePerLinkCountLimit($config->limits->attributeCount);

        if ($config->limits->attributeValueLength !== null) {
            $builder->setAttributeValueLengthLimit($config->limits->attributeValueLength);
        }

        return $builder->build();
    }

    private static function parseSamplerRatio(?string $samplerArg): float
    {
        if ($samplerArg === null || !is_numeric($samplerArg)) {
            throw new InvalidTelemetryConfiguration('The traceidratio sampler requires a numeric samplerArg.');
        }

        $ratio = (float) $samplerArg;

        if ($ratio < 0 || $ratio > 1) {
            throw new InvalidTelemetryConfiguration('The traceidratio samplerArg must be between 0 and 1.');
        }

        return $ratio;
    }
}
