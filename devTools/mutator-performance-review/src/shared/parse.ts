import type { IndexedMutation, ObservationRecord, ParseResult, ReviewRecord } from "./types.ts";

/**
 * Parses newline-delimited JSON, skipping blank lines and collecting per-line errors instead of
 * throwing, so one malformed line in a large report doesn't block reviewing the rest of it.
 */
export function parseJsonl<T = unknown>(text: string): ParseResult<T> {
  const records: T[] = [];
  const errors: string[] = [];
  const lines = text.split("\n");

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();
    if (line === "") continue;

    try {
      const record: unknown = JSON.parse(line);
      if (typeof record !== "object" || record === null || Array.isArray(record)) {
        throw new Error("expected a JSON object");
      }
      records.push(record as T);
    } catch (error) {
      errors.push(`Line ${i + 1}: ${(error as Error).message}`);
    }
  }

  return { records, errors };
}

export interface MutationIndex {
  mutations: Map<string, IndexedMutation>;
  order: string[];
  runIds: Set<string>;
  warnings: string[];
}

/**
 * Groups observation records by mutation id. Unlike WorkbookBuilder, this does not throw on
 * inconsistent metadata or a duplicate run/mutation pair — a review session should stay usable
 * even when the report has a rough edge; it warns instead and keeps the first metadata seen.
 */
export function indexMutations(records: readonly ObservationRecord[]): MutationIndex {
  const mutations = new Map<string, IndexedMutation>();
  const order: string[] = [];
  const runIds = new Set<string>();
  const warnings: string[] = [];

  for (const record of records) {
    const runId = record?.runId;
    const mutation = record?.mutation;

    if (typeof runId !== "string" || runId === "" || typeof mutation !== "object" || mutation === null) {
      warnings.push("Skipped a record missing runId or mutation.");
      continue;
    }

    const mutationId = mutation.id;
    if (typeof mutationId !== "string" || mutationId === "") {
      warnings.push("Skipped a record with no mutation id.");
      continue;
    }

    runIds.add(runId);

    let indexed = mutations.get(mutationId);
    if (indexed === undefined) {
      indexed = { meta: mutation, observations: [] };
      mutations.set(mutationId, indexed);
      order.push(mutationId);
    } else if (indexed.meta.mutatorName !== mutation.mutatorName || indexed.meta.diff !== mutation.diff) {
      warnings.push(
        `Mutation ${mutationId} has inconsistent metadata between observations; keeping the first one seen.`,
      );
    }

    indexed.observations.push({
      runId,
      detectionStatus: record.detectionStatus,
      tests: Array.isArray(record.tests) ? record.tests : [],
      decisiveProcess: record.decisiveProcess ?? null,
    });
  }

  return { mutations, order, runIds, warnings };
}

export interface ReviewsParseResult {
  reviews: Map<string, ReviewRecord>;
  error: string | null;
}

/**
 * Parses the reviews file: a single JSON object keyed by mutation id (GET /api/reviews' response
 * shape — see server.ts). Unlike parseJsonl, a malformed file is one error for the whole file, not
 * a per-line concern, since there is exactly one JSON document to parse.
 */
export function parseReviewsJson(text: string): ReviewsParseResult {
  if (text.trim() === "") return { reviews: new Map(), error: null };

  let parsed: unknown;
  try {
    parsed = JSON.parse(text);
  } catch (error) {
    return { reviews: new Map(), error: `Reviews file is not valid JSON: ${(error as Error).message}` };
  }

  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) {
    return { reviews: new Map(), error: "Expected the reviews file to contain a JSON object" };
  }

  const reviews = new Map<string, ReviewRecord>();
  for (const [mutationId, record] of Object.entries(parsed as Record<string, unknown>)) {
    if (typeof record !== "object" || record === null) continue;
    reviews.set(mutationId, record as ReviewRecord);
  }

  return { reviews, error: null };
}

export interface MutatorSummary {
  /** The mutator's short name, or "" when the report didn't record one. */
  name: string;
  mutationCount: number;
}

/**
 * The mutators present in the report and how many mutations each generated — the option list for
 * the Metrics tab's scope selector. Sorted by name so the list is stable across reports rather
 * than reordering as counts change between runs; the count rides along because it is the sample
 * size behind every scoped metric, and a mutator with three mutations must not read like one with
 * three hundred.
 */
export function summariseMutators(mutations: ReadonlyMap<string, IndexedMutation>): MutatorSummary[] {
  const counts = new Map<string, number>();

  for (const { meta } of mutations.values()) {
    const name = typeof meta.mutatorName === "string" ? meta.mutatorName : "";
    counts.set(name, (counts.get(name) ?? 0) + 1);
  }

  return [...counts]
    .map(([name, mutationCount]) => ({ name, mutationCount }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * Narrows an indexed report to a single mutator, preserving insertion order. Scoping happens here,
 * on the mutation map, rather than inside computeMetrics(): the metrics keep one definition and one
 * set of tests, and a scoped figure is by construction the same formula over a smaller population.
 */
export function filterByMutator(
  mutations: ReadonlyMap<string, IndexedMutation>,
  mutatorName: string,
): Map<string, IndexedMutation> {
  const filtered = new Map<string, IndexedMutation>();

  for (const [mutationId, mutation] of mutations) {
    if ((mutation.meta.mutatorName ?? "") === mutatorName) filtered.set(mutationId, mutation);
  }

  return filtered;
}
