# Telemetry

Infection can emit OpenTelemetry traces when standard `OTEL_*` environment
variables request tracing. There is no Infection-specific telemetry config in
`infection.json5` yet.

Telemetry is disabled by default. This first slice only allows the OpenTelemetry
console trace exporter:

```bash
OTEL_TRACES_EXPORTER=console vendor/bin/infection
```

Other trace exporters, metrics exporters, and logs exporters are rejected for
now. OTLP export will be introduced in a later increment.

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
