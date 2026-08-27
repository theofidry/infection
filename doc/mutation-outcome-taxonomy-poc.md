# Mutation outcome and reason taxonomy proof of concept

Related: [#3017](https://github.com/infection/infection/issues/3017),
[#3018](https://github.com/infection/infection/issues/3018), and
[#3498](https://github.com/infection/infection/issues/3498), area 9.

## Status

This branch is an intentionally incomplete proof of concept based on `origin/master` at
`03bac9f7b`. It tests the shape of the result boundary; it is not a proposed reporter or
configuration migration.

The recommendation remains to resolve the RFCs and vocabulary before merging an
implementation. The TestFramework rework is changing the same boundary.

## Problem

`DetectionStatus` currently mixes evaluator identity (`KILLED_BY_TESTS`), outcome
(`ESCAPED`), failure reason (`TIMED_OUT`) and pre-evaluation decisions (`IGNORED`). A
`MutantExecutionResult` retains only the last process result, so a PHPUnit pass followed by
a PHPStan detection loses the PHPUnit evidence. MSI rules are separately encoded in
`MetricsCalculator` and `Calculator`.

For example, the current execution chain may be:

```text
PHPUnit executes the mutant and passes
    -> PHPStan executes the same mutant and reports an error
```

The final `MutantExecutionResult` says `KILLED_BY_STATIC_ANALYSIS`, but it does not retain
that PHPUnit ran first and did not detect the mutation. The status also encodes both the
evaluator (`STATIC_ANALYSIS`) and the outcome (`KILLED`) in one value. This makes it hard to
explain the evaluation, add another evaluator, or define metrics independently of process
status names.

`not generated` is deliberately outside this POC: if no mutation exists there can be no
mutation evaluation result. It belongs to selection diagnostics.

## Model exercised by this branch

The POC keeps `DetectionStatus` as a compatibility view and adds a more structured result
alongside it:

```text
DetectionStatus from each process
    -> EvaluationAttempt for each evaluator
    -> ordered list of attempts
    -> MutationEvaluationResultReducer
    -> final MutationOutcome and EvaluationReason
    -> MsiEligibilityPolicy
```

It introduces three concepts:

1. `EvaluationAttempt` records an evaluator id, bounded outcome and reason, command,
   output and active duration.
2. `MutationEvaluationResult` contains an ordered, non-empty attempt list and a final
   outcome produced by `MutationEvaluationResultReducer`.
3. `MsiEligibilityPolicy` maps a final outcome to numerator and denominator inclusion,
   including the existing timeout switch.

The old `DetectionStatus` remains the compatibility view used by reporters. Evaluator ids
are strings (`phpunit`, `phpstan`, `mago`, `infection`) rather than enum cases, so adding an
evaluator does not expand the outcome taxonomy.

The process container is the POC aggregation boundary. It preserves each completed process
result, reduces the ordered attempts after the evaluator chain finishes, and returns the
last legacy `MutantExecutionResult` enriched with the complete evaluation result. Existing
reporters therefore continue to see their old status and process fields.

### Attempt outcome and reason

An attempt outcome deliberately answers only whether one evaluator detected the mutation:

| Existing `DetectionStatus` | Attempt outcome | Attempt reason |
| --- | --- | --- |
| `KILLED_BY_TESTS` | `DETECTED` | `TEST_FAILURE` |
| `KILLED_BY_STATIC_ANALYSIS` | `DETECTED` | `STATIC_ANALYSIS_FAILURE` |
| `ESCAPED` | `UNDETECTED` | `PASSED` |
| `ERROR` | `INCONCLUSIVE` | `PROCESS_ERROR` |
| `TIMED_OUT` | `INCONCLUSIVE` | `TIMEOUT` |
| `SYNTAX_ERROR` | `INCONCLUSIVE` | `SYNTAX_ERROR` |
| `SKIPPED` | `NOT_EVALUATED` | `TIME_BUDGET` |
| `NOT_COVERED` | `NOT_EVALUATED` | `NO_COVERING_TESTS` |
| `IGNORED` | `NOT_EVALUATED` | `IGNORED` |

The reason retains why that outcome occurred without adding evaluator-specific cases to the
outcome enum. The evaluator id remains separate: both PHPUnit and a static analyser can
produce `DETECTED`, but for different reasons.

### Aggregation example

For the PHPUnit then PHPStan chain above, the enriched result is conceptually:

```text
attempts:
  1. phpunit / UNDETECTED / PASSED
  2. phpstan / DETECTED / STATIC_ANALYSIS_FAILURE

final outcome: COVERED
resolution reason: STATIC_ANALYSIS_FAILURE
legacy status: KILLED_BY_STATIC_ANALYSIS
```

The reducer currently gives precedence to the first `DETECTED` attempt. If no attempt
detects the mutation, the last attempt determines the final result. This describes the
current short-circuiting evaluator chain; it is not yet a general retry or consensus model.

### MSI eligibility

`MsiEligibilityPolicy` is intended to keep score calculation separate from evaluation. It
classifies a result as one of:

- excluded from the scores;
- included only in the overall MSI denominator;
- included in both the overall and covered-code denominators;
- included in the numerator and both denominators.

This also isolates the existing `timeoutsAsEscaped` option: a timeout remains a suspicious
evaluation result, while the policy decides whether it contributes to the numerator. The
policy is not connected to the production metrics calculators in this POC.

## Known taxonomy problem

The current final `MutationOutcome` vocabulary is not a viable recommendation yet.
`COVERED` means that an evaluator detected the mutation, while `NOT_COVERED` currently
combines two different situations:

1. an evaluator ran but did not detect the mutation (`UNDETECTED` + `PASSED`, the legacy
   `ESCAPED` case);
2. no test covered the mutation, so it was not evaluated (`NOT_EVALUATED` +
   `NO_COVERING_TESTS`).

Those situations must remain distinguishable for both reporting and metrics. In mutation
testing, a mutation can be covered by tests and still escape, so "covered" is not a synonym
for "detected".

The current reducer maps both situations to `MutationOutcome::NOT_COVERED`. The current MSI
policy then puts every `NOT_COVERED` result only in the overall denominator. If connected to
production as written, it would therefore omit an escaped but test-covered mutation from
the covered-code MSI denominator. The tests do not currently exercise the
`UNDETECTED` + `PASSED` policy case.

This is useful evidence from the POC rather than a settled design. A production taxonomy
needs distinct final states for, at minimum:

- covered and detected;
- covered and undetected (escaped);
- not covered and therefore not evaluated;
- inconclusive;
- skipped or ignored.

## Deliberate limitations

- The reducer only models the current short-circuiting chain: the first detection wins;
  otherwise the last attempt resolves the mutation.
- Retries, flakiness and mutant-induced versus harness-induced crashes remain unspecified.
- Evaluator versions and external evidence references are not populated yet.
- Synthetic ignored/skipped/not-covered results contain one `infection` attempt. There is
  no empty attempt list.
- Existing reporters do not serialize attempts. Their compatibility migration still needs
  an RFC decision table.
- The MSI policy is executable and tested, but the existing aggregate calculator is not
  replaced. Doing that honestly requires carrying final classifications through collectors
  and deciding output compatibility first.
- The final `COVERED`/`NOT_COVERED` vocabulary and its MSI mapping are known to collapse an
  escaped mutation with a mutation that has no covering tests, as described above.

## How to test the POC

Install dependencies if needed, then run the focused tests:

```bash
composer install
vendor/phpunit/phpunit/phpunit tests/phpunit/Mutant/Evaluation
vendor/phpunit/phpunit/phpunit tests/phpunit/Process/MutantProcessContainerTest.php
vendor/phpunit/phpunit/phpunit tests/phpunit/Mutant/TestFrameworkMutantExecutionResultFactoryTest.php
vendor/phpunit/phpunit/phpunit tests/phpunit/StaticAnalysis/PHPStan/Mutant/PHPStanMutantExecutionResultFactoryTest.php
vendor/phpunit/phpunit/phpunit tests/phpunit/StaticAnalysis/Mago/Mutant/MagoMutantExecutionResultFactoryTest.php
```

For a manual end-to-end smoke test, run Infection with a static analyser configured and
inspect the result in a debugger or temporary subscriber at
`MutantExecutionResult::getMutationEvaluationResult()`. An escaped PHPUnit attempt followed
by a PHPStan or Mago detection should expose two ordered attempts while
`getDetectionStatus()` remains `KILLED_BY_STATIC_ANALYSIS`.

Run project checks to see the wider integration state:

```bash
make cs
make autoreview
make test-unit
```

### Verification on this branch

The following checks passed on 2026-08-25 with PHP 8.4.22:

- the focused evaluator, reducer, policy and process-container tests;
- `make cs`;
- `make phpstan`;
- `make autoreview`, including PHPat, Mago, the AutoReview suite, Rector, the
  collision detector and zizmor.

## Decisions still required

Before production work, reconcile #3017 and #3018 in one decision table covering generated,
selected and evaluated state; attempt outcome/reason; final outcome; MSI treatment; and
user-facing labels. Define reduction and retry evidence, then specify compatibility for
JSON, summary JSON, Stryker, TeamCity, text and console output.

This is a durable metric, architecture and vocabulary choice with credible alternatives.
No existing ADR covers it, so it is a candidate for a new ADR after the RFCs are resolved;
this POC document is not that ADR.
