# Evaluating Mutator Performance

The performance of a mutator has four independent dimensions:

1. **Technical quality:** does it generate and evaluate its intended transformations without unacceptable tool
   failures?
2. **Actionability:** do its mutations identify specific, justified improvements?
3. **Productivity:** do affected developers consider those improvements worthwhile in their context?
4. **Cost:** how much machine time and developer attention does it consume?

These dimensions must remain separate. A fast mutator which produces noise is not useful, while an insightful
mutator may be too expensive to run routinely. Consider the dimensions together rather than reducing them to a
single composite score.

The terms _actionable mutation_ and _productive mutation_ have the meanings defined in
[the nomenclature][Nomenclature]. Neither is an intrinsic property of a mutator. They depend on the subject, its
tests, and the developer's context.

## Scope, Audience, and Limitations

This process is for Infection contributors and maintainers deciding whether to add, change, retain, or remove a
mutator. It asks whether that mutator produces enough productive findings to justify its computational cost, review
cost, and risk.
It does not rank a collection of mutators, evaluate the quality of a project's test suite, or attempt to establish
an optimal mutation-operator set.

Productivity is assessed from Infection's perspective. A mutation is productive when the affected developer
considers its specific, justified improvement worthwhile in the subject's context. Whether the team implements that
improvement is irrelevant: implementation also depends on capacity, priority, ownership, and timing, none of which
establish the quality of the mutator.

This deliberately limited process does not independently estimate equivalent-mutation rates, construct dynamic
subsumption relations, compare controlled test-suite variants, or establish coupling with real faults. Equivalent,
redundant, irrelevant, and arid mutations remain relevant as possible explanations for an unproductive finding, but
they do not require separate population metrics. This reduces the strength of the conclusions in exchange for an
assessment which can realistically be performed for one mutator.

Results remain conditional on the selected projects, revisions, test suites, mutations, reviewers, and affected
developers. They support an engineering decision about the mutator; they do not establish universal
properties of the transformation or predict its value for every Infection user.

## Evaluation Process

Use the following stages in order. A candidate which fails an early stage should normally be revised or rejected
before investing in a corpus study.

### 1. State the Claim and Decision

Describe the transformation, the defect or testing weakness it is intended to expose, and the contexts in which it
should and should not apply. Give concrete positive, negative, and boundary examples. For an existing mutator,
describe the proposed decision: retain it, change its applicability or transformation, enable it by default, or
remove it.

Before collecting results, record:

- the population to which the conclusion is intended to apply;
- the evidence required to support the decision;
- which outcomes count as reported findings;
- the validity criteria;
- the sampling and rerun procedures;
- the acceptable computational and review cost.

Do not derive acceptance thresholds after seeing the results. There is no universal threshold at which a mutator
becomes good: the acceptable trade-off is a project policy, while the measurements below are evidence for applying
that policy.

### 2. Review the Transformation

Check the mutator's `canMutate()` and `mutate()` behaviour with representative examples. A suitable transformation:

- changes behaviour which a developer could reasonably want a test or static analyser to detect;
- does not deliberately generate syntax errors, guaranteed fatal errors, or infinite loops;
- avoids known equivalent, redundant, irrelevant, and arid mutations where this can be decided cheaply and safely;
- preserves the source node attributes required to materialise the mutant;
- does not unintentionally duplicate another enabled mutator.

This review catches faults that aggregate measurements can hide. Record uncertain cases instead of adding a complex
suppression heuristic without evidence: every heuristic has its own correctness and generation cost.

The canonical mutator test should exercise the accepted, rejected, and boundary examples. Passing that test proves
that the implementation matches its declared transformation; it does not prove that the transformation is useful.

### 3. Run a Pilot

Run the mutator by itself on a few heterogeneous subjects. Inspect every generated mutation and result if the pilot
is small enough. Use the pilot to find implementation faults, refine the data-collection procedure, estimate the
population and runtime, and calculate the sample size for the main study. Do not include pilot observations in the
confirmatory results when the transformation or protocol changed in response to them.

### 4. Evaluate a Representative Corpus

Apply the experimental design below to a corpus representative of the declared population. Collect objective result
and cost data for every generated mutation, then assess a representative sample of reported findings for
actionability and productivity.

When evaluating a change to an existing mutator, run the old and new implementations on the same revisions and
environment. Compare paired project-level results as well as totals. Report mutations added, removed, and shared by
the two implementations; an aggregate mutation score can conceal a large change in the findings presented to users.

### 5. Make and Record the Decision

Report the measurements, raw counts, uncertainty, protocol deviations, and important negative examples. Discuss
each dimension separately before giving the decision and its rationale. A weakness in one dimension must not be
hidden by a composite score.

