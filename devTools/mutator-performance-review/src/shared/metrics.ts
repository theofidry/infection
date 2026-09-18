import type { IndexedMutation, Metrics, ReviewRecord } from "./types.ts";
import { classificationKind } from "./classifications.ts";

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

  for (const { observations } of mutations.values()) {
    const evaluatedStatuses = new Set<string>();
    let hasSyntaxError = false;
    let evaluatedCount = 0;

    for (const obs of observations) {
      if (NOT_EVALUATED.has(obs.detectionStatus)) continue;

      evaluatedCount++;
      evaluatedStatuses.add(obs.detectionStatus);
      workload += obs.tests.length;

      if (obs.decisiveProcess !== null && typeof obs.decisiveProcess.runtimeSeconds === "number") {
        runtime += obs.decisiveProcess.runtimeSeconds;
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
    else if (kind === "non-actionable") nonActionable++;
    else if (kind === "unresolved") unresolved++;
  }

  const classified = actionable + nonActionable;

  return {
    mutationCount: mutations.size,
    evaluated,
    invalid,
    workload,
    runtime,
    repeated,
    unstable,
    reviewedCount,
    syntacticValidityRate: evaluated > 0 ? (evaluated - invalid) / evaluated : null,
    actionabilityRate: classified > 0 ? actionable / classified : null,
    unresolvedProportion: classified + unresolved > 0 ? unresolved / (classified + unresolved) : null,
    instabilityRate: repeated > 0 ? unstable / repeated : null,
  };
}
