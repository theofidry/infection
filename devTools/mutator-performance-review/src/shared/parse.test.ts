import assert from "node:assert/strict";
import { indexMutations, parseJsonl, parseReviewsJson } from "./parse.ts";
import type { ObservationRecord, ReviewRecord } from "./types.ts";

Deno.test("parseJsonl skips blank lines and reports malformed ones with their line number", () => {
  const { records, errors } = parseJsonl<{ a: number }>('{"a":1}\n\nnot json\n{"a":2}\n');

  assert.deepEqual(records, [{ a: 1 }, { a: 2 }]);
  assert.equal(errors.length, 1);
  assert.ok(errors[0].startsWith("Line 3:"), errors[0]);
});

Deno.test("parseJsonl rejects a top-level JSON array or scalar as not an object", () => {
  const { records, errors } = parseJsonl('[1,2,3]\n"a string"\n');

  assert.deepEqual(records, []);
  assert.equal(errors.length, 2);
});

function observation(overrides: Partial<ObservationRecord> = {}): ObservationRecord {
  return {
    runId: "run-1",
    mutation: {
      id: "m1",
      mutatorName: "Plus",
      mutatorClass: "Infection\\Mutator\\Arithmetic\\Plus",
      source: { file: "src/Example.php", startLine: 1, endLine: 1 },
      diff: "- a;\n+ b;",
    },
    tests: [],
    detectionStatus: "escaped",
    decisiveProcess: null,
    ...overrides,
  };
}

Deno.test("indexMutations groups repeated observations of the same mutation", () => {
  const first = observation();
  const second = observation({ runId: "run-2", detectionStatus: "killed by tests" });

  const { mutations, order, runIds, warnings } = indexMutations([first, second]);

  assert.deepEqual(order, ["m1"]);
  assert.equal(mutations.get("m1")?.observations.length, 2);
  assert.equal(runIds.size, 2);
  assert.deepEqual(warnings, []);
});

Deno.test("indexMutations warns but keeps the first metadata when a mutation's diff disagrees across observations", () => {
  const first = observation();
  const inconsistent = observation({
    runId: "run-2",
    mutation: { ...first.mutation, diff: "different diff" },
  });

  const { mutations, warnings } = indexMutations([first, inconsistent]);

  assert.equal(mutations.get("m1")?.meta.diff, first.mutation.diff);
  assert.equal(warnings.length, 1);
  assert.ok(warnings[0].includes("m1"));
});

Deno.test("indexMutations skips a record with no runId or mutation and warns", () => {
  const { mutations, warnings } = indexMutations([
    { ...observation(), runId: "" },
  ]);

  assert.equal(mutations.size, 0);
  assert.equal(warnings.length, 1);
});

Deno.test("indexMutations skips a record with no mutation id and warns", () => {
  const { mutations, warnings } = indexMutations([
    observation({ mutation: { ...observation().mutation, id: "" } }),
  ]);

  assert.equal(mutations.size, 0);
  assert.equal(warnings.length, 1);
});

Deno.test("parseReviewsJson treats an empty body as no reviews, not an error", () => {
  const { reviews, error } = parseReviewsJson("");

  assert.equal(error, null);
  assert.equal(reviews.size, 0);
});

Deno.test("parseReviewsJson reads a JSON object keyed by mutation id", () => {
  const record: ReviewRecord = {
    mutationId: "m1",
    reviewer: "a",
    classification: "cannot determine",
    rationale: "more context needed",
    proposedImprovement: null,
    uncertainty: null,
  };

  const { reviews, error } = parseReviewsJson(JSON.stringify({ m1: record }));

  assert.equal(error, null);
  assert.equal(reviews.size, 1);
  assert.deepEqual(reviews.get("m1"), record);
});

Deno.test("parseReviewsJson reports malformed JSON as one error for the whole file", () => {
  const { reviews, error } = parseReviewsJson("not json");

  assert.equal(reviews.size, 0);
  assert.ok(error?.includes("not valid JSON"), error ?? "no error");
});

Deno.test("parseReviewsJson rejects a top-level array or scalar", () => {
  const { reviews, error } = parseReviewsJson("[1,2,3]");

  assert.equal(reviews.size, 0);
  assert.ok(error?.includes("JSON object"), error ?? "no error");
});

Deno.test("parseReviewsJson skips a key whose value is not an object", () => {
  const { reviews, error } = parseReviewsJson(JSON.stringify({ m1: "not an object" }));

  assert.equal(error, null);
  assert.equal(reviews.size, 0);
});
