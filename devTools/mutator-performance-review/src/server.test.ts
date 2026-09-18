import assert from "node:assert/strict";
import { join } from "node:path";
import {
  handleGetReviews,
  handlePostReviews,
  handleReloadEvents,
  handleReport,
  router,
  serveStatic,
} from "./server.ts";

function postReview(path: string, body: unknown): Promise<Response> {
  return handlePostReviews(
    new Request("http://localhost/api/reviews", { method: "POST", body: JSON.stringify(body) }),
    path,
  );
}

Deno.test("handleReport returns 404 with a helpful message when the report file does not exist", async () => {
  const dir = await Deno.makeTempDir();
  const response = await handleReport(join(dir, "missing.jsonl"));

  assert.equal(response.status, 404);
  assert.ok((await response.text()).includes("missing.jsonl"));
});

Deno.test("handleReport returns the raw file bytes unmodified", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "report.jsonl");
  await Deno.writeTextFile(path, '{"a":1}\n{"a":2}\n');

  const response = await handleReport(path);

  assert.equal(response.status, 200);
  assert.equal(await response.text(), '{"a":1}\n{"a":2}\n');
});

Deno.test("handleGetReviews returns an empty JSON object when no reviews have been written yet", async () => {
  const dir = await Deno.makeTempDir();
  const response = await handleGetReviews(join(dir, "reviews.json"));

  assert.equal(response.status, 200);
  assert.equal(response.headers.get("content-type"), "application/json; charset=utf-8");
  assert.deepEqual(JSON.parse(await response.text()), {});
});

Deno.test("handlePostReviews creates the file (and its parent directory) and stores one entry per mutation id", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "nested", "reviews.json");

  const first = await postReview(path, { mutationId: "m1", classification: "actionable — tests" });
  const second = await postReview(path, { mutationId: "m2", classification: "cannot determine" });

  assert.equal(first.status, 204);
  assert.equal(second.status, 204);

  const stored = JSON.parse(await Deno.readTextFile(path));
  assert.deepEqual(stored, {
    m1: { mutationId: "m1", classification: "actionable — tests" },
    m2: { mutationId: "m2", classification: "cannot determine" },
  });
});

Deno.test("handlePostReviews replaces (does not duplicate) the entry for an already-reviewed mutation", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");

  await postReview(path, { mutationId: "m1", classification: "actionable — tests", rationale: "first pass" });
  await postReview(path, { mutationId: "m1", classification: "cannot determine", rationale: "changed my mind" });

  const stored = JSON.parse(await Deno.readTextFile(path));
  assert.deepEqual(Object.keys(stored), ["m1"]);
  assert.equal(stored.m1.classification, "cannot determine");
  assert.equal(stored.m1.rationale, "changed my mind");
});

Deno.test("handlePostReviews leaves other mutations' reviews untouched when one is updated", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");

  await postReview(path, { mutationId: "m1", classification: "actionable — tests" });
  await postReview(path, { mutationId: "m2", classification: "non-actionable — equivalent" });
  await postReview(path, { mutationId: "m1", classification: "non-actionable — redundant" });

  const stored = JSON.parse(await Deno.readTextFile(path));
  assert.equal(stored.m1.classification, "non-actionable — redundant");
  assert.equal(stored.m2.classification, "non-actionable — equivalent");
});

Deno.test("handlePostReviews removes the entry when posted with an empty classification (un-reviewing)", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");

  await postReview(path, { mutationId: "m1", classification: "actionable — tests" });
  await postReview(path, { mutationId: "m2", classification: "non-actionable — equivalent" });
  await postReview(path, { mutationId: "m1", classification: "", rationale: "leftover notes, still not classified" });

  const stored = JSON.parse(await Deno.readTextFile(path));
  assert.deepEqual(Object.keys(stored), ["m2"]);
});

Deno.test("handlePostReviews un-reviewing a mutation with no stored review is a harmless no-op", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");

  const response = await postReview(path, { mutationId: "m1", classification: "" });

  assert.equal(response.status, 204);
  assert.deepEqual(JSON.parse(await Deno.readTextFile(path)), {});
});

Deno.test("handlePostReviews does not leave a temp file behind after a successful write", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");

  await postReview(path, { mutationId: "m1", classification: "actionable — tests" });

  const entries = [...Deno.readDirSync(dir)].map((e) => e.name);
  assert.deepEqual(entries, ["reviews.json"]);
});

Deno.test("handlePostReviews rejects a record without a mutationId", async () => {
  const dir = await Deno.makeTempDir();
  const response = await postReview(join(dir, "reviews.json"), { classification: "actionable — tests" });

  assert.equal(response.status, 400);
});

Deno.test("handlePostReviews rejects a non-JSON body", async () => {
  const dir = await Deno.makeTempDir();
  const response = await handlePostReviews(
    new Request("http://localhost/api/reviews", { method: "POST", body: "not json" }),
    join(dir, "reviews.json"),
  );

  assert.equal(response.status, 400);
});

Deno.test("handleGetReviews 500s instead of discarding a corrupt reviews file", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");
  await Deno.writeTextFile(path, "not json");

  const response = await handleGetReviews(path);

  assert.equal(response.status, 500);
});

Deno.test("handlePostReviews 500s (and does not overwrite) when the existing reviews file is corrupt", async () => {
  const dir = await Deno.makeTempDir();
  const path = join(dir, "reviews.json");
  await Deno.writeTextFile(path, "not json");

  const response = await postReview(path, { mutationId: "m1", classification: "actionable — tests" });

  assert.equal(response.status, 500);
  assert.equal(await Deno.readTextFile(path), "not json"); // untouched, not clobbered with just the new record
});

Deno.test("serveStatic serves index.html for /", async () => {
  const response = await serveStatic("/");

  assert.equal(response.status, 200);
  assert.equal(response.headers.get("content-type"), "text/html; charset=utf-8");
});

Deno.test("serveStatic 404s an unknown path and refuses to escape the public directory", async () => {
  const missing = await serveStatic("/does-not-exist.js");
  assert.equal(missing.status, 404);

  const traversal = await serveStatic("/../server.ts");
  assert.equal(traversal.status, 404);
});

Deno.test("router dispatches GET/POST /api/reviews and GET /api/report distinctly from static files", async () => {
  const reviews = await router(new Request("http://localhost/api/reviews"));
  assert.notEqual(reviews.headers.get("content-type"), "text/html; charset=utf-8");

  const notFound = await router(new Request("http://localhost/api/reviews", { method: "PUT" }));
  assert.equal(notFound.status, 404);

  const index = await router(new Request("http://localhost/"));
  assert.equal(index.headers.get("content-type"), "text/html; charset=utf-8");
});

Deno.test("handleReloadEvents opens an SSE stream with a connected preamble", async () => {
  const response = handleReloadEvents();

  assert.equal(response.headers.get("content-type"), "text/event-stream");

  const reader = response.body?.getReader();
  assert.ok(reader);
  const { value } = await reader.read();
  assert.equal(new TextDecoder().decode(value), ": connected\n\n");

  await reader.cancel();
});

Deno.test("router dispatches GET /api/reload-events to the SSE stream", async () => {
  const response = await router(new Request("http://localhost/api/reload-events"));

  assert.equal(response.headers.get("content-type"), "text/event-stream");
  await response.body?.cancel();
});
