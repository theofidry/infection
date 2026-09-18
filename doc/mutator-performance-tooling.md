# Mutator Performance Evaluation Tooling

This document is a roadmap for building the reporting and review tools needed to apply the methodology described in
[Evaluating Mutator Performance](mutator-performance.md).

The workflow can be developed against a small subset of Infection before it is used for a corpus evaluation. Such a
run validates the tools and their interoperability; its results are not sufficient evidence for a decision about a
mutator.

## Initial JSONL Report

The first report contains one JSON object per generated mutation. It exposes the observations Infection already has
after mutation testing without calculating study metrics or collecting human classifications. Infection assigns one
run ID per invocation and includes it in every record. Subsequent invocations append observations to the configured
file, allowing review assignments and classifications to be joined by run ID and mutation ID.

Each line has the following shape:

```json
{
    "runId": "0199…",
    "mutation": {
        "id": "8f4c…",
        "mutatorName": "Plus",
        "mutatorClass": "Infection\\Mutator\\Arithmetic\\Plus",
        "source": {
            "file": "src/Example.php",
            "startLine": 12,
            "endLine": 12
        },
        "diff": "@@ …"
    },
    "tests": [
        {
            "method": "ExampleTest::test_it_adds",
            "file": "tests/ExampleTest.php",
            "executionTimeSeconds": 0.012
        }
    ],
    "detectionStatus": "killed by tests",
    "decisiveProcess": {
        "commandLine": "…",
        "output": "…",
        "runtimeSeconds": 0.031
    }
}
```

The values of `detectionStatus` are the native values of `DetectionStatus`; the report does not regroup them.
`tests` is empty when no covering test is known. A test's file and execution time are nullable because coverage
adapters do not always provide them.

`decisiveProcess` is null for `ignored`, `not covered`, and `skipped` mutations because Infection did not start a
process for them. For other statuses it describes the process whose result determined the final status. This name is
deliberate: when a test-framework process escapes and a static-analysis follow-up kills the mutation,
`MutantExecutionResult` retains only the follow-up process. The initial report must not present that duration as the
complete mutation evaluation time. A later revision can add an ordered `processes` collection after Infection
retains every stage of the follow-up chain.

Fields for baselines, peak resident memory, generation measurements, reviews, and derived statistics are omitted from
the initial report. They should be added only with instrumentation or external input which gives them a defined meaning.
The converter may add columns derived from the report, such as selected-test count, without adding them to the raw
schema. Complete source is recovered from the recorded revision instead of being repeated for every mutation.

### Sampling and Repeated Measurements

One run is sufficient to validate the reporting and review workflow, but not to evaluate mutators. A corpus study has
two distinct sampling concerns:

- human classifications sample unique mutations to measure properties such as actionability and validity;
- repeated executions sample observations of the same mutations to measure operational stability and performance.

Repeated executions do not increase the number of mutations reviewed. For example, 100 mutations evaluated five
times remain a mutation sample of 100 and produce 500 execution observations. Each repetition must use the same
project revision and controlled environment and is identified by the run ID recorded on every observation.

Stability is a derived metric, not metadata about the measurement process. It is calculated by matching repeated
observations of a mutation and can include detection-status agreement, runtime variation, and timeout or process-failure
frequency. Timing comparisons likewise need repeated mutant executions and matched original-code baselines. Aggregate
repetitions per mutation before aggregating mutations per project, so frequently repeated mutations and large projects
do not receive accidental extra weight.

A practical study therefore starts with one complete run to establish the mutation population, randomly samples unique
mutations for human review, and separately selects mutations for repeated execution. The appropriate absolute sample
sizes and coverage across projects matter more than a single sampling percentage. They should be fixed in the study
protocol after the small tooling trial exposes the cost and variance of each measurement.

### Report Architecture

The report uses the new report framework. It does not introduce another implementation of
`Infection\Reporter\Reporter`:

```text
MutatorPerformanceReporterFactory
    -> ComposableReporter
        -> MutatorPerformanceJsonLinesDataProducer
        -> AppendingFileWriter
```

`MutatorPerformanceJsonLinesDataProducer` implements `DataProducer` and lazily yields one encoded JSON object per
`MutantExecutionResult` from `ResultsCollector`. Encoding belongs to the producer; choosing and appending to the
configured destination belongs to the writer. `MutatorPerformanceReporterFactory` implements `ReporterFactory` and returns either
a `ComposableReporter` for the configured path or a `NullReporter` when the report is disabled. The factory is added
to the aggregate reporter factory used by the container.

