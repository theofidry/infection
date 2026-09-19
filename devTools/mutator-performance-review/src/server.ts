import { dirname, extname, join } from "node:path";
import type { ReviewRecord } from "./shared/types.ts";

const REPORT_PATH = Deno.env.get("REPORT_PATH") ?? "var/mutator-performance.jsonl";
const REVIEWS_PATH = Deno.env.get("REVIEWS_PATH") ?? "var/mutator-performance-reviews.json";
const PUBLIC_DIR = new URL("../public/", import.meta.url);

const CONTENT_TYPES: Readonly<Record<string, string>> = {
  ".html": "text/html; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".css": "text/css; charset=utf-8",
};

const NDJSON = "application/x-ndjson; charset=utf-8";
const JSON_TYPE = "application/json; charset=utf-8";

/**
 * Serves the compiled client (public/index.html, app.js, app.css). The server is otherwise a
 * dumb persistence layer — see doc/mutator-performance-tooling.md's "Client computes, server
 * persists" decision; all parsing, indexing, and metrics computation happens in the browser.
 */
export async function serveStatic(pathname: string): Promise<Response> {
  const relative = pathname === "/" ? "index.html" : pathname.slice(1);
  const fileUrl = new URL(relative, PUBLIC_DIR);

  if (!fileUrl.pathname.startsWith(PUBLIC_DIR.pathname)) {
    return new Response("Not found", { status: 404 });
  }

  try {
    const body = await Deno.readFile(fileUrl);
    const contentType = CONTENT_TYPES[extname(relative)] ?? "application/octet-stream";
    return new Response(body, { headers: { "content-type": contentType } });
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return new Response("Not found", { status: 404 });
    throw error;
  }
}

/** The raw observation report. Missing is a real error here — there is nothing to review. */
export async function handleReport(path: string = REPORT_PATH): Promise<Response> {
  try {
    const body = await Deno.readFile(path);
    return new Response(body, { headers: { "content-type": NDJSON } });
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) {
      return new Response(`Report not found at ${path}`, { status: 404 });
    }
    throw error;
  }
}

/**
 * Reads the reviews file: a single JSON object keyed by mutation id. Missing is normal (no
 * reviews recorded yet) and reads as empty. A file that exists but fails to parse is a real
 * error — silently treating it as empty would mean the next successful write discards whatever
 * was actually on disk, so this is left to throw and must not be swallowed by the caller.
 */
async function readReviews(path: string): Promise<Record<string, ReviewRecord>> {
  let text: string;

  try {
    text = await Deno.readTextFile(path);
  } catch (error) {
    if (error instanceof Deno.errors.NotFound) return {};
    throw error;
  }

  if (text.trim() === "") return {};

  return JSON.parse(text) as Record<string, ReviewRecord>;
}

/** Writes via a temp file + rename so a crash mid-write can't leave a truncated/corrupt file. */
async function writeReviews(path: string, reviews: Record<string, ReviewRecord>): Promise<void> {
  await Deno.mkdir(dirname(path), { recursive: true });
  const tmpPath = `${path}.tmp-${crypto.randomUUID()}`;
  await Deno.writeTextFile(tmpPath, JSON.stringify(reviews, null, 2) + "\n");
  await Deno.rename(tmpPath, path);
}

export async function handleGetReviews(path: string = REVIEWS_PATH): Promise<Response> {
  let reviews: Record<string, ReviewRecord>;

  try {
    reviews = await readReviews(path);
  } catch (error) {
    return new Response(`Reviews file is corrupt: ${(error as Error).message}`, { status: 500 });
  }

  return new Response(JSON.stringify(reviews, null, 2) + "\n", { headers: { "content-type": JSON_TYPE } });
}

/**
 * Upserts one review by mutation id, then rewrites the whole file — see readReviews/writeReviews.
 * Classification is the only mandatory field (see renderReviewForm() in the client): a record
 * with an empty classification means "this mutation is no longer reviewed," so it's removed
 * rather than stored with a blank value — an empty classification isn't a valid one anyway.
 */
export async function handlePostReviews(request: Request, path: string = REVIEWS_PATH): Promise<Response> {
  let record: unknown;

  try {
    record = await request.json();
  } catch {
    return new Response("Invalid JSON body", { status: 400 });
  }

  if (
    typeof record !== "object" ||
    record === null ||
    typeof (record as { mutationId?: unknown }).mutationId !== "string" ||
    (record as { mutationId: string }).mutationId === ""
  ) {
    return new Response("Expected an object with a non-empty string mutationId", { status: 400 });
  }

  let reviews: Record<string, ReviewRecord>;

  try {
    reviews = await readReviews(path);
  } catch (error) {
    return new Response(`Reviews file is corrupt: ${(error as Error).message}`, { status: 500 });
  }

  const reviewRecord = record as ReviewRecord;
  if (reviewRecord.classification === "") {
    delete reviews[reviewRecord.mutationId];
  } else {
    reviews[reviewRecord.mutationId] = reviewRecord;
  }
  await writeReviews(path, reviews);

  return new Response(null, { status: 204 });
}