When evidence is incomplete, narrow the claim or run another study. Do not treat absence of observed problems as
evidence that the mutator is universally safe or useful.

## What Is Being Measured

The quantitative assessment starts with the `Mutation` objects generated by the mutator. For every generated
mutation, the study records its execution result and cost using the taxonomy supported by that Infection version.

Only reported findings are candidates for human review. A study will commonly include `escaped` mutations and,
when evaluating coverage feedback, `not covered` mutations. Declare the included statuses before collecting data
and assess a representative sample of those findings for actionability and productivity.

```text
Candidate mutator
  └─ Generated mutations
      ├─ Objective data collected for every mutation
      │   ├─ Execution result
      │   └─ Execution cost
      └─ Reported findings sampled for human review
          ├─ Actionability
          └─ Productivity
```

For the execution result, record the exact `DetectionStatus` produced by the executing Infection version. Reports
must identify that version and must not merge statuses for collection. Any grouping used during analysis must be
defined in the report.

The generated mutations are not the whole assessment. Also review the mutator's applicability rules, but keep this
design review separate from the quantitative metrics above. Nodes rejected by `Mutator::canMutate()` do not produce
mutations and therefore cannot be included in metrics over generated mutations. Use representative positive, negative,
and boundary examples to determine whether the mutator accepts and rejects the intended cases.

## Metrics

### Technical Quality

#### Syntactic Validity Rate

```math
\frac{\text{generated mutations} - \text{mutations with SYNTAX_ERROR}}{\text{generated mutations}}
```

Use Infection's `DetectionStatus::SYNTAX_ERROR` as the operational definition of an invalid mutation. Mutators are
required to produce replacements which PHP-Parser accepts and Infection can materialise. A generated mutation which
is not executable is therefore expected to be reported as `SYNTAX_ERROR` during mutant evaluation.

The literature commonly describes such mutations as invalid, uncompilable, or stillborn. Google states the
underlying validity principle explicitly: a mutant should be syntactically valid because a compiler detecting it does
not provide useful test feedback [1][PracticalMutationTesting]. Empirical tool evaluations also report compilation
errors as a result category; RegularMutator, for example, reports them separately from killed and survived mutants
[6][RegularMutator]. _Syntactic validity rate_ is the name used by this methodology for the complement of that
reported error proportion; it is not presented as an established term from the literature.

Do not classify other `DetectionStatus` values as invalid. Runtime failures and timeouts may be legitimate
consequences of a valid behavioural change. Keep the complete native status distribution beside the validity rate so
that this metric does not conceal other tool failures.

### Actionability and Productivity

#### Actionability Precision

```math
\frac{\text{actionable findings}}{\text{reviewed reported mutations}}
```

This describes the quality of the feedback presented to developers. A finding is actionable only when its analysis
identifies a specific, justified improvement to the tests or subject.

#### Actionable Yield

```math
\frac{\text{estimated actionable findings}}{\text{all evaluated mutations}}
```

This describes how much useful feedback a mutator produces overall. Report actionability precision and actionable
yield together: precision alone ignores how rarely a mutator reports findings, while yield alone can hide a large
review burden.

#### Productivity Rate

```math
\frac{\text{productive findings}}{\text{actionable findings assessed by the affected developers}}
```

This records whether affected developers consider a justified improvement worthwhile in their own context. It
distinguishes an expert assessment that a mutation is actionable from the developer's assessment of its productivity.
Capture it explicitly rather than inferring it from implementation, which is influenced by capacity, priority,
ownership, and timing.

Also report the overall productive yield:

```math
\frac{\text{productive findings}}{\text{all findings presented}}
```

Use a fixed response scale and ask for the reason behind the rating. At minimum, distinguish `productive`,
`not productive`, and `cannot assess`. If an ordinal scale is used, publish its full distribution instead of only its
mean. Whether or when the improvement is implemented is outside this assessment.

#### Review Effort

Report the median and tail review time per finding, per actionable finding, and per productive finding. This captures
the cognitive cost which machine-runtime measurements omit. Industrial evidence about how developers respond to
mutation findings provides a basis for evaluating these downstream outcomes
[3][MutationTestingPractices].

### Computational Cost

Instrument these phases separately:

- mutation generation;
- mutant materialisation and process startup;
- test execution;
- static-analysis follow-up;
- reporting.

Cost-reduction research uses several non-interchangeable measures, so retain both the work avoided and the quality
preserved by an optimisation [4][MutationCostReview].

For each mutation, record at least:

