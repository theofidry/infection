import assert from "node:assert/strict";
import { indexMutations, parseJsonl } from "./parse.ts";
import { computeMetrics, computeMutatorMetrics, MIN_SPREAD_SAMPLE, summariseSpread } from "./metrics.ts";
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

/** A review carrying only the two fields the metrics read; the rest are the reviewer's free text. */
function review(mutationId: string, classification: string): ReviewRecord {
  return { mutationId, reviewer: "a", classification, rationale: null, proposedImprovement: null, uncertainty: null };
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

Deno.test("computeMetrics: the non-actionable subcategories share Actionability's denominator, so together they partition the classified mutations", async () => {
  const mutations = await loadFixtureMutations();
  const reviews = toReviewsMap([
    review("mutation-1", "actionable — tests"),
    review("mutation-2", "non-actionable — equivalent"),
    review("mutation-3", "non-actionable — redundant"),
  ]);

  const metrics = computeMetrics(mutations, reviews);
  const rates = metrics.nonActionableRates;

  assert.equal(metrics.actionabilityRate, 1 / 3);
  assert.equal(rates["non-actionable — equivalent"], 1 / 3);
  assert.equal(rates["non-actionable — redundant"], 1 / 3);
  // Zero rather than absent: this subcategory has a denominator, nothing was classified into it.
  assert.equal(rates["non-actionable — irrelevant or arid"], 0);
  // The property the shared denominator buys: the four review rates cover the classified
  // population exactly once, so a reader can subtract one from the others. Tolerance because
  // thirds do not sum to 1 in binary floating point.
  const total = Object.values(rates).reduce<number>((sum, rate) => sum + (rate ?? 0), metrics.actionabilityRate ?? 0);
  assert.ok(Math.abs(total - 1) < 1e-9);
});

Deno.test("computeMetrics: rates are null rather than divide-by-zero when there is no denominator", () => {
  const metrics = computeMetrics(new Map(), new Map());

  assert.equal(metrics.syntacticValidityRate, null);
  assert.equal(metrics.actionabilityRate, null);
  assert.deepEqual(Object.values(metrics.nonActionableRates), [null, null, null]);
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

Deno.test("computeMetrics: means are per observation, and runtime's denominator excludes untimed observations", async () => {
  const metrics = computeMetrics(await loadFixtureMutations(), new Map());

  // mutation-1 twice + mutation-2 once; mutation-3 is "not covered" so no process ran for it.
  assert.equal(metrics.evaluatedObservations, 3);
  // mutation-2 is a syntax error the fixture records no decisive process for, so it is not timed.
  assert.equal(metrics.timedObservations, 2);
  assert.ok(Math.abs((metrics.meanRuntimeSeconds ?? 0) - 0.03) < 1e-9); // 0.06 / 2, not 0.06 / 3
  assert.ok(Math.abs((metrics.meanTestsSelected ?? 0) - 2 / 3) < 1e-9);
});

Deno.test("computeMetrics: means are null rather than zero when nothing was observed", () => {
  const metrics = computeMetrics(new Map(), new Map());

  assert.equal(metrics.meanRuntimeSeconds, null);
  assert.equal(metrics.meanTestsSelected, null);
});

Deno.test("computeMutatorMetrics: one entry per mutator, each scoped to that mutator's mutations", async () => {
  const perMutator = computeMutatorMetrics(await loadFixtureMutations(), new Map());

  // Every fixture mutator generated exactly one mutation, so the count tie breaks on name.
  assert.deepEqual(perMutator.map((m) => m.name), ["FalseValue", "Plus", "TrueValue"]);
  assert.deepEqual(perMutator.map((m) => m.mutationCount), [1, 1, 1]);

  const [falseValue, plus, trueValue] = perMutator;
  assert.equal(falseValue.metrics.evaluated, 0); // its only mutation is "not covered"
  assert.equal(falseValue.metrics.syntacticValidityRate, null);
  assert.equal(plus.metrics.instabilityRate, 1); // escaped, then killed by tests
  assert.equal(plus.metrics.syntacticValidityRate, 1);
  assert.equal(trueValue.metrics.syntacticValidityRate, 0); // its only mutation is a syntax error
  assert.equal(trueValue.metrics.instabilityRate, null); // observed once, nothing to compare
});

Deno.test("computeMutatorMetrics: orders by mutation count descending before name", async () => {
  const mutations = await loadFixtureMutations();
  const extra = mutations.get("mutation-2");
  assert.ok(extra !== undefined);
  mutations.set("mutation-4", { meta: { ...extra.meta, id: "mutation-4" }, observations: extra.observations });

  const perMutator = computeMutatorMetrics(mutations, new Map());

  assert.deepEqual(perMutator.map((m) => [m.name, m.mutationCount]), [
    ["TrueValue", 2],
    ["FalseValue", 1],
    ["Plus", 1],
  ]);
});

Deno.test("summariseSpread: quartiles interpolate linearly (type 7) and ignore the order values arrive in", () => {
  // Shuffled on purpose: the caller passes chart rows, which are sorted by value, not by mutator.
  assert.deepEqual(summariseSpread([4, 1, 5, 2, 3]), { p25: 2, median: 3, p75: 4 });
  assert.deepEqual(summariseSpread([1, 2, 3, 4, 5, 6]), { p25: 2.25, median: 3.5, p75: 4.75 });
});

Deno.test("summariseSpread: no band below the minimum sample, where a quartile would be noise", () => {
  const justUnder = Array.from({ length: MIN_SPREAD_SAMPLE - 1 }, (_, i) => i + 1);

  assert.equal(summariseSpread(justUnder), null);
  assert.notEqual(summariseSpread([...justUnder, MIN_SPREAD_SAMPLE]), null);
});

Deno.test("summariseSpread: no band when the middle half is a single value, as on an all-100% metric", () => {
  assert.equal(summariseSpread([1, 1, 1, 1, 1, 1]), null);
  // One outlier still leaves P25 == P75 == 1: the band would sit on top of its own median.
  assert.equal(summariseSpread([0, 1, 1, 1, 1, 1]), null);
});
