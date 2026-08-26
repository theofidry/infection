# Mutation execution strategies proof of concept

Related: [#88](https://github.com/infection/infection/issues/88),
[#2239](https://github.com/infection/infection/issues/2239), and
[#3498](https://github.com/infection/infection/issues/3498), areas 3 and 4.

## Status and recommendation

This branch is an experimental proof of concept based on `origin/master` at `03bac9f7b`. It
demonstrates a boundary between mutation generation and materialization, then exercises that
boundary with mutation weights and syntactic arid-node detection. It preserves the selected
population and MSI, but deliberately changes execution order within each source file. It does
not propose that this policy should be merged before the active test-framework evaluation
contracts settle.

The recommendation remains: design the boundary now, measure child bootstrap and test cost,
and simulate strategies offline from existing traces. Grouped or higher-order mutants need a
separate RFC defining scoring, rerun identity, splitting and fault-localisation semantics.

## Baseline problem

On `03bac9f7b`, `MutationTestingRunner` receives the generated mutation stream and directly
owns the `--id` selection before creating one `Mutant` per `Mutation`. It then applies
source-code ignore rules, coverage and timeout decisions and creates process containers.
`ParallelProcessRunner` consumes those containers as a non-rewindable stream and orchestrates
worker slots and static-analysis follow-ups.

This makes it tempting to add sampling, prioritization, grouping or tracing behavior to the
runner. That would mix selection policy with process scheduling and threaten the runner's
streaming, queue, `TEST_TOKEN` and follow-up invariants.

## Boundary exercised by this branch

The POC adds four deliberately narrow concepts under `src/Mutation/Selection/`:

1. A generated `Mutation` is the candidate. No wrapper is added because generation already
   provides its deterministic source, mutator, position, covering-test and timing context.
2. `MutationWeight` keeps three signals independent: expected value, noise risk, and estimated
   execution cost. The POC assigns expected value `1` to ordinary mutations and `0` to arid
   mutations, leaves noise risk unknown, and reuses the existing nominal covering-test time as
   cost. It deliberately has no composite score.
3. `MutationEvaluationPlan` gives an independently schedulable first-order evaluation an
   explicit identity, selected mutation, weight and MSI intent. Its identity is the existing
   `Mutation::getHash()` compatibility id; arid metadata does not alter that hash.
4. `WeightedMutationSelector::select()` consumes candidates once and buffers one contiguous
   source-file batch. Within the batch it orders higher expected value first, then lower cost,
   then original position. File order is unchanged. The explicit `--id` path remains lazy and
   stops as soon as it finds the requested mutation.

`MarkNodesAsAridVisitor` runs after parent connection during AST enrichment. It marks a call and
its descendants as arid when it sees `error_log()` or a method/static/nullsafe call whose
literal name matches a PSR-3 logging level (`debug`, `info`, `notice`, `warning`, `error`,
`critical`, `alert`, `emergency`, or `log`). The marker is copied into `Mutation` separately
from the six hash attributes.

This is intentionally a syntactic experiment. It does not prove that the receiver implements
PSR-3, so `$domainObject->error()` is a possible false positive. Dynamic method calls are not
classified. Performance code is not classified because its intent cannot be inferred
reliably from an AST shape.

`MutationTestingRunner` creates the weighted selector from its existing mutant-id input and
transforms emitted plans into mutants. Constructor wiring is deliberately unchanged so the
seam does not disturb the active container and evaluation rework. The runner remains
responsible for existing post-materialization decisions until those decisions have explicit
result/reporting semantics.

`ParallelProcessRunner`, `MutantCodeFactory`, `Mutation`, process containers, adapters,
metrics and reporters are untouched. This is important: a selection strategy can now change
which independent first-order evaluations are requested without becoming scheduling policy.

## What the POC proves

- Selection can happen before AST cloning, mutant printing, temporary-file writes and process
  construction.
- A weight can travel with an evaluation plan without changing `Mutation` identity or MSI.
- Value, noise and cost do not need to become one unexplained number.
- Arid context can be attached during the existing enrichment pass and survive serialization
  into the selection stage.
- Bounded prioritization is possible without a global sort: the selector consumes once and
  buffers at most the current contiguous file batch.
- The rerun-id path does not count, rewind, weight or pre-consume candidates after its match.
- `IterableCounter` now preserves the streamed value type through its by-reference buffering
  operation; runtime counting and buffering behavior is unchanged.
- Plan identity and first-order MSI intent can be stated before execution.
- Process orchestration does not need to know which selector produced the work.

It does not yet prove the complete desired model. A production design still needs a selected
mutation record if selection diagnostics must explain rejected candidates, deterministic
strategy context (including seed and trace capabilities), a final outcome contract, and a
decision about where pre-materialization coverage and timeout suppression belong.

## How to test the POC

Install dependencies if needed:

```bash
composer install
```

Run the focused selector, arid visitor and integration tests:

```bash
vendor/phpunit/phpunit/phpunit tests/phpunit/Mutation/Selection
vendor/phpunit/phpunit/phpunit tests/phpunit/PhpParser/Visitor/MarkNodesAsAridVisitorTest.php
vendor/phpunit/phpunit/phpunit tests/phpunit/Mutation/MutationTest.php
vendor/phpunit/phpunit/phpunit tests/phpunit/Process/Runner/MutationTestingRunnerTest.php
```

The tests demonstrate arid propagation, hash preservation, separate weight signals, stable
per-file prioritization, the buffering boundary, deterministic `--id` selection, and unchanged
runner events, mutants and process containers.

For a manual smoke test against a small project, run Infection normally and then rerun one
reported mutation id:

```bash
./bin/infection --threads=1 --no-progress
./bin/infection --threads=1 --no-progress --id=<reported-mutant-id>
```

The first command exercises weighted per-file ordering. Add temporary logging and ordinary
mutatable expressions in the same source file and use `-vvv` or the debug log to inspect their
execution order. The second command exercises selector-level id filtering. Both retain
first-order materialization, existing test filtering, process isolation, static-analysis
follow-ups and reporting behavior.

Run wider project checks with:

```bash
make cs
make autoreview
make test-unit
```

### Verification on this branch

On 2026-08-26 with PHP 8.4.22, the focused visitor, mutation, selector and runner suite passes
(37 tests, 289 assertions), and `make autoreview` passes. The latter includes CS checks,
PHPStan/PHPat, Mago, Composer validation, the AutoReview suite, Rector, collision detection and
zizmor.

## Deliberate limitations

- There is no new CLI or schema option. Weighted ordering is always enabled on this PoC branch;
  this is for evaluation, not a proposed default.
- Ignore-regex checks currently require a materialized diff, and coverage and timeout skips
  already have reporter-visible results. This POC does not silently move them into selection.
- `MutationEvaluationPlan` carries one mutation. Supporting several mutations requires a new
  materialization contract rather than changing this class to imply grouping already works.
- The MSI intent is descriptive; existing calculators remain authoritative.
- No-coverage mode is not implemented. A capable adapter must define an unambiguous whole-suite
  fallback where absence of a filter means all tests, never no tests.
- Sampling, exclusion, budgets, seeds, confidence and reporting of weights are not implemented.
- Noise risk is deliberately unknown. Mutator-level noise metadata needs its own evidence and
  contract before it can influence ordering.
- The logging classifier uses literal call names without receiver type proof and therefore has
  known false positives. It is unsuitable for exclusion or MSI changes.
- Per-file ranking relies on generated mutations remaining contiguous by source path. It delays
  the first evaluation for a file until the next file begins, or generation ends.
- The active test-framework rework changes process/evaluation contracts and must be reconciled
  before this seam is promoted to production architecture.

## Next incremental slices

1. Run this classifier on several projects and measure false positives, ordering changes,
   time-to-first-process, and peak mutations buffered per file.
2. Add offline reporting of arid classifications and separate signals before adding exclusion.
3. Complete a decision table for candidate, selected mutation, evaluation plan, final outcome,
   scoring and selection diagnostics.
4. Rebase the seam after the test-framework contracts settle and prove opt-in configuration
   and reporting with a low-risk individual-mutation strategy.
5. Consider grouped execution only through an experimental RFC with recursive first-order
   fallback and benchmarked split/retry cost.

## Open decisions and risks

- Sampling changes MSI unless denominator, seed, reproducibility and confidence are defined.
- Global ranking can violate the stream's memory and time-to-first-process guarantees.
- Higher-order mutations can mask or amplify each other; coverage disjointness does not prove
  independence.
- A killed group cannot identify the killed member. Existing first-order MSI requires splitting
  to final individual outcomes or an explicitly different metric.
- `Mutation::getHash()` identifies one mutation and is a compatibility contract. Group identity
  and rerun syntax cannot overload it accidentally.
- Process reuse risks test pollution and loses current one-mutant-per-process isolation.

Strategy and scoring are durable public testing-policy choices with credible alternatives.
The RFC may be sufficient if it records the decision; otherwise this is an ADR candidate. No
current ADR covers it, and this POC document is not that ADR.