- mutator and mutation identifiers;
- project and revision;
- outcome;
- selected test count and their baseline duration;
- wall time and, where available, CPU time;
- peak resident memory;
- whether evaluation terminated early;
- timeout limit;
- worker count and worker utilisation.

#### Evaluation CPU Cost

```math
\frac{\text{sum of mutant-process CPU time}}{\text{evaluated mutations}}
```

This measures consumed machine resources independently of parallelism.

#### End-to-End Latency

Measure wall time from the start of mutation generation until reporting completes. This is the user-visible
performance measure. Report CPU-seconds and wall time together: throughput can improve while total resource
consumption increases.

#### Evaluation Overhead Ratio

```math
\frac{\text{mutant evaluation time}}{\text{baseline duration of the selected tests}}
```

This normalises execution cost for mutations whose covering tests have very different durations.

Stratify all runtime results by outcome. Mutations with the current `killed by tests` status may terminate after the
first failing test, while mutations with the current `escaped` status normally execute every selected test. Comparing
their unstratified runtime introduces outcome bias.

Use medians and p90 or p95 latency with confidence intervals to describe skewed runtimes. Retain means for resource
accounting because total CPU cost is additive. Minima and maxima alone do not reliably characterise a distribution.

#### Cost Effectiveness

```math
\begin{aligned}
&\frac{\text{total CPU-hours or wall time}}{\text{estimated actionable findings}} \\
&\frac{\text{total CPU-hours or wall time}}{\text{productive findings}} \\
&\frac{\text{review minutes}}{\text{actionable findings}} \\
&\frac{\text{review minutes}}{\text{productive findings}}
\end{aligned}
```

These metrics connect cost to useful outcomes. They should only be calculated when actionability and productivity
come from a representative sample or complete population.

### Reliability

#### Outcome Instability

```math
\frac{\text{mutations whose outcome changes}}{\text{mutations evaluated repeatedly}}
```

This detects flaky tests and environmental sensitivity. Flaky outcomes and coverage can materially alter mutation
scores [5][FlakyTests]. Record transitions using the native result taxonomy implemented by the executing version.

Use a fixed rerun schedule which does not depend on the initial mutation outcome. Repeat the unmutated suite under
the same conditions, and record the failing test and failure signature for each run. Report instability associated
with failures also observed in baseline runs separately from instability observed only with a mutation. This
distinction is evidence about the likely source of instability, not proof: a mutation may legitimately expose
nondeterministic subject behaviour.

Report runtime dispersion across identical repetitions. High dispersion weakens comparisons even when the median is
stable.

## Actionability Assessment Protocol

Actionability is contextual and cannot be made wholly objective. Make its assessment reproducible as follows:

1. Randomly sample the statuses declared to be findings, normally `escaped` and, when coverage feedback is in scope,
   `not covered`. Stratify the sample by project and status.
2. Show reviewers the mutation diff, relevant source, tests, and normal Infection diagnostics.
3. Hide the mutator identity where practical.
4. Require one classification:
    - actionable: add or strengthen a test;
    - actionable: fix the subject;
    - non-actionable: equivalent;
    - non-actionable: redundant or already represented;
    - non-actionable: irrelevant or arid;
    - cannot determine.
5. Require a concrete proposed improvement for every actionable classification.
6. Use two independent reviewers on a validation subset.
7. Report raw agreement and an agreement statistic such as Cohen's kappa.
8. Retain disagreements and `cannot determine` results instead of resolving them silently.
9. Weight results back to the population when sampling rates differ between strata.
10. Report sample sizes and uncertainty intervals appropriate to the sampling design and clustering structure.

After the actionability assessment, ask an affected developer to rate the productivity of the proposed improvement
using a fixed scale and provide a reason. Do not ask the expert reviewer to predict the developer's rating, and do not
infer it from implementation activity. Report the response rate and retain non-response and `cannot assess` as
missing evidence rather than negative assessments.

## Experimental Design

Use a corpus which represents Infection's users instead of evaluating only Infection itself. Include:

- small, medium, and large PHP projects;
- different supported PHP and PHPUnit versions;
- unit-heavy and integration-heavy suites;
- frameworks and libraries;
- multiple revisions where practical.

For reliable comparisons:

- pin PHP, dependencies, extensions, CPU allocation, worker count, and timeout policy;
- repeat the original test suite to establish baseline noise;
- randomise mutator and run order;
- measure warm-cache and cold-cache experiments separately;
- repeat executions and publish their dispersion;
- analyse projects as separate subjects so a large project cannot dominate the conclusion;
- publish per-project results and a cross-project estimate with confidence intervals.

Determine human-review sample sizes from the required confidence interval. Use stratified random sampling rather
than selecting mutations which look interesting.

### Infection's Existing Benchmarks

