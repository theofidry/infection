# Telemetry

Infection can emit OpenTelemetry traces when standard `OTEL_*` environment
variables request tracing. There is no Infection-specific telemetry config in
`infection.json5` yet.

Telemetry is disabled by default. Enable the console trace exporter to print
spans locally:

```bash
OTEL_TRACES_EXPORTER=console vendor/bin/infection --quiet
```

Enable the OTLP trace exporter by selecting `otlp` and configuring a collector
endpoint:

```bash
OTEL_TRACES_EXPORTER=otlp \
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf \
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318 \
vendor/bin/infection --quiet
```

Setting only protocol, compression, or headers variables does not enable
telemetry; tracing must also be requested through an exporter or OTLP endpoint.

`http/protobuf` works with the Composer package pulled by the OpenTelemetry
dependencies. The `protobuf` PHP extension is optional and only improves
protobuf serialization performance.

The trace-specific OTLP variables take precedence over the generic OTLP
variables. For example:

```bash
OTEL_TRACES_EXPORTER=otlp \
OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=http/protobuf \
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://127.0.0.1:4318/v1/traces \
OTEL_EXPORTER_OTLP_TRACES_COMPRESSION=gzip \
vendor/bin/infection --quiet
```

`OTEL_EXPORTER_OTLP_TRACES_COMPRESSION` overrides
`OTEL_EXPORTER_OTLP_COMPRESSION` for traces. Supported compression values are
`none` and `gzip`; `none` disables request compression.

Metrics exporters and logs exporters are rejected for now.

To inspect the OpenTelemetry tracer service created from the current
environment, use:

```bash
OTEL_TRACES_EXPORTER=console vendor/bin/infection debug:telemetry
```

This command only dumps the configured tracer service. It does not create spans
or prove that data can be delivered to an OTLP collector.

Infection sets `OTEL_SERVICE_NAME=infection` when no service name is provided.
Override it with:

```bash
OTEL_SERVICE_NAME=my-project-infection vendor/bin/infection
```

To force telemetry off, use:

```bash
OTEL_SDK_DISABLED=true vendor/bin/infection
```

The first telemetry slice records the main Infection lifecycle spans:

- `infection.run`
- `infection.initial_tests`
- `infection.initial_static_analysis`
- `infection.mutation_generation`
- `infection.mutation_testing`
- `infection.mutation_evaluation`

Mutation evaluation spans include basic mutation attributes such as the mutant
hash, mutator name, source file path, source lines, status, and process runtime.
