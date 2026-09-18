import type { IndexedMutation, Metrics, MutatorMetrics, ReviewRecord, Spread } from "./types.ts";
import { classificationKind, NON_ACTIONABLE_CLASSIFICATIONS } from "./classifications.ts";
import { filterByMutator, summariseMutators } from "./parse.ts";

// Native Infection\Mutant\DetectionStatus values (src/Mutant/DetectionStatus.php).
export const STATUS = {
  KILLED_BY_TESTS: "killed by tests",
  KILLED_BY_STATIC_ANALYSIS: "killed by SA",
  ESCAPED: "escaped",
  ERROR: "error",
  TIMED_OUT: "timed out",
  SKIPPED: "skipped",
  SYNTAX_ERROR: "syntax error",
  NOT_COVERED: "not covered",
  IGNORED: "ignored",
} as const;

export const NOT_EVALUATED: ReadonlySet<string> = new Set([STATUS.IGNORED, STATUS.NOT_COVERED, STATUS.SKIPPED]);
export const BAD_STATUSES: ReadonlySet<string> = new Set([
  STATUS.ESCAPED,
  STATUS.ERROR,
  STATUS.TIMED_OUT,
  STATUS.SYNTAX_ERROR,
]);

/**
 * The same formulas as WorkbookBuilder::calculateMetrics() / writeMetrics() in the PHP workbook
 * prototype (devTools/mutator-performance-workbook/src/WorkbookBuilder.php), reimplemented here as
 * the single tested source of truth now that the review tool computes its own metrics client-side.
 */
export function computeMetrics(
  mutations: ReadonlyMap<string, IndexedMutation>,
  reviews: ReadonlyMap<string, ReviewRecord>,
): Metrics {
  let evaluated = 0;
  let invalid = 0;
  let workload = 0;
  let runtime = 0;
  let repeated = 0;
  let unstable = 0;
  let evaluatedObservations = 0;
  let timedObservations = 0;

  for (const { observations } of mutations.values()) {
    const evaluatedStatuses = new Set<string>();
    let hasSyntaxError = false;
    let evaluatedCount = 0;

    for (const obs of observations) {
      if (NOT_EVALUATED.has(obs.detectionStatus)) continue;

      evaluatedCount++;
      evaluatedObservations++;
      evaluatedStatuses.add(obs.detectionStatus);
      workload += obs.tests.length;

      if (obs.decisiveProcess !== null && typeof obs.decisiveProcess.runtimeSeconds === "number") {
        runtime += obs.decisiveProcess.runtimeSeconds;
        timedObservations++;
      }
      if (obs.detectionStatus === STATUS.SYNTAX_ERROR) hasSyntaxError = true;
    }

    if (evaluatedCount > 0) evaluated++;
    if (hasSyntaxError) invalid++;
    if (evaluatedCount >= 2) {
      repeated++;
      if (evaluatedStatuses.size > 1) unstable++;
    }
  }

  let actionable = 0;
  let nonActionable = 0;
  const nonActionableCounts = new Map<string, number>();
  let unresolved = 0;
  let reviewedCount = 0;

  for (const [mutationId, review] of reviews) {
    // A review can outlive the mutation it was for — the report was regenerated and that
    // mutation no longer appears, or REVIEWS_PATH was pointed at a different report. Either way
    // it's not part of *this* report's review progress or actionability.
    if (!mutations.has(mutationId)) continue;

    // Classification is the mandatory field (see renderReviewForm() in the client) — an empty or
    // otherwise unrecognized value isn't a completed review, even though it's a key in the file.
    // The server now deletes a review when its classification is cleared rather than storing it
    // empty, but a record saved before that behaviour shipped can still be sitting on disk.
    const kind = classificationKind(review.classification);
    if (kind === null) continue;

    reviewedCount++;
    if (kind === "actionable") actionable++;
    else if (kind === "non-actionable") {
      nonActionable++;
      nonActionableCounts.set(review.classification, (nonActionableCounts.get(review.classification) ?? 0) + 1);
    } else if (kind === "unresolved") unresolved++;
  }

  const classified = actionable + nonActionable;

  return {
    mutationCount: mutations.size,
    evaluated,
    invalid,
    workload,
    runtime,
    evaluatedObservations,
    timedObservations,
    repeated,
    unstable,
    reviewedCount,
    syntacticValidityRate: evaluated > 0 ? (evaluated - invalid) / evaluated : null,
    actionabilityRate: classified > 0 ? actionable / classified : null,
    nonActionableRates: Object.fromEntries(
      NON_ACTIONABLE_CLASSIFICATIONS.map((
        { value },
      ) => [value, classified > 0 ? (nonActionableCounts.get(value) ?? 0) / classified : null]),
    ),
    unresolvedProportion: classified + unresolved > 0 ? unresolved / (classified + unresolved) : null,
    instabilityRate: repeated > 0 ? unstable / repeated : null,
    meanRuntimeSeconds: timedObservations > 0 ? runtime / timedObservations : null,
    meanTestsSelected: evaluatedObservations > 0 ? workload / evaluatedObservations : null,
  };
}

/**
 * The same metrics, computed once per mutator present in the report. Ordered by mutation count
 * descending, then by name: the mutators carrying the most evidence come first, and the order is
 * the same whichever metric is being looked at, so a comparison is never re-ranked underneath the
 * reader by the metric they happened to pick.
 *
 * Each mutator's metrics are the unchanged computeMetrics() over that mutator's mutations — the
 * formulas exist once, and a per-mutator figure is the same formula over a smaller population.
 */
export function computeMutatorMetrics(
  mutations: ReadonlyMap<string, IndexedMutation>,
  reviews: ReadonlyMap<string, ReviewRecord>,
): MutatorMetrics[] {
  return summariseMutators(mutations)
    .map(({ name, mutationCount }) => ({
      name,
      mutationCount,
      metrics: computeMetrics(filterByMutator(mutations, name), reviews),
    }))
    .sort((a, b) => b.mutationCount - a.mutationCount || a.name.localeCompare(b.name));
}

/**
 * Below this many mutators a quartile is noise rather than a summary — three points cannot describe
 * where "the middle half" is — so no band is drawn at all.
 */
export const MIN_SPREAD_SAMPLE = 5;

/** The linear-interpolation quantile (type 7): what R, NumPy, and Excel's PERCENTILE all return. */
function quantile(sorted: readonly number[], p: number): number {
  const position = (sorted.length - 1) * p;
  const lower = Math.floor(position);

  return sorted[lower] + (position - lower) * ((sorted[lower + 1] ?? sorted[lower]) - sorted[lower]);
}

/**
 * The reference band for one comparison chart, or null when there is nothing worth drawing: too few
 * mutators to place a quartile, or a quartile range of zero. The second case is the common one here
 * — syntactic validity is 100% for almost every mutator — and a band collapsed onto its own median
 * would be ink telling the reader what the bars already say.
 */
export function summariseSpread(values: readonly number[]): Spread | null {
  if (values.length < MIN_SPREAD_SAMPLE) return null;

  const sorted = [...values].sort((a, b) => a - b);
  const p25 = quantile(sorted, 0.25);
  const p75 = quantile(sorted, 0.75);

  if (p25 === p75) return null;

  return { p25, median: quantile(sorted, 0.5), p75 };
}
