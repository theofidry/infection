# Mutation selection boundary, weighting, and arid code

Related: [#2239](https://github.com/infection/infection/issues/2239) and
[#3498](https://github.com/infection/infection/issues/3498), areas 3 and 4.

Status: proof of concept based on `origin/master` at `03bac9f7b`.

## Recommendation

Design and measure now; wait before adding runtime weights or selection configuration. The
independent first slice on this branch is an offline analysis of existing mutation results.
It does not change mutation generation, execution order, selection, reporting semantics, or
MSI.

Runtime selection belongs after the mutation-analysis boundary is explicit. It need not
depend on the evaluator result contract, but implementing it inside today's runner would add
another responsibility that the TestFramework rework would have to extract.

## What this proof of concept does

`devTools/analyse-mutation-results.php` reads Infection's existing JSON log and emits a
deterministically ordered JSON summary:

- outcome counts by mutator;
- outcome counts by the first significant PHP token on the mutation's reported start line;
  and
- the number of mutations for which the JSON report has no detailed record.

The token grouping is intentionally named `source_construct`, not “arid code”. It is a cheap,
inspectable proxy with obvious limits. For example, `T_VARIABLE` says nothing about the
business importance or observability of the expression. The PoC provides data for discussing
classifiers; it is not itself a classifier or a score.

The analyser also reports `runtime_available: false`. Infection has a per-mutant process
runtime in `MutantExecutionResult`, but the existing JSON report does not expose it. Adding
runtime telemetry is outside this slice.

## How to try it

Configure Infection's JSON log in the project's `infection.json5`:

```json5
{
    // Keep the project's other settings.
    logs: {
        json: "infection-log.json",
    },
}
```

Then run Infection:

```bash
./vendor/bin/infection
```

Run the analyser from this checkout:

```bash
php devTools/analyse-mutation-results.php /path/to/project/infection-log.json \
    > mutation-results-analysis.json
```

Inspect the whole result or query a dimension with `jq`:

```bash
jq '.input' mutation-results-analysis.json
jq '.by_mutator' mutation-results-analysis.json
jq '.by_source_construct' mutation-results-analysis.json
```

The command exits non-zero for a missing file, malformed JSON, or an incompatible detailed
mutation row. It needs PHP 8.3 or newer and no Composer dependencies.

For a quick smoke test without running Infection, create a report containing one killed and
one escaped mutation:

```bash
php -r 'echo json_encode([
    "stats" => ["totalMutantsCount" => 2],
    "killed" => [["mutator" => ["mutatorName" => "Plus", "originalSourceCode" => "\$a + \$b", "originalStartLine" => 1]]],
    "escaped" => [["mutator" => ["mutatorName" => "ReturnRemoval", "originalSourceCode" => "return \$a;", "originalStartLine" => 1]]],
]);' > /tmp/infection-log.json
php devTools/analyse-mutation-results.php /tmp/infection-log.json
```

## Findings from the current boundary

The current stream has no selection stage or weight:

1. `MutationGenerator::generate()` enumerates source files and yields every mutation produced
   by `FileMutationGenerator`.
2. AST enrichment suppresses mutations for coverage, git-diff, and user-ignore reasons. These
   are eligibility decisions, not ranked selection.
3. `MutationTestingRunner::run()` applies mutant-id, source-code-regex, uncovered-code, and
   predicted-runtime filters, materialises survivors, and creates the evaluation process
   chain. It therefore mixes selection policy, materialisation, and evaluation orchestration.
4. `Mutation::getNominalTestExecutionTime()` is used to skip mutations predicted to exceed the
   configured timeout. It does not reorder work.
5. Results are sorted for presentation by file and line. `ParallelProcessRunner` consumes a
   single-pass stream and must not buffer or rewind it.

The existing JSON log is useful for outcome analysis, but it has two relevant limitations:

- per-mutant process runtime is not included; and
- `SKIPPED` mutations contribute to `stats.totalMutantsCount` but have no detailed section.

The PoC exposes the second limitation as `mutations_without_details`. This value can also be
affected by covered-only reporting, so it is a data-quality signal rather than a reconstructed
status.

## Required design before runtime code

A future boundary should have the shape `generated mutations -> selection decision -> selected
mutation stream`. A decision should preserve the mutation and record whether it was selected,
with a bounded reason and optional scores. It belongs upstream of materialisation and evaluator
creation.

Before implementing it, decide:

- whether ranking is a mutator default, a mutation-instance heuristic, user-declared source
  criticality, or a composition;
- whether a weight affects ordering, sampling or budgets, reporting prominence, or MSI;
- what “arid” means: low business impact, boilerplate, low observability, or syntax;
- whether scores are deterministic and serializable, without changing `Mutation::getHash()`;
  and
- whether ordering uses bounded queues, buckets, or stable per-file ordering rather than a
  global buffering sort.

Value, likelihood of noise or equivalence, and execution cost must remain separate dimensions.
Collapsing them into one number would hide policy decisions as false precision. Unexecuted
weighted mutations also need an explicit outcome and denominator decision shared with
#3017/#3018; selection must not silently alter MSI.

## Incremental path

1. Use this PoC on real reports and compare projects, mutators, and coarse syntax groups.
2. Define value, noise, and cost separately in a small RFC, including ordering versus exclusion
   and MSI consequences.
3. After runner ownership stabilises, extract a no-op selection boundary and assert identical
   order and counts.
4. Only then consider weights, arid classifiers, budgets, configuration, or scheduling.

The accepted architecture, public contract, and metric semantics are ADR candidates. Search
`adr/` and follow `adr/README.md` before recording that durable decision.

## Collision and risks

The active TestFramework rework moves `MutationTestingRunner` toward
`CombinedTestFramework`/`MutantEvaluationPipe` and closure-based process chains. Those types do
not exist on the audited `origin/master`. A runner-level selection implementation would
conflict directly; this offline tool does not.

Key risks remain:

- a universal score may encode maintainer opinion and transfer poorly across project types;
- syntax can misclassify important domain code as arid;
- path rules can be gamed and add configuration complexity;
- selection changes the measured population and can make a familiar MSI misleading;
- global scheduling conflicts with streaming and may cost more than it saves; and
- learned project outcomes introduce reproducibility, persistence, and privacy concerns.
