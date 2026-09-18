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
  repeated: number;
  unstable: number;
  /** Reviews whose mutation is actually in the current report — excludes stale/orphaned entries. */
  reviewedCount: number;
  syntacticValidityRate: number | null;
  actionabilityRate: number | null;
  unresolvedProportion: number | null;
  instabilityRate: number | null;
}
