# Evaluating Mutator Performance

This guide describes how to evaluate an Infection [mutator][Nomenclature]. It is intended for contributors who are
deciding whether to add, change, retain, or remove a mutator.

The evaluation has three independent dimensions:

1. **Validity:** does the mutator generate syntactically valid transformations on real projects?
2. **Actionability:** how often does the transformation identify a specific, justified improvement?
3. **Cost:** how much machine time and memory does the mutator consume?

These dimensions are reported separately because a single score would obscure trade-offs: an inexpensive mutator
can generate mostly non-actionable mutations, while a highly actionable mutator can still be too expensive to enable
routinely.

## Scope

The unit under evaluation is the mutator, not the subject's test suite or development team. The evaluation ends once
a mutation has been classified as actionable or non-actionable. It does not consider whether the affected developer
regards an actionable improvement as [productive][Nomenclature], has the capacity to implement it, or chooses to do
so.

The results are conditional on the selected subjects, revisions, tests, PHP versions, and execution environment.
They support a decision about the mutator for a declared population, but do not establish a universal property of
the transformation.

There is no universal acceptance threshold. The threshold or decision rule is established before collecting
results. If Infection adopts stable project-wide thresholds, that policy is a candidate for an
[Architecture Decision Record](../adr/README.md).

## Evaluation Process

### 1. Evaluation Claim

The evaluation claim describes:

- the transformation and the testing weakness or defect it is intended to expose;
- the contexts in which it does and does not apply;
- positive, negative, and boundary examples;
- the population of PHP projects covered by the conclusion;
- the decision being considered and the evidence required to make it.

For a change to an existing mutator, the claim includes the expected effect. For example, a new guard may be
intended to remove equivalent mutations without removing actionable ones.

### 2. Transformation Review

The review of `canMutate()` and `mutate()` precedes the corpus study and establishes whether the mutator:

- changes behaviour which a developer could reasonably want a test or static analyser to detect;
- does not deliberately produce syntax errors, guaranteed fatal errors, or infinite loops;
- avoids known equivalent, redundant, irrelevant, and arid mutations when they can be suppressed cheaply and
  safely;
- preserves the node attributes needed to materialise the mutant;
- does not unintentionally duplicate another enabled mutator.

The canonical mutator test covers the accepted, rejected, and boundary examples. Passing it shows that the
implementation matches the declared transformation; it does not establish actionability on real projects.

### 3. Pilot

A pilot runs only the candidate mutator on a few heterogeneous subjects. When the pilot is sufficiently small, every
mutation is inspected. The pilot identifies implementation faults, refines the classification rules, estimates the
runtime, and informs the sample size for the main study.

Pilot observations are excluded from the main result if the mutator or protocol changed in response to them.

### 4. Representative Corpus Evaluation

The candidate runs against pinned revisions of subjects representative of the declared population. Validity,
execution status, and cost data are collected for every generated mutation. Either every generated mutation or a
random sample is classified for actionability.

The actionability sample is selected independently of coverage and execution status. Selecting only escaped or
uncovered mutations would measure the feedback produced by those particular test suites rather than the quality of the
mutator's transformations.

When two implementations are compared, both are run on the same revisions and environment. The comparison reports
mutations added, removed, and shared, together with paired project-level results. Otherwise, aggregate totals can
hide a regression in one project behind a larger project.

### 5. Evidence and Decision

The evaluation report contains raw counts, proportions, uncertainty intervals, cost distributions, protocol
deviations, and significant negative examples. It discusses validity, actionability, and cost separately before
presenting the decision and its rationale.

## Study Populations and Records

The following populations are used consistently:

- **generated mutation:** a `Mutation` produced by the candidate mutator;
- **validity-checked mutation:** a generated mutation whose materialised code was checked for syntactic validity;
- **evaluated mutation:** a generated mutation for which mutant evaluation was attempted;
- **reviewed mutation:** a generated mutation submitted for actionability classification;
- **classified mutation:** a reviewed mutation conclusively classified as actionable or non-actionable;
- **actionable mutation:** a classified mutation which identifies a specific, justified improvement to the tests or
  subject.

A mutation may belong to several populations. For example, a `not covered` mutation is generated but not evaluated
because Infection does not start a mutant process for it. Each metric identifies its denominator, avoiding the
ambiguous term _all mutations_.

Each observation is identified by its mutation ID, while the subject and revision are recorded separately. An ID
identifies a particular generated mutation, not the same semantic change across revisions. The record contains at
least:

