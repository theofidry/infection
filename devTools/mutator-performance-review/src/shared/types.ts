export interface TestDescription {
  method: string;
  file?: string | null;
  executionTimeSeconds?: number | null;
}

export interface DecisiveProcess {
  commandLine: string;
  output: string;
  runtimeSeconds: number;
}

export interface MutationSource {
  file: string;
  startLine: number;
  endLine: number;
}

export interface MutationMeta {
  id: string;
  mutatorName: string;
  mutatorClass: string;
  source: MutationSource;
  diff: string;
}

/** One line of the raw mutator-performance JSONL report. */
export interface ObservationRecord {
  runId: string;
  mutation: MutationMeta;
  tests: TestDescription[];
  detectionStatus: string;
  decisiveProcess: DecisiveProcess | null;
}

/** An observation with the mutation metadata factored out, grouped under its mutation. */
export interface Observation {
  runId: string;
  detectionStatus: string;
  tests: TestDescription[];
  decisiveProcess: DecisiveProcess | null;
}

export interface IndexedMutation {
  meta: MutationMeta;
  observations: Observation[];
}

/** One line of the reviews JSONL log. */
export interface ReviewRecord {
  mutationId: string;
  reviewer: string | null;
  classification: string;
  rationale: string | null;
  proposedImprovement: string | null;
  uncertainty: string | null;
}

export interface ParseResult<T> {
  records: T[];
  errors: string[];
}

export interface Metrics {
  mutationCount: number;
  evaluated: number;
  invalid: number;
  workload: number;
  runtime: number;
  /** Observations Infection ran a mutant process for — the denominator `workload` was summed over. */
  evaluatedObservations: number;
  /**
   * Evaluated observations that actually carry a process runtime — the denominator `runtime` was
   * summed over. Separate from evaluatedObservations because an evaluated observation with no
   * recorded decisive process contributes nothing to the sum, and dividing by it would report a
   * mean lower than any runtime actually measured.
   */
  timedObservations: number;
  repeated: number;
  unstable: number;
  /** Reviews whose mutation is actually in the current report — excludes stale/orphaned entries. */
  reviewedCount: number;
  syntacticValidityRate: number | null;
  actionabilityRate: number | null;
  /**
   * Share of classified mutations in each non-actionable subcategory, keyed by classification
   * value. Same denominator as actionabilityRate, so the three subcategories and the
   * actionability rate partition the classified population and sum to 1.
   */
  nonActionableRates: Readonly<Record<string, number | null>>;
  unresolvedProportion: number | null;
  instabilityRate: number | null;
  /**
   * Per-observation means. Totals answer "how much did this cost in all", which is dominated by how
   * often a mutator fires; the means are what compares one mutator against another.
   */
  meanRuntimeSeconds: number | null;
  meanTestsSelected: number | null;
}

/**
 * Where the middle half of a comparison chart's mutators sit, and the value that splits them.
 * Order statistics rather than mean and standard deviation: these distributions are bounded (a rate
 * cannot exceed 100%) and right-skewed (one slow mutator drags the mean past the 75th percentile),
 * so a symmetric band around a mean would run off the axis and misreport what is typical.
 */
export interface Spread {
  p25: number;
  median: number;
  p75: number;
}

/** One mutator's slice of the report, for comparing mutators against each other. */
export interface MutatorMetrics {
  /** The mutator's short name, or "" when the report didn't record one. */
  name: string;
  mutationCount: number;
  metrics: Metrics;
}