The configuration adds the optional path `logs.mutatorPerformance`. Enabling it must also make
`TargetDetectionStatusesProvider` retain every native status. Otherwise the producer would receive only the subset
needed by the console and other configured reporters, making the JSONL file silently incomplete.

`AppendingFileWriter` currently joins iterable lines before calling the filesystem. This is sufficient for the small
Infection trial, but it means the initial implementation is JSONL in format rather than a streaming collector. Before
using it on a large corpus, the writer should append lines incrementally without moving JSON encoding or
mutation-specific behaviour into it.

The canonical tests are:

- a data-producer test covering every status, nullable test metadata, process and non-process records, JSON escaping,
  and the static-analysis timing semantics;
- a reporter-factory test covering configured and absent paths and the exact producer/writer composition;
- configuration schema and loading tests for the new log path;
- a target-status-provider test proving that the report retains all statuses;
- a small end-to-end run proving that the file contains one independently decodable object per generated mutation.

## Review and Visualisation Tooling

The first prototype converted the JSONL report to an XLSX workbook (`devTools/mutator-performance-workbook`), with
generated `Mutations`, `Summary`, `Metrics`, and `Observations` sheets and an editable `Reviews` sheet for
classification. It is functionally complete — reviewer input round-trips through validated categories, and the
`Metrics` sheet derives rates from it — but a spreadsheet is a poor medium for reading diffs and process output:
cells wrap and need manual resizing, and OpenSpout cannot express Excel data-validation rules, so a mistyped
classification was still possible despite the `Lists` sheet documenting the accepted values.

`devTools/mutator-performance-review` replaces the workbook as the review-authoring surface. The first iteration
of this replacement was a single self-contained static HTML file — no server, no build step, no dependency,
opened directly from disk. It solved the spreadsheet's ergonomics problem, but raised a different one: its metrics
(syntactic validity, actionability, instability) reimplemented `WorkbookBuilder::calculateMetrics()`'s formulas a
second time, in JavaScript, with no test coverage of its own — exactly the kind of duplication that drifts out of
sync silently. The tool was rebuilt as a small Deno/TypeScript application in the same directory, run locally with
`docker compose` (`make mutator-performance-review`), so the statistics have exactly one implementation and it is
tested. The following decisions were made for this iteration and are expected to be revisited once the tool is
used on a real corpus:

- **Client computes, server persists.** The Deno server is a thin, mostly logic-free layer: it serves the static
  client assets and exposes `GET /api/report` and `GET`/`POST /api/reviews`, reading and writing the report and
  reviews files on disk directly — no manual export/import step. All parsing, indexing, and metrics computation
  happens in the browser, in TypeScript modules (`src/shared/`) shared with the test suite and unit-tested with
  `deno test` (no framework; it ships with Deno). This keeps the one thing that must stay correct — the math — in
  a single tested place, and keeps the server small enough that it barely needs tests of its own.
- **Reviews are a JSON object, not a JSONL log — unlike the report.** The report is append-only because a repeated
  execution of the same mutation is legitimate additional evidence (see "Sampling and Repeated Measurements"
  above); a review is not — reclassifying a mutation replaces the prior classification, it doesn't add a second
  one. So `var/mutator-performance-reviews.json` holds a single JSON object keyed by mutation ID, and
  `POST /api/reviews` upserts one entry into it and rewrites the whole file, rather than appending a line the
  client would later have to reduce. The rewrite goes through a temp file plus `rename` so a crash mid-write can't
  corrupt it, and a file that fails to parse on read is surfaced as an error rather than silently treated as empty
  — silently discarding it would mean the very next successful save overwrites it, losing every prior review. The
  trade-off against the report's append-only approach: concurrent writers could still race (last write wins), but
  this is explicitly a single-reviewer local tool (see "Scope is deliberately narrow" below).
- **Validated classification through the interface, not data-validation.** The six classification values are a radio
  list (each option showing its term and definition inline, sourced from the same `CLASSIFICATIONS` constant used
  for the metrics math) rather than a free-text field, so an invalid or misspelled classification is not
  structurally possible. This directly replaces the dropdown OpenSpout could not provide.