const encoder = new TextEncoder();
const reloadClients = new Set<WritableStreamDefaultWriter<Uint8Array>>();

/**
 * A Server-Sent Events stream the client subscribes to (see connectHotReload() in
 * src/client/app.ts) so the browser reloads itself after a source edit, instead of the developer
 * needing to notice and refresh manually. Plain SSE over `EventSource` rather than WebSockets:
 * one-directional, needs no extra dependency on either side.
 */
export function handleReloadEvents(): Response {
  const { readable, writable } = new TransformStream<Uint8Array, Uint8Array>();
  const writer = writable.getWriter();
  reloadClients.add(writer);
  writer.closed.catch(() => {}).finally(() => reloadClients.delete(writer));
  writer.write(encoder.encode(": connected\n\n")).catch(() => {});

  return new Response(readable, {
    headers: {
      "content-type": "text/event-stream",
      "cache-control": "no-cache",
      "connection": "keep-alive",
    },
  });
}

function broadcastReload(): void {
  for (const writer of reloadClients) {
    writer.write(encoder.encode("data: reload\n\n")).catch(() => reloadClients.delete(writer));
  }
}

export function router(request: Request): Promise<Response> {
  const url = new URL(request.url);

  if (url.pathname === "/api/report" && request.method === "GET") return handleReport();
  if (url.pathname === "/api/reviews" && request.method === "GET") return handleGetReviews();
  if (url.pathname === "/api/reviews" && request.method === "POST") return handlePostReviews(request);
  if (url.pathname === "/api/reload-events" && request.method === "GET") {
    return Promise.resolve(handleReloadEvents());
  }
  if (request.method === "GET") return serveStatic(url.pathname);

  return Promise.resolve(new Response("Not found", { status: 404 }));
}

/**
 * Rebuilds public/app.js from src/client/app.ts (and everything it imports from src/shared/) on
 * every change, and rewatches public/index.html and public/app.css directly, since esbuild only
 * tracks the module graph it bundles. A dynamic import keeps esbuild out of `deno test`/`deno
 * check` entirely — it's only ever needed once the server actually starts serving.
 */
async function watchAndRebuildClient(): Promise<void> {
  const esbuild = await import("esbuild");
  // app.ts imports "chart.js" via deno.json's npm import map — esbuild's own resolver doesn't
  // know about that map, so it needs the Deno loader plugin to find the package (see build-client.ts).
  const { denoPlugins } = await import("@luca/esbuild-deno-loader");

  type Plugin = NonNullable<Parameters<typeof esbuild.context>[0]["plugins"]>[number];

  const ctx = await esbuild.context({
    entryPoints: [new URL("./client/app.ts", import.meta.url).pathname],
    outfile: new URL("../public/app.js", import.meta.url).pathname,
    bundle: true,
    format: "esm",
    // esbuild-deno-loader vendors its own Plugin/PluginBuild types, structurally close to but not
    // identical to this dynamically-imported esbuild's own — hence the cast (see build-client.ts).
    plugins: [...denoPlugins() as Plugin[], {
      name: "notify-reload",
      setup(build: { onEnd: (cb: (result: { errors: unknown[] }) => void) => void }) {
        build.onEnd((result) => {
          if (result.errors.length === 0) {
            console.log("Rebuilt public/app.js");
            broadcastReload();
          }
        });
      },
    } as Plugin],
  });

  await ctx.watch();

  const watcher = Deno.watchFs([
    new URL("../public/index.html", import.meta.url).pathname,
    new URL("../public/app.css", import.meta.url).pathname,
  ]);
  for await (const event of watcher) {
    if (event.kind === "modify" || event.kind === "create") broadcastReload();
  }
}

if (import.meta.main) {
  const port = Number(Deno.env.get("PORT") ?? "8000");
  console.log(`mutator-performance-review listening on http://0.0.0.0:${port}`);
  console.log(`  report:  ${join(Deno.cwd(), REPORT_PATH)}`);
  console.log(`  reviews: ${join(Deno.cwd(), REVIEWS_PATH)}`);
  Deno.serve({ port, hostname: "0.0.0.0" }, router);
  watchAndRebuildClient().catch((error) => console.error("Hot-reload watcher failed:", error));
}
