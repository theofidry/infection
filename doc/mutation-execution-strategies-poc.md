# Mutation execution strategies proof of concept

Related: [#88](https://github.com/infection/infection/issues/88) and
[#3498](https://github.com/infection/infection/issues/3498), area 4.

## Status and recommendation

This branch is an intentionally small, behavior-preserving proof of concept based on
`origin/master` at `03bac9f7b`. It demonstrates a streaming boundary between mutation
generation and materialization. It does not propose that strategy implementation should be
merged before the active test-framework evaluation contracts settle.

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

The POC adds three deliberately narrow concepts under `src/Mutation/Selection/`:

1. A generated `Mutation` is the candidate. No wrapper is added because generation already
   provides its deterministic source, mutator, position, covering-test and timing context.
2. `ExhaustiveMutationSelector::select()` consumes candidates once and lazily emits ordered
   plans. A strategy which needs global ranking would have to make and document its buffering
   bound at this boundary. A shared strategy abstraction is deliberately deferred until there
   is a second proven implementation and the active evaluation rework has settled.
3. `MutationEvaluationPlan` gives an independently schedulable first-order evaluation an
   explicit identity, selected mutation and MSI intent. Its identity is the existing
   `Mutation::getHash()` compatibility id; it does not invent a grouped identity.

`ExhaustiveMutationSelector` implements current behavior. It preserves candidate order,
selects every mutation unless an existing `--id` was requested, and yields as it consumes.
`MutationTestingRunner` creates this selector from its existing mutant-id input and transforms
the emitted plans into mutants. Constructor wiring is deliberately unchanged in this POC so
the seam does not disturb the active container and evaluation rework. The runner remains
responsible for the existing post-materialization decisions until those decisions have
explicit result/reporting semantics.

`ParallelProcessRunner`, `MutantCodeFactory`, `Mutation`, process containers, adapters,
metrics and reporters are untouched. This is important: a selection strategy can now change
which independent first-order evaluations are requested without becoming scheduling policy.

## What the POC proves

- Selection can happen before AST cloning, mutant printing, temporary-file writes and process
  construction.
- The current exhaustive behavior and rerun id fit a lazy, order-preserving selector.
- The selector does not count, rewind or pre-consume its input.
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

Run the focused selector and integration tests:

```bash
vendor/phpunit/phpunit/phpunit tests/phpunit/Mutation/Selection
vendor/phpunit/phpunit/phpunit tests/phpunit/Process/Runner/MutationTestingRunnerTest.php
```

The selector test demonstrates ordered single-pass consumption, empty input and deterministic
`--id` selection. The runner test demonstrates that plans still produce the same events,
mutants and process containers.

For a manual smoke test against a small project, run Infection normally and then rerun one
reported mutation id:

```bash
./bin/infection --threads=1 --no-progress
./bin/infection --threads=1 --no-progress --id=<reported-mutant-id>
```

The first command exercises the exhaustive selector. The second exercises selector-level id
filtering. Both retain first-order materialization, whole existing test filtering, process
isolation, static-analysis follow-ups and reporting behavior.

Run wider project checks with:

```bash
make cs
make autoreview
make test-unit
```

### Verification on this branch

On 2026-08-26 with PHP 8.4.22, the focused selector plus runner tests and
`make autoreview` pass. The latter includes CS checks, PHPStan/PHPat, Mago, Composer
validation, the AutoReview suite, Rector, collision detection and zizmor.

## Deliberate limitations

- There is no new CLI or schema option. The only wired strategy is current exhaustive
  behavior, including `--id`.
- Ignore-regex checks currently require a materialized diff, and coverage and timeout skips
  already have reporter-visible results. This POC does not silently move them into selection.
- `MutationEvaluationPlan` carries one mutation. Supporting several mutations requires a new
  materialization contract rather than changing this class to imply grouping already works.
- The MSI intent is descriptive; existing calculators remain authoritative.
- No-coverage mode is not implemented. A capable adapter must define an unambiguous whole-suite
  fallback where absence of a filter means all tests, never no tests.
- Sampling, prioritization, seeds, confidence and bounded buffering are not implemented.
- The active test-framework rework changes process/evaluation contracts and must be reconciled
  before this seam is promoted to production architecture.

## Next incremental slices

1. Measure process/bootstrap cost and simulate prioritization or grouping from traces without
   changing execution.
2. Complete a decision table for candidate, selected mutation, evaluation plan, final outcome,
   scoring and selection diagnostics.
3. Rebase the seam after the test-framework contracts settle and extract exhaustive behavior
   without user-visible changes.
4. Prove configuration and reporting with a low-risk individual-mutation strategy.
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