- **Reviews are keyed by mutation ID, not by run ID.** Actionability is a property of the generated mutation, not of
  a specific execution, so reviewing the same mutation once is sufficient regardless of how many runs observed it.
  This matches the workbook's one-row-per-mutation `Reviews` sheet. Each record carries the reviewer's name, so
  independent reviews from different reviewers can still be joined and compared downstream by `mutationId` and
  `reviewer` — the tool itself does not merge them, and there is no `Assignments` mechanism yet.
- **Docker Compose, the first long-running service in this repo.** Every existing `docker-compose.yml` service
  (`php83`, `php84`, `php85`, `zizmor`) is invoked with `docker compose run --rm` for one-shot commands; none of
  them stay up. `mutator-performance-review` is the first meant to be started with `docker compose up` and left
  running while a reviewer works in the browser. It mounts the whole repo (`.:/opt/infection`), matching every
  other service, so it resolves `var/mutator-performance.jsonl` the same way the rest of the tooling does. Because
  that bind mount shadows anything copied in at image-build time, the client TypeScript is bundled (via `esbuild`)
  against the live mounted source rather than once when the image is built — see the next decision for what that
  buys beyond just avoiding a rebuild.
- **Hot reload, not just a live-mounted source.** A rebuild-on-restart alone still meant editing a file, restarting
  the container, and manually refreshing the browser. Instead, the server watches `src/client` and `src/shared`
  (via esbuild's own watch API, which follows the whole module graph so editing a shared module the client imports
  is covered too, not just `app.ts` itself) and `public/index.html`/`public/app.css` directly, rebuilds
  `public/app.js` on every change, and pushes a reload to any open tab over a `GET /api/reload-events`
  Server-Sent-Events stream — plain `EventSource` on the client, no added dependency. The client also reloads if
  the SSE connection drops and later reconnects, which covers the case hot-reloading the client bundle alone can't:
  the server process itself restarting (e.g. after a `Dockerfile` or `server.ts` change forces a full
  `docker compose up --build`).
- **Scope is deliberately narrow.** This pass reproduces the review workflow only. It does not reproduce the
  workbook's generated `Mutations`, `Summary`, or `Observations` tables beyond a small stats strip; browsing a
  mutation's full observation history happens inline in the review pane instead of a separate sheet. There is no
  concurrency control or authentication — this is a single-reviewer local devtool.

Both tools currently exist side by side. The workbook's read-only views are not yet superseded; only its `Reviews`
sheet — the part a developer had to fill in by hand — has a replacement.

## Roadmap

1. Define a row-per-mutation record containing the run ID, mutation ID, mutator, source location, diff, native status,
   selected tests, runtime, and available execution settings.
2. Add an experimental JSONL report which emits these raw observations, including every native status. The JSONL is
   the authoritative evidence and is not edited during review.
3. Provide a review-authoring surface for the JSONL report. The initial approach queued an XLSX workbook; the review
   surface instead became a Deno/TypeScript app run locally with `docker compose`, described in
   [Review and Visualisation Tooling](#review-and-visualisation-tooling) above. An `Assignments` mechanism for
   independent review remains open.
4. Identify a review by run ID, mutation ID, and reviewer. Record its classification, rationale, proposed improvement,
   and uncertainty. Use validated classification values and preserve complete diffs and process output in JSONL when
   spreadsheet cell limits require a shortened display value.
5. Export only the human-authored review records as validated CSV or JSONL and join them with the raw observations.
   Regenerating the workbook must not overwrite completed reviews or treat edits to generated cells as evidence.
6. Generate preliminary category counts, actionability and unresolved proportions, reviewer agreement, native status
   distributions, and the timing statistics supported by the observations. Derive stability statistics only from
   matched repetitions of the same mutation, and clearly state which metrics are unavailable instead of silently
   approximating them.
7. Add missing instrumentation incrementally: matched original-code baseline runs, mutation-generation time and
   memory, mutant and baseline peak resident memory, identifiers needed to match repeated observations, and independent
   validity checks for mutations which Infection did not evaluate.
8. Exercise the complete workflow on a convenient Infection subset and refine the record format, workbook layout,
   validation, and review process as practical problems emerge. Stabilise the protocol and report format only before a
   real corpus study needs reproducible results.
