import assert from "node:assert/strict";
import { indexMutations, parseJsonl } from "./parse.ts";
import { computeMetrics } from "./metrics.ts";
import type { ObservationRecord, ReviewRecord } from "./types.ts";

const fixtureUrl = new URL("../../testdata/sample-report.jsonl", import.meta.url);

async function loadFixtureMutations() {
  const text = await Deno.readTextFile(fixtureUrl);
  const { records, errors } = parseJsonl<ObservationRecord>(text);
  assert.deepEqual(errors, []);
  return indexMutations(records).mutations;
}

function toReviewsMap(records: readonly ReviewRecord[]): Map<string, ReviewRecord> {
  return new Map(records.map((record) => [record.mutationId, record]));
}

Deno.test("computeMetrics: syntactic validity, workload, runtime, and instability on the sample fixture", async () => {
  const mutations = await loadFixtureMutations();
  const metrics = computeMetrics(mutations, new Map());

  // 3 mutations: mutation-1 (escaped, then killed by tests), mutation-2 (syntax error), mutation-3 (not covered).
  assert.equal(metrics.mutationCount, 3);
  assert.equal(metrics.evaluated, 2); // mutation-3 is "not covered" and excluded
  assert.equal(metrics.invalid, 1); // mutation-2
  assert.equal(metrics.syntacticValidityRate, 0.5); // (2 evaluated - 1 invalid) / 2
  assert.equal(metrics.repeated, 1); // mutation-1 has 2 evaluated observations
  assert.equal(metrics.unstable, 1); // mutation-1's status changed between observations
  assert.equal(metrics.instabilityRate, 1);
  assert.equal(metrics.workload, 2); // one selected test per mutation-1 observation
  assert.ok(Math.abs(metrics.runtime - 0.06) < 1e-9); // 0.031 + 0.029
});

Deno.test("computeMetrics: actionability and unresolved proportion are derived from reviews, excluding cannot-determine from the classified denominator", async () => {
  const mutations = await loadFixtureMutations();
  const reviews: ReviewRecord[] = [
    {
      mutationId: "mutation-1",
      reviewer: "a",
      classification: "actionable — tests",
      rationale: null,
      proposedImprovement: null,
      uncertainty: null,
    },
    {
      mutationId: "mutation-2",
      reviewer: "a",
      classification: "non-actionable — equivalent",
      rationale: null,
      proposedImprovement: null,
      uncertainty: null,
    },
    {
      mutationId: "mutation-3",
      reviewer: "a",
      classification: "cannot determine",
      rationale: null,
      proposedImprovement: null,
      uncertainty: null,
    },
  ];

  const metrics = computeMetrics(mutations, toReviewsMap(reviews));

  assert.equal(metrics.actionabilityRate, 0.5); // 1 actionable / (1 actionable + 1 non-actionable)
  assert.equal(metrics.unresolvedProportion, 1 / 3); // 1 cannot-determine / 3 reviewed
  assert.equal(metrics.reviewedCount, 3);
});

Deno.test("computeMetrics: a review for a mutation id no longer in the report is excluded from reviewedCount and actionability", async () => {
  const mutations = await loadFixtureMutations();
  const reviews = toReviewsMap([
    {
      mutationId: "mutation-1",
      reviewer: "a",
      classification: "actionable — tests",
      rationale: null,
      proposedImprovement: null,
      uncertainty: null,
    },
    {
      // Not one of the 3 mutations in the fixture — e.g. left over from a regenerated report.
      mutationId: "mutation-does-not-exist-anymore",
      reviewer: "a",
      classification: "actionable — subject",
      rationale: null,
      proposedImprovement: null,
      uncertainty: null,
    },
  ]);

  const metrics = computeMetrics(mutations, reviews);

  assert.equal(metrics.reviewedCount, 1); // the orphaned entry doesn't count
  assert.equal(metrics.actionabilityRate, 1); // not diluted/skewed by the orphaned entry either
});

Deno.test("computeMetrics: a stored review with an empty classification does not count as reviewed", async () => {
  const mutations = await loadFixtureMutations();
  const reviews = toReviewsMap([
    {
      mutationId: "mutation-1",
      reviewer: "a",
      classification: "actionable — tests",
      rationale: null,
      proposedImprovement: null,
      uncertainty: null,
    },
    {
      // A record saved before the server started deleting empty-classification reviews instead
      // of storing them — the mandatory field was left blank, so this mutation isn't reviewed.
      mutationId: "mutation-2",
      reviewer: null,
      classification: "",
      rationale: "typed before I picked a classification, back when that used to persist",
      proposedImprovement: null,
      uncertainty: null,
    },
  ]);

  const metrics = computeMetrics(mutations, reviews);

  assert.equal(metrics.reviewedCount, 1);
  assert.equal(metrics.actionabilityRate, 1);
});

Deno.test("computeMetrics: rates are null rather than divide-by-zero when there is no denominator", () => {
  const metrics = computeMetrics(new Map(), new Map());

  assert.equal(metrics.syntacticValidityRate, null);
  assert.equal(metrics.actionabilityRate, null);
  assert.equal(metrics.unresolvedProportion, null);
  assert.equal(metrics.instabilityRate, null);
  assert.equal(metrics.reviewedCount, 0);
});

Deno.test("computeMetrics: reviews are keyed by mutation id, so a reclassification simply replaces the prior one", async () => {
  const mutations = await loadFixtureMutations();
  const reviews = toReviewsMap([
    {
      mutationId: "mutation-1",
      reviewer: "a",
      classification: "non-actionable — redundant",
      rationale: "changed my mind",
      proposedImprovement: null,
      uncertainty: null,
    },
  ]);

  const metrics = computeMetrics(mutations, reviews);

  assert.equal(metrics.actionabilityRate, 0);
});
