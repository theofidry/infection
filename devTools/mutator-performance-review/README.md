# Mutator performance review

A review UI for classifying mutations from the append-only mutator-performance JSONL report. It replaces filling in a
spreadsheet by hand.

```bash
make mutator-performance-review
```

Then open <http://localhost:8000>. This starts a small Deno server (via `docker compose`, so nothing needs to be
installed locally beyond Docker) that serves the review page and reads/writes the report and reviews files directly from
`var/`:

- `var/mutator-performance.jsonl` — the report, produced by Infection's `logs.mutatorPerformance` (read-only from this
  tool's perspective). Append-only: one line per observation, because repeated runs of the same mutation are legitimate
  distinct evidence.
- `var/mutator-performance-reviews.json` — a single JSON object keyed by mutation ID, holding the current classification
  for each reviewed mutation. Unlike the report, there is no value in keeping a history of past classifications, so this
  file is rewritten (atomically — via a temp file plus rename, so a crash mid-write can't corrupt it) on every change
  rather than appended to.

Both paths can be overridden with the `REPORT_PATH`/`REVIEWS_PATH` environment variables in `docker-compose.yml`, for
example to point at a different corpus run.

## Using it

A progress bar above the sidebar ("N / M reviewed") tracks how much of the review is done — that's the point of this
tab, so it's visible there rather than tucked away in Metrics.

1. The report loads automatically. Pick a mutation from the sidebar — search by mutator, file, or mutation ID (shown in
   the detail header, for cross-referencing against the raw report), or filter to unreviewed/reviewed/unstable. The
   source location in the detail header is a `phpstorm://` link that jumps straight to that file and line, if PhpStorm's
   protocol handler is set up on the machine the browser is running on.
2. Read the diff and the recorded observations (status, selected tests, process output per run, collapsed by default),
   then record a classification, rationale, proposed improvement, and uncertainty. Classification is a radio list — term
   plus its definition inline, restricted to the values from
   [Evaluating Mutator Performance](../../doc/mutator-performance.md#actionability-rate) — so a review can't drift from
   the validated categories the way a free-typed spreadsheet cell can.
3. Every change is saved to `var/mutator-performance-reviews.json` as soon as you make it (selecting a classification
   saves immediately; text fields save shortly after you stop typing). A toast in the corner shows "Saving…" while the
   request is in flight and "Saved" once it lands (fading out after a couple of seconds; a failed save stays up until
   the next attempt), so it's clear when it's safe to move on — there is no separate export step, and no session to lose
   by closing the tab.
4. The Metrics tab has a **Scope** selector at the top: "All mutators", or one mutator at a time. It re-scopes the whole
   tab — chips, status donut, review progress, and every card — so the same, single set of formulas is applied to a
   smaller population rather than computed a second way. Each option shows that mutator's mutation count, because that
   count is the sample size behind every rate on the page: 100% validity over 3 mutations and over 300 look identical on
   a card otherwise.
5. The Compare tab charts the same metrics one bar per mutator, so mutators can be read against each other rather than
   one at a time. Each chart is ranked by its own value, largest first, with ties falling back to the mutator name.
   Behind the bars, a band marks where the middle half of the mutators sit (P25–P75) and a line marks the median, so a
   bar can be read as typical or not rather than only as higher or lower than its neighbour. "Minimum mutations" drops
   the long tail of mutators with too little evidence to compare. Syntactic validity, Instability, and the three
   non-actionable subcategories chart only the mutators away from target — below 100% for the first, above 0% for the
   rest — because a wall of identical bars is not a comparison; each says so in its heading as well as in the count of
   the rest.
6. After appending a new Infection run, a plain browser reload picks it up — tab, search, status filter, metrics scope,
   and the Compare tab's minimum all survive it (they live in the URL query string, restored on load). There's no export step — no separate "reload" button
   either, both would just duplicate what a reload already does — `var/mutator-performance-reviews.json` is already the
   file to read, share, or join with the raw observations; open it directly.

Editing `src/client/app.ts`, anything under `src/shared/`, `public/index.html`, or `public/app.css` while
`make mutator-performance-review` is running takes effect automatically — the server rebuilds `public/app.js` (via
esbuild's watch API) and pushes a reload to any open browser tab over Server-Sent Events. No container restart, no
manual browser refresh. Editing `src/server.ts` (or the `Dockerfile`/`deno.json`) needs the usual
`docker compose up --build`, since that's the server process itself — but once the new server is up, the browser's SSE
connection dropping and reconnecting triggers a reload for that too, so there's still nothing to click.

## Architecture

```
devTools/mutator-performance-review/
  Dockerfile           # denoland/deno base image
  deno.json             # tasks: serve, test, check, build:client
  src/
    shared/              # parsing, indexing, and metrics logic — no DOM, unit-tested with `deno test`
    client/app.ts         # browser entrypoint (bundled to public/app.js by esbuild)
    server.ts              # Deno.serve: static files + /api/report + /api/reviews (GET/POST) +
                            #   /api/reload-events (SSE) + the hot-reload watch/rebuild loop
  public/                 # index.html + app.css are committed; app.js is generated (gitignored)
  testdata/                # fixtures for the shared-module tests
```

All parsing, indexing, and metrics computation (syntactic validity, review-driven actionability and its non-actionable
subcategories, unresolved proportion, outcome instability, and the per-observation means the Compare tab charts)
happens client-side, in the modules under `src/shared/`, unit-tested directly with `deno test`.
This is deliberate: the same statistics used to exist a second time, untested, in
`devTools/mutator-performance-workbook`'s XLSX output — see [the tooling doc](../../doc/mutator-performance-tooling.md)
for the full rationale. The server is otherwise thin: it serves static files, reads/writes the two JSONL and JSON files
(without parsing or validating their content beyond checking that a posted review has a `mutationId`), and runs the
hot-reload watcher.

Run `make mutator-performance-review` to start it, or, with a local Deno install, `deno task test` / `deno task check`
from this directory to run the test suite or type-checker without Docker.
