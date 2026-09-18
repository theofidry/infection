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