- Infection version, mutator name, mutation ID, subject, and revision;
- source location and mutation diff;
- validity result and native `DetectionStatus`;
- actionability classification and rationale, when reviewed;
- mutant and matched-baseline wall time and peak resident memory where available;
- selected test count, timeout, worker count, and whether evaluation stopped early.

## Metrics

### Validity

#### Syntactic Validity Rate

```math
\frac{\text{validity-checked mutations without a syntax error}}
     {\text{validity-checked mutations}}
```

Each generated mutation is materialised and parsed or linted independently of coverage. When this is impractical,
Infection's `DetectionStatus::SYNTAX_ERROR` provides the operational signal and the denominator is limited to
mutations for which Infection attempted mutant evaluation. The report states which procedure was used.

A value of 1 means that no syntactically invalid transformation was observed. A syntax error normally indicates a
mutator defect: a parser detecting the mutation provides no information about the subject's tests. The `error` and
`timed out` statuses are not syntax errors because they can be legitimate consequences of a valid behavioural
change. They are reported separately.

TODO: Confirm what happens when PHP-Parser cannot print a mutation.

<details>
<summary>Research basis and terminology</summary>

The literature describes mutations which cannot be compiled as _invalid_, _uncompilable_, or _stillborn_. Google's
practical mutation-testing criteria require mutants to be syntactically valid because compiler detection does not
provide useful test feedback [1]. Tool evaluations likewise report compilation errors separately from killed and
surviving mutants [6]. This guide uses _syntactic validity rate_ as an operational term; it is not an established
term in the literature.

</details>

### Actionability

#### Actionability Rate

```math
\frac{\text{actionable mutations}}{\text{classified mutations}}
```

This metric estimates how often the mutator's generated transformations identify a specific, justified improvement.
When the complete generated population is classified, the result is the population proportion rather than an
estimate.

Each reviewed mutation receives one of the following classifications:

- **actionable — tests:** add or strengthen a test.
- **actionable — subject:** correct a defect in the subject.
- **non-actionable — equivalent:** the mutant and subject have the same observable behaviour.
- **non-actionable — redundant:** the same testing requirement is already represented by another mutation.
- **non-actionable — irrelevant or arid:** detecting the change would not exercise a meaningful requirement.
- **cannot determine:** the available evidence is insufficient.

`Cannot determine` represents a missing classification rather than a third actionability outcome. Additional context
or another reviewer may resolve it. Unresolved cases are excluded from the formula, but their count and proportion
of reviewed mutations are reported separately. A high unresolved proportion weakens the result and cannot improve
the apparent actionability rate.

The report retains one row per reviewed mutation:

| Mutation ID | Subject and revision | Classification | Proposed improvement or rationale | Reviewer |
|-------------|----------------------|----------------|-----------------------------------|----------|
| `…`         | `vendor/project@…`   | Actionable — tests | Add a boundary assertion for `…` | `…`      |
| `…`         | `vendor/project@…`   | Non-actionable — equivalent | Both forms return `…` | `…` |
| `…`         | `vendor/project@…`   | Cannot determine | Domain contract is unavailable | `…` |

The report includes the raw counts underlying the rate and a confidence interval appropriate to the sampling design.
It also includes the non-actionable subcategories, which indicate whether the applicability guards, duplicate
suppression, or transformation may require reconsideration.

<details>
<summary>Research basis and terminology</summary>

Industrial mutation-testing research distinguishes a mutation that leads to a concrete test improvement from the
affected developer's subsequent judgement that the improvement is productive [1, 2, 3]. This guide measures only the
first concept and uses _actionable mutation_ as defined in Infection's [nomenclature][Nomenclature].

Equivalent-mutant detection is undecidable in general and commonly requires manual analysis [4]. Manual
classification can therefore be uncertain and reviewer-dependent. Random sampling, explicit categories, retained
unknowns, independent review, and uncertainty intervals make those limitations visible instead of converting them
silently into favourable results.

This guide uses _actionability rate_ as an operational term; it is not an established metric in the literature.

</details>

#### Reviewer Agreement

For a validation subset reviewed independently by at least two reviewers, agreement is calculated as:

```math
\frac{\text{mutations given the same classification by all reviewers}}
     {\text{mutations in the validation subset}}
```