Use `make benchmark_mutation_generator` to detect a change in mutation-generation time and peak memory on
Infection's fixed benchmark corpus. Use `make profile_mutation_generator` to locate generation hotspots. Follow the
measurement guidance in [the benchmarking guide][Benchmarking] when comparing revisions.

This benchmark covers mutation generation, not mutant evaluation or human review. It is useful regression evidence,
but it cannot establish end-to-end cost, actionability, productivity, or value across projects. Add a focused
benchmark only when the existing corpus does not exercise the relevant generation path; do not add a benchmark merely
to restate a correctness test.

### Statistical Analysis

Mutations from the same file, revision, or project share code, tests, and development practices, so they are not
independent observations. State whether a result describes mutations in the sampled corpus or is intended to
generalise across projects. A large project with many mutations may otherwise dominate a pooled result, while giving
every project equal weight answers a different question.

Report per-project results and use consistent revisions and execution environments. Account for clustering when
producing a cross-project estimate. Possible approaches
include resampling whole projects or project-revision pairs and using hierarchical models; neither is suitable for
every corpus, and the chosen method and unit of analysis must be declared.

Report absolute and relative effect sizes with uncertainty intervals rather than relying on statistical significance
alone. Determine sample size using the unit which supports the claim, such as projects for cross-project
generalisation or developers for productivity, rather than using the raw number of mutations in every case.

## Recommended Summary

The primary summary for each mutator should contain:

1. actionability precision and actionable yield with confidence intervals;
2. productivity rate and productive yield with confidence intervals;
3. native result-status distribution and syntactic validity rate;
4. median and p95 mutant-evaluation cost, and total end-to-end latency;
5. CPU-hours and review minutes per estimated actionable and productive finding;
6. outcome instability, sample size, and number of represented projects.

A mutator is a strong candidate for inclusion when it produces sufficiently many productive mutations at an
acceptable computational and review cost. The acceptable balance and strength of evidence remain policy decisions.

If the project adopts thresholds as stable gates for adding, retaining, or removing mutators, record that decision
in an Architecture Decision Record.

## Research Basis

1. Goran Petrović, Marko Ivanković, Gordon Fraser, and René Just, "Practical Mutation Testing at Scale: A View
   from Google," _IEEE Transactions on Software Engineering_, vol. 48, no. 10, pp. 3900–3912, 2022,
   doi: [10.1109/TSE.2021.3107634][PracticalMutationTesting].
2. Goran Petrović and Marko Ivanković, "State of Mutation Testing at Google," _Proceedings of the 40th
   International Conference on Software Engineering: Software Engineering in Practice_, pp. 163–171, 2018,
   doi: [10.1145/3183519.3183521][StateOfMutationTesting].
3. Goran Petrović, Marko Ivanković, Gordon Fraser, and René Just, "Does Mutation Testing Improve Testing
   Practices?", _Proceedings of the 43rd International Conference on Software Engineering_, 2021,
   doi: [10.1109/ICSE43902.2021.00087][MutationTestingPractices].
4. Alessandro Viola Pizzoleto, Fabiano Cutigi Ferrari, Jeff Offutt, Leo Fernandes, and Márcio Ribeiro, "A Systematic
   Literature Review of Techniques and Metrics to Reduce the Cost of Mutation Testing," _Journal of Systems and
   Software_, vol. 157, 2019, doi: [10.1016/j.jss.2019.07.100][MutationCostReview].
5. August Shi, Jonathan Bell, and Darko Marinov, "Mitigating the Effects of Flaky Tests on Mutation Testing,"
   _Proceedings of the 28th ACM SIGSOFT International Symposium on Software Testing and Analysis_, pp. 112–122, 2019,
   doi: [10.1145/3293882.3330568][FlakyTests].
6. Y. Ivanova and A. Khritankov, "RegularMutator: A Mutation Testing Tool for Solidity Smart Contracts,"
   _Procedia Computer Science_, vol. 178, pp. 75–83, 2020,
   doi: [10.1016/j.procs.2020.11.009][RegularMutator].

[Benchmarking]: benchmarking.md
[FlakyTests]: https://doi.org/10.1145/3293882.3330568
[MutationCostReview]: https://doi.org/10.1016/j.jss.2019.07.100
[MutationTestingPractices]: https://doi.org/10.1109/ICSE43902.2021.00087
[Nomenclature]: nomenclature.md
[PracticalMutationTesting]: https://doi.org/10.1109/TSE.2021.3107634
[RegularMutator]: https://doi.org/10.1016/j.procs.2020.11.009
[StateOfMutationTesting]: https://doi.org/10.1145/3183519.3183521
