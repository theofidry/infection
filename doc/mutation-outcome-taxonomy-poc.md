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

`not generated` is deliberately outside this POC: if no mutation exists there can be no
mutation evaluation result. It belongs to selection diagnostics.

## Model exercised by this branch

The POC introduces three concepts:

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