The report includes the raw agreement and full disagreement table. A chance-corrected statistic such as
[Cohen's kappa][CohensKappa] may be added when its assumptions fit the number of reviewers and categories, but it
does not replace the raw data.

Low agreement indicates that the classification rule or available context is insufficiently reproducible. In that case,
the protocol is refined and the classification repeated; incompatible judgements are not averaged.

### Computational Cost

Mutation generation is measured separately from mutant evaluation. Generating fewer mutations and evaluating each
mutation faster are different improvements and can have different effects on actionability.

<details>
<summary>Research basis and terminology</summary>

Pizzoleto et al.'s systematic review covers 153 primary studies and identifies 18 metrics used to measure mutation
testing costs [4]. The three most common are the number of mutants executed, used by 66 studies; mutant execution
speed-up, used by 36; and the number of tests required, used by 25. The review also identifies separate speed-up
metrics for mutant generation, compilation, execution, and complete mutation analysis. This evidence supports
measuring workload and execution time separately and comparing execution time with a baseline rather than
interpreting an isolated duration.

Test-prioritisation research demonstrates that test selection and ordering affect mutant execution costs because a
test that kills a mutant can end its evaluation early [9]. Studies of regression and parallel mutation testing
report actual time saved, analysis overhead, total execution time, and speed-up for fixed execution configurations
[4]. These results support recording the selected tests, termination status, end-to-end time, and worker count.

Memory is not among the recurring cost metrics identified by the systematic review. However, Performance Mutation
Testing treats execution time and memory as observable non-functional properties, establishes timing baselines by
repeatedly running the original program, and proposes profiling memory deviations against the original program [10].
That study evaluates performance-mutant behaviour rather than mutation-tool overhead. It therefore supports the
baseline principle, although the memory measurements below remain specific to this methodology.

</details>

<details>
<summary>Why the baseline and aggregation matter</summary>

An absolute mutant duration primarily reflects its selected tests. A mutation that takes ten seconds when its tests
normally take nine seconds has a different cost profile from one that takes five seconds when its tests normally take
one second. The matched baseline reveals this difference.

The most reliable baseline runs the same selected tests against the original code through the same child-process path,
bootstrap, environment, and timeout. Infection's nominal test time can be used as an estimate when that control run
is unavailable, although it excludes process startup and may not reproduce the cost of running the tests
together.

Detection status remains relevant to the interpretation. A killed mutant may stop at the first failing test, whereas an
escaped mutant normally runs every selected test. Time distributions are therefore separated by status. Timeouts are
reported as censored observations rather than ordinary durations.

Time is additive, whereas peak memory is not. Summing the peak memory of sequential mutant processes does not describe
the memory required by the run. Per-mutant memory is compared with its matched baseline; run-level memory is the
maximum combined resident memory of the concurrently active processes.

A mutation may legitimately make the subject slower or increase its memory consumption. This remains part of its
evaluation cost, but does not by itself indicate an Infection performance defect.

</details>

#### Mutation-Generation Cost

For a fixed corpus, the reported measurements are generation wall time, peak memory, and generation throughput:

```math
\frac{\text{generated mutations}}{\text{generation wall time}}
```

Throughput is useful for comparing implementations that generate the same mutation set. When the set changes, the
count and actionability result accompany it. Higher throughput caused only by omitting intended mutations is not an
improvement in mutator quality.

The `make benchmark_mutation_generator` command provides a controlled generation benchmark, while
`make profile_mutation_generator` identifies hotspots. The [benchmarking guide][Benchmarking] describes their use.
The benchmark does not measure mutant evaluation or actionability.

#### Evaluation Workload

```math
\sum_{m \in M} n_m
```

Here, $M$ is the set of evaluated mutations, and $n_m$ is the number of tests selected for mutation $m$. The report
also contains the number of generated and evaluated mutations and the expected duration of each selected test set
when run against the original code.

These counts describe the workload that produces the measured time. They do not measure mutator quality.

#### Evaluation-Time Overhead

```math
\Delta T_m = T_m - T_m^{(0)}
```

```math
A_m^T = \frac{T_m}{T_m^{(0)}}
```

Here, $T_m$ is the wall time for mutation $m$, and $T_m^{(0)}$ is the wall time for the same selected tests running
against the original code. The absolute overhead $\Delta T_m$ expresses the additional time, while the amplification
$A_m^T$ allows comparison between mutations with differently sized test selections. An amplification near 1 means
that mutant evaluation took approximately the baseline time.

The report contains the absolute mutant and baseline times, together with the median and p95 overhead and
amplification, stratified by native `DetectionStatus`. Baselines close to zero and timed-out evaluations are reported
separately.

#### Peak-Memory Overhead

```math
\Delta R_m = R_m - R_m^{(0)}
```

```math
A_m^R = \frac{R_m}{R_m^{(0)}}
```

Here, $R_m$ is the peak resident set size for mutation $m$, and $R_m^{(0)}$ is the peak resident set size of the same
selected tests running against the original code under the same process configuration. The report contains the
median and p95 per-mutation overhead $\Delta R_m$ and amplification $A_m^R$. A positive overhead indicates that the
mutant process reached a higher peak memory usage than its baseline.

#### End-to-End Latency and Total Resource Cost

The aggregate evaluation-time amplification is:

```math
A^T = \frac{\sum_{m \in M} T_m}
           {\sum_{m \in M} T_m^{(0)}}
```

This metric compares the total mutant-process work with the expected work of running the corresponding selected tests
against the original code. End-to-end wall time, measured from mutation generation through reporting, remains a
separate user-visible measurement because parallel scheduling affects it.

At the run level, memory is reported as the maximum combined resident set size of the active Infection and mutant
processes. Absolute values and paired absolute and relative differences are reported per subject. The worker count
remains fixed between comparisons.

### Reliability Check

Outcome instability is a validity check on the experiment, not a quality metric for the mutator:

```math
\frac{\text{mutations whose native status changes across identical runs}}
     {\text{mutations evaluated repeatedly}}
```

The rerun schedule is fixed before the first result is observed. The unmutated test suite is repeated under the same
conditions. The resulting record includes status transitions, failing tests, failure signatures, and runtime
dispersion. Instability associated with baseline failures is reported separately from instability observed only
under mutation.

High instability indicates that observed differences in status and timing may result from test or environmental
noise. It does not, by itself, show that the mutator is unreliable.

<details>
<summary>Research basis and terminology</summary>

Flaky test outcomes and non-deterministic coverage can change mutation-testing results. Shi, Bell, and Marinov found
non-deterministic coverage even for tests whose pass/fail outcome appeared stable, and showed that score differences
can be smaller than variation caused by flakiness [5]. Repeated baseline and mutant runs are therefore needed to
quantify experimental noise.

</details>

## Metrics Which Do Not Evaluate the Mutator

Infection's complete native `DetectionStatus` distribution is retained as diagnostic context. Statuses remain
separate during collection; any grouping is defined during analysis and identifies the Infection version.

Mutation score is not a mutator-performance metric. It primarily describes how a particular test suite detects a
particular mutation set. A high killed proportion can mean that a mutator creates obvious or redundant mutations; a
low proportion can mean weak tests, equivalent mutations, or subtle actionable gaps. Coverage rate is similarly a
property of the selected subjects, tests, tracer, and mutation locations. Neither provides sufficient evidence on
its own to accept or reject a mutator.

The number of generated mutations is workload, not quality. It is reported to explain total cost and corpus
coverage, but a mutator is not considered better merely because it produces more or fewer mutations.

<details>
<summary>Research basis and terminology</summary>

The conventional mutation score is the proportion of non-equivalent mutants that a test suite kills [4, 7]. Its
denominator and interpretation concern test effectiveness. Research on selective mutation also shows why mutation
count alone is insufficient: reducing the mutant set is useful only when the reduced set preserves the relevant
testing information [4, 8].

</details>

## Experimental Design

A representative corpus extends beyond Infection itself and includes, as appropriate:

- small, medium, and large PHP projects;
- different supported PHP and PHPUnit versions;
- unit-heavy and integration-heavy test suites;
- frameworks and libraries;
- multiple revisions of a project.

Before data collection, the study protocol establishes:

- pinned versions of PHP, dependencies, and extensions;
- fixed CPU allocation, worker count, timeout policy, and Infection configuration;
- the sampling frame, randomisation procedure, classifications, rerun count, and exclusions;
- the actionability sample size required to achieve the desired precision;
- the weighting applied to projects;
- separate cold-cache and warm-cache measurements.

Mutations within the same file, revision, and project share source code and tests, so they are not independent.
Per-project results are published, and cross-project estimates account for clustering. The report states the unit of
analysis and includes confidence intervals rather than only point estimates.

Comparisons use a randomised or alternating run order and paired observations from the same project revisions. They
report absolute and relative effect sizes. Statistical significance alone does not establish practical importance.

## Recommended Report

The recommended report for each mutator contains:

1. the declared transformation, target population, decision rule, corpus, and environment;
2. syntactic validity rate;
3. the actionability-classification table, raw category counts, actionability rate, unresolved proportion, reviewer
   agreement, and uncertainty intervals;
4. generated and evaluated mutation counts plus the native status distribution;
5. generation wall time, throughput, and peak memory;
6. selected-test workload, mutant and matched-baseline evaluation times, and their overhead and amplification,
   stratified by status;
7. mutant and matched-baseline peak memory, their overhead and amplification, and maximum combined run-level memory;
8. end-to-end wall time and aggregate evaluation-time amplification;
9. outcome and runtime instability;
10. per-project results, aggregate method, protocol deviations, and the final decision with its rationale.

No individual metric determines the decision. The evidence supports a mutator when its implementation is valid, its
mutations are sufficiently actionable for the declared population, and its computational cost satisfies the decision
rule recorded before the study.

## References

1. Goran Petrović, Marko Ivanković, Gordon Fraser, and René Just, "Practical Mutation Testing at Scale: A View
   from Google," _IEEE Transactions on Software Engineering_, vol. 48, no. 10, pp. 3900–3912, 2022,
   doi: [10.1109/TSE.2021.3107634][PracticalMutationTesting].
2. Goran Petrović and Marko Ivanković, "State of Mutation Testing at Google," _Proceedings of the 40th
   International Conference on Software Engineering: Software Engineering in Practice_, pp. 163–171, 2018,
   doi: [10.1145/3183519.3183521][StateOfMutationTesting].
3. Goran Petrović, Marko Ivanković, Gordon Fraser, and René Just, "Does Mutation Testing Improve Testing
   Practices?", _Proceedings of the 43rd International Conference on Software Engineering_, pp. 910–920, 2021,
   doi: [10.1109/ICSE43902.2021.00087][MutationTestingPractices].
4. Alessandro Viola Pizzoleto, Fabiano Cutigi Ferrari, Jeff Offutt, Leo Fernandes, and Márcio Ribeiro, "A Systematic
   Literature Review of Techniques and Metrics to Reduce the Cost of Mutation Testing," _Journal of Systems and
   Software_, vol. 157, 2019, doi: [10.1016/j.jss.2019.07.100][MutationCostReview].
5. August Shi, Jonathan Bell, and Darko Marinov, "Mitigating the Effects of Flaky Tests on Mutation Testing,"
   _Proceedings of the 28th ACM SIGSOFT International Symposium on Software Testing and Analysis_, pp. 112–122,
   2019, doi: [10.1145/3293882.3330568][FlakyTests].
6. Y. Ivanova and A. Khritankov, "RegularMutator: A Mutation Testing Tool for Solidity Smart Contracts,"
   _Procedia Computer Science_, vol. 178, pp. 75–83, 2020,
   doi: [10.1016/j.procs.2020.11.009][RegularMutator].
7. Yue Jia and Mark Harman, "An Analysis and Survey of the Development of Mutation Testing," _IEEE Transactions
   on Software Engineering_, vol. 37, no. 5, pp. 649–678, 2011,
   doi: [10.1109/TSE.2010.62][MutationSurvey].
8. Lingming Zhang, Milos Gligoric, Darko Marinov, and Sarfraz Khurshid, "Operator-Based and Random Mutant
   Selection: Better Together," _Proceedings of the 28th IEEE/ACM International Conference on Automated Software
   Engineering_, pp. 92–102, 2013, doi: [10.1109/ASE.2013.6693070][MutantSelection].
9. Lingming Zhang, Darko Marinov, and Sarfraz Khurshid, "Faster Mutation Testing Inspired by Test Prioritization
   and Reduction," _Proceedings of the 2013 International Symposium on Software Testing and Analysis_, 2013,
   doi: [10.1145/2483760.2483782][FasterMutationTesting].
10. Pedro Delgado-Pérez, Ana Belén Sánchez, Sergio Segura, and Inmaculada Medina-Bulo, "Performance Mutation
    Testing," _Software Testing, Verification and Reliability_, vol. 30, no. 5, 2020,
    doi: [10.1002/stvr.1728][PerformanceMutationTesting].

[Benchmarking]: benchmarking.md
[CohensKappa]: https://doi.org/10.1177/001316446002000104
[FasterMutationTesting]: https://doi.org/10.1145/2483760.2483782
[FlakyTests]: https://doi.org/10.1145/3293882.3330568
[MutantSelection]: https://doi.org/10.1109/ASE.2013.6693070
[MutationCostReview]: https://doi.org/10.1016/j.jss.2019.07.100
[MutationSurvey]: https://doi.org/10.1109/TSE.2010.62
[MutationTestingPractices]: https://doi.org/10.1109/ICSE43902.2021.00087
[Nomenclature]: nomenclature.md
[PerformanceMutationTesting]: https://doi.org/10.1002/stvr.1728
[PracticalMutationTesting]: https://doi.org/10.1109/TSE.2021.3107634
[RegularMutator]: https://doi.org/10.1016/j.procs.2020.11.009
[StateOfMutationTesting]: https://doi.org/10.1145/3183519.3183521
