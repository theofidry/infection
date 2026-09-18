/// <reference lib="dom" />
/// <reference lib="dom.iterable" />

import { indexMutations, parseJsonl, parseReviewsJson } from "../shared/parse.ts";
import { BAD_STATUSES, computeMetrics, NOT_EVALUATED, STATUS } from "../shared/metrics.ts";
import { classificationKind, CLASSIFICATIONS } from "../shared/classifications.ts";
import type { IndexedMutation, Metrics, Observation, ObservationRecord, ReviewRecord } from "../shared/types.ts";
import { ArcElement, Chart, DoughnutController, Tooltip } from "chart.js";

Chart.register(ArcElement, DoughnutController, Tooltip);

/**
 * The native statuses for which Infection actually starts a mutant process — the complement of
 * NOT_EVALUATED. Shown as the detection-status donut in the Metrics tab (see renderStatusChart()),
 * in this fixed order so a status keeps the same ring position across reports.
 */
const EVALUATED_STATUS_ORDER: readonly string[] = [
  STATUS.KILLED_BY_TESTS,
  STATUS.KILLED_BY_STATIC_ANALYSIS,
  STATUS.ESCAPED,
  STATUS.ERROR,
  STATUS.TIMED_OUT,
  STATUS.SYNTAX_ERROR,
];

/** Fixed display order for the "Non-evaluated mutations" card (see renderStatusChart()), roughly by how often each turns up in practice. */
const NOT_EVALUATED_STATUS_ORDER: readonly string[] = [
  STATUS.NOT_COVERED,
  STATUS.SKIPPED,
  STATUS.IGNORED,
];

/** Resolves a status's declared `--status-N` custom property (see app.css) to a concrete color — Canvas 2D fills don't understand `var()`. */
function statusColor(status: string): string {
  const i = EVALUATED_STATUS_ORDER.indexOf(status);
  return getComputedStyle(document.documentElement).getPropertyValue(`--status-${i + 1}`).trim();
}

let statusChart: Chart<"doughnut"> | undefined;

interface ReviewDraft {
  reviewer: string;
  classification: string;
  rationale: string;
  proposedImprovement: string;
  uncertainty: string;
}

interface State {
  mutations: Map<string, IndexedMutation>;
  order: string[];
  runIds: Set<string>;
  reviews: Map<string, ReviewRecord>;
  selectedId: string | null;
  filteredIds: string[];
}

const state: State = {
  mutations: new Map(),
  order: [],
  runIds: new Set(),
  reviews: new Map(),
  selectedId: null,
  filteredIds: [],
};

function el<T extends HTMLElement = HTMLElement>(id: string): T {
  const found = document.getElementById(id);
  if (found === null) throw new Error(`Missing #${id}`);
  return found as T;
}

// ---------- loading ----------

async function loadReport(): Promise<void> {
  const response = await fetch("/api/report");

  if (!response.ok) {
    showEmptyState(await response.text());
    return;
  }

  const { records, errors } = parseJsonl<ObservationRecord>(await response.text());
  const { mutations, order, runIds, warnings } = indexMutations(records);

  state.mutations = mutations;
  state.order = order;
  state.runIds = runIds;

  showWarnings([...errors, ...warnings]);

  if (mutations.size === 0) {
    showEmptyState("The report has no observations yet.");
    return;
  }

  el("empty-state").style.display = "none";
  el("tabs").style.display = "flex";

  renderStats();
  renderSidebar();
  renderMetrics();
  renderDetail(state.filteredIds[0] ?? null);
}

async function loadReviews(): Promise<void> {
  const response = await fetch("/api/reviews");
  const text = response.ok ? await response.text() : "";
  const { reviews, error } = parseReviewsJson(text);

  if (error !== null) showWarnings([error]);

  state.reviews = reviews;
}

function showEmptyState(message: string): void {
  el("tabs").style.display = "none";
  const empty = el("empty-state");
  empty.style.display = "flex";
  el("empty-state-message").textContent = message;
}

function switchTab(tab: "review" | "metrics"): void {
  for (const btn of document.querySelectorAll<HTMLButtonElement>(".tab-btn")) {
    btn.classList.toggle("active", btn.dataset.tab === tab);
  }
  el("tab-review").classList.toggle("active", tab === "review");
  el("tab-metrics").classList.toggle("active", tab === "metrics");
  updateUrl();
}

const STATUS_FILTERS = new Set(["all", "unreviewed", "reviewed", "unstable"]);

/**
 * Reflects the active tab and the sidebar's search/status filter in the URL query string (via
 * replaceState, so it doesn't spam browser history on every keystroke), so this state survives a
 * reload and can be bookmarked/shared — rather than relying on the browser's incidental "restore
 * form values on reload" behaviour, which only covers a same-tab reload.
 */
function updateUrl(): void {
  const tab = el("tab-metrics").classList.contains("active") ? "metrics" : "review";
  const status = el<HTMLSelectElement>("filter-status").value;
  const q = el<HTMLInputElement>("search").value;

  const url = new URL(globalThis.location.href);
  if (tab === "review") url.searchParams.delete("tab");
  else url.searchParams.set("tab", tab);
  if (status === "all" || status === "") url.searchParams.delete("status");
  else url.searchParams.set("status", status);
  if (q === "") url.searchParams.delete("q");
  else url.searchParams.set("q", q);

  globalThis.history.replaceState(null, "", url);
}

function restoreFromUrl(): void {
  const params = new URL(globalThis.location.href).searchParams;

  const status = params.get("status");
  if (status !== null && STATUS_FILTERS.has(status)) {
    el<HTMLSelectElement>("filter-status").value = status;
  }

  const q = params.get("q");
  if (q !== null) el<HTMLInputElement>("search").value = q;

  switchTab(params.get("tab") === "metrics" ? "metrics" : "review");
}

// ---------- rendering ----------

function statusesOf(mutationId: string): { statuses: Set<string>; unstable: boolean } {
  const observations = state.mutations.get(mutationId)?.observations ?? [];
  const statuses = new Set(observations.map((o) => o.detectionStatus));
  return { statuses, unstable: statuses.size > 1 };
}

/**
 * A key present in state.reviews isn't necessarily a completed review — a record saved before the
 * server started deleting empty-classification reviews (instead of storing them) can still be
 * sitting in an existing reviews.json with `classification: ""`. Classification is the mandatory
 * field, so that's not reviewed; this is the one place that decides "reviewed", used everywhere
 * else that needs it instead of checking state.reviews.has() directly.
 */
function isReviewed(mutationId: string): boolean {
  const review = state.reviews.get(mutationId);
  return review !== undefined && classificationKind(review.classification) !== null;
}

function matchesFilter(mutationId: string, query: string, filter: string): boolean {
  const mutation = state.mutations.get(mutationId);
  if (mutation === undefined) return false;

  if (query) {
    const haystack = `${mutation.meta.mutatorName} ${mutation.meta.source.file} ${mutationId}`.toLowerCase();
    if (!haystack.includes(query)) return false;
  }
  if (filter === "unreviewed") return !isReviewed(mutationId);
  if (filter === "reviewed") return isReviewed(mutationId);
  if (filter === "unstable") return statusesOf(mutationId).unstable;
  return true;
}

function shortPath(path: string | undefined): string {
  if (!path) return "(unknown file)";
  const parts = path.split("/");
  return parts.length > 3 ? "…/" + parts.slice(-3).join("/") : path;
}

function renderSidebar(): void {
  const query = el<HTMLInputElement>("search").value.trim().toLowerCase();
  const filter = el<HTMLSelectElement>("filter-status").value;
  state.filteredIds = state.order.filter((id) => matchesFilter(id, query, filter));

  const list = el("mutation-list");
  list.innerHTML = "";

  for (const id of state.filteredIds) {
    const mutation = state.mutations.get(id);
    if (mutation === undefined) continue;
    const { unstable } = statusesOf(id);
    const reviewed = isReviewed(id);

    const item = document.createElement("div");
    item.className = "m-item" + (id === state.selectedId ? " selected" : "");
    item.onclick = () => renderDetail(id);

    const dot = document.createElement("div");
    dot.className = "dot" + (reviewed ? " reviewed" : unstable ? " unstable" : "");

    const body = document.createElement("div");
    body.className = "body";
    const mutator = document.createElement("div");
    mutator.className = "mutator";
    mutator.textContent = mutation.meta.mutatorName || "(unknown mutator)";
    const loc = document.createElement("div");
    loc.className = "loc";
    loc.textContent = `${shortPath(mutation.meta.source?.file)}:${mutation.meta.source?.startLine ?? "?"}`;
    body.append(mutator, loc);

    item.append(dot, body);
    list.appendChild(item);
  }

  renderReviewProgress({ label: "review-progress-label", bar: "review-progress-bar", fill: "review-progress-fill" });
}

/**
 * How much of the review workflow is done — the whole point of this tool — shown both above the
 * sidebar's mutation list and above the Metrics tab's donut (hence the id parameters: same
 * rendering, two independent DOM targets). Goes through computeMetrics() rather than
 * `state.reviews.size` directly so a review left over from a mutation no longer in the current
 * report (e.g. the report was regenerated) can't inflate this past what's actually reviewed.
 */
function renderReviewProgress(ids: { label: string; bar: string; fill: string }): void {
  const total = state.mutations.size;
  const reviewed = computeMetrics(state.mutations, state.reviews).reviewedCount;
  const pctDone = total > 0 ? (reviewed / total) * 100 : 0;

  const label = el(ids.label);
  label.innerHTML = "";
  const b = document.createElement("b");
  b.textContent = `${reviewed} / ${total}`;
  label.append(b, " reviewed");

  el(ids.bar).title = total > 0
    ? `${reviewed} of ${total} mutations reviewed (${pctDone.toFixed(0)}%)`
    : "No mutations to review yet";
  el(ids.fill).style.width = `${pctDone}%`;
}

function renderStats(): void {
  const stats = el("stats");
  stats.innerHTML = "";

  const counts: Record<string, number> = {};
  for (const { observations } of state.mutations.values()) {
    for (const o of observations) counts[o.detectionStatus] = (counts[o.detectionStatus] ?? 0) + 1;
  }

  const chips: string[] = [
    `Runs: <b>${state.runIds.size}</b>`,
    `Mutations: <b>${state.mutations.size}</b>`,
  ];
  // Evaluated statuses get the donut below, NOT_EVALUATED ones get their own "Non-evaluated
  // mutations" card (both in renderStatusChart()) — a chip here is the fallback for a status this
  // UI doesn't recognize as either, so a future native status still shows up somewhere.
  for (const [status, count] of Object.entries(counts).sort()) {
    if (!EVALUATED_STATUS_ORDER.includes(status) && !NOT_EVALUATED.has(status)) {
      chips.push(`${status}: <b>${count}</b>`);
    }
  }

  for (const html of chips) {
    const chip = document.createElement("span");
    chip.className = "chip";
    chip.innerHTML = html;
    stats.appendChild(chip);
  }

  renderStatusChart(counts);
}

/** Draws the observation total in the doughnut's hole — Chart.js has no built-in for this. */
const centerTextPlugin = {
  id: "centerText",
  afterDraw(chart: Chart): void {
    const total = (chart.config.data.datasets[0] as { total?: number }).total;
    if (total === undefined) return;
    const { ctx, chartArea: { left, right, top, bottom } } = chart;
    const cx = (left + right) / 2;
    const cy = (top + bottom) / 2;
    const style = getComputedStyle(chart.canvas);

    ctx.save();
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillStyle = style.getPropertyValue("--text").trim();
    ctx.font = "700 32px system-ui, sans-serif";
    ctx.fillText(String(total), cx, cy - 12);
    ctx.fillStyle = style.getPropertyValue("--text-muted").trim();
    ctx.font = "13px system-ui, sans-serif";
    ctx.fillText("OBSERVATIONS", cx, cy + 16);
    ctx.restore();
  },
};

/**
 * The detection-status donut: whole = evaluated observations, one segment per native status. This
 * counts observations, not mutations, so it can exceed the "Evaluated" metric card below (a rerun
 * of the same mutation counts again here — that's the point, since a status change between reruns
 * is exactly what the Instability metric is measuring).
 */
function renderStatusChart(counts: Record<string, number>): void {
  const wrap = el("status-breakdown");
  wrap.innerHTML = "";

  const segments = EVALUATED_STATUS_ORDER
    .map((status) => ({ status, count: counts[status] ?? 0 }))
    .filter((s) => s.count > 0);
  const total = segments.reduce((sum, s) => sum + s.count, 0);

  if (total > 0) {
    const colors = segments.map((s) => statusColor(s.status));

    const donutBox = document.createElement("div");
    donutBox.className = "donut-box";
    const canvas = document.createElement("canvas");
    canvas.width = 180;
    canvas.height = 180;
    donutBox.appendChild(canvas);

    const legend = document.createElement("ul");
    legend.className = "donut-legend";
    for (const [i, { status, count }] of segments.entries()) {
      const p = (count / total) * 100;
      const li = document.createElement("li");
      const swatch = document.createElement("span");
      swatch.className = "swatch";
      swatch.style.background = colors[i];
      const name = document.createElement("span");
      name.className = "status-name";
      name.textContent = status;
      const value = document.createElement("span");
      value.className = "status-value";
      value.textContent = `${count} (${p.toFixed(1)}%)`;
      li.append(swatch, name, value);
      legend.appendChild(li);
    }

    wrap.append(donutBox, legend);

    statusChart?.destroy();
    statusChart = new Chart<"doughnut">(canvas, {
      type: "doughnut",
      data: {
        labels: segments.map((s) => s.status),
        datasets: [{
          data: segments.map((s) => s.count),
          backgroundColor: colors,
          borderWidth: 0,
          spacing: 2,
          total, // read back by centerTextPlugin
        } as never],
      },
      options: {
        responsive: false,
        cutout: "68%",
        plugins: {
          tooltip: {
            callbacks: {
              label: (item) => `${item.label}: ${item.parsed} (${(item.parsed / total * 100).toFixed(1)}%)`,
            },
          },
        },
      },
      plugins: [centerTextPlugin],
    });
  }

  if (NOT_EVALUATED_STATUS_ORDER.some((status) => (counts[status] ?? 0) > 0)) {
    wrap.appendChild(buildNonEvaluatedCard(counts));
  }
}

/**
 * "Non-evaluated mutations": the NOT_EVALUATED statuses (not covered, skipped, ignored) get their
 * own card rather than chips or donut segments, because they're a different kind of thing from the
 * donut's statuses — Infection never started a mutant process for them at all, so there's no
 * detection outcome to review, just a reason none was attempted.
 */
function buildNonEvaluatedCard(counts: Record<string, number>): HTMLDivElement {
  const card = document.createElement("div");
  card.className = "non-evaluated-card";

  const heading = document.createElement("h3");
  heading.textContent = "Non-evaluated mutations";
  const desc = document.createElement("p");
  desc.className = "desc";
  desc.textContent = "Generated, but Infection never started a mutant process for these — " +
    "there's no detection outcome to review, just a reason none was attempted.";
  card.append(heading, desc);

  const list = document.createElement("ul");
  for (const status of NOT_EVALUATED_STATUS_ORDER) {
    const count = counts[status] ?? 0;
    if (count === 0) continue;
    const li = document.createElement("li");
    const name = document.createElement("span");
    name.className = "status-name";
    name.textContent = status;
    const value = document.createElement("span");
    value.className = "status-value";
    value.textContent = String(count);
    li.append(name, value);
    list.appendChild(li);
  }
  card.appendChild(list);

  return card;
}

function pct(x: number | null): string {
  return x === null ? "—" : (x * 100).toFixed(1) + "%";
}

/**
 * One line per card explaining what a reviewer is looking at — the denominator and what a high/low
 * value means — condensed from doc/mutator-performance.md's "Metrics" section, which remains the
 * source of truth if these drift. "Mutations" itself isn't repeated here: the total is already the
 * "Mutations" chip in the Overview section above, so this grid starts from what's evaluated.
 *
 * `group` splits the grid in two: "report" metrics are derived only from the raw report and can't
 * move no matter how a mutation gets classified; "review" metrics are derived from state.reviews
 * and change as classifications are added (see computeMetrics() — reviewedCount/actionable/
 * nonActionable/unresolved are the only fields fed by the reviews map). Answers "why didn't this
 * number move when I classified a mutation?" by construction rather than by a one-off explanation.
 */
const METRIC_DEFINITIONS: ReadonlyArray<{
  label: string;
  value: (m: Metrics) => string | number;
  description: string;
  /** The bare computation, in the same terms as computeMetrics() — kept short, no clauses. */
  formula: string;
  /** Plain-English definitions of the terms `formula` uses that aren't self-explanatory. */
  formulaLegend: string;
  group: "report" | "review";
  /**
   * "good"/"critical" colors the value like the native DetectionStatus good/bad classes elsewhere
   * (see BAD_STATUSES usage in renderObservations()) — reserved for a metric with a real target
   * (100%, here), not a general-purpose way to decorate any number.
   */
  valueStatus?: (m: Metrics) => "good" | "critical" | undefined;
}> = [
  {
    label: "Evaluated",
    value: (m) => m.evaluated,
    description: "Mutations Infection ran mutant evaluation for — excludes not covered, skipped, and ignored. " +
      "Informational only: there's no target value, just the denominator behind the metrics below.",
    formula: "count(mutations with ≥1 evaluated observation)",
    formulaLegend: "Excludes not covered, skipped, and ignored — statuses with no mutant process at all.",
    group: "report",
  },
  {
    label: "Syntactic validity",
    value: (m) => pct(m.syntacticValidityRate),
    description: "Share of evaluated mutations that parsed without a syntax error. " +
      "Target 100% — anything lower usually means a mutator defect.",
    formula: "(Evaluated − Invalid) ÷ Evaluated",
    formulaLegend: "Invalid = evaluated mutations with a syntax-error observation.",
    group: "report",
    valueStatus: (m) =>
      m.syntacticValidityRate === null ? undefined : m.syntacticValidityRate === 1 ? "good" : "critical",
  },
  {
    label: "Test workload",
    value: (m) => m.workload,
    description: "Total tests selected across evaluated mutations — the workload behind the runtime below. " +
      "Informational only: no target value, just context for interpreting Recorded runtime.",
    formula: "Σ tests selected",
    formulaLegend: "Summed over every evaluated observation.",
    group: "report",
  },
  {
    label: "Recorded runtime",
    value: (m) => m.runtime.toFixed(2) + "s",
    description: "Total decisive-process wall time across evaluated mutations. " +
      "Informational only: no target value — expected runtime scales with Test workload above.",
    formula: "Σ decisive-process runtime (seconds)",
    formulaLegend: "Summed over every evaluated observation.",
    group: "report",
  },
  {
    label: "Instability",
    value: (m) => pct(m.instabilityRate),
    description: "Share of repeatedly-evaluated mutations whose status changed across runs — a reliability check " +
      "on the run, not on the mutator. Target 0% — non-zero points to flaky tests or environment noise.",
    formula: "Unstable ÷ Repeated",
    formulaLegend: "Repeated = mutations evaluated ≥2 times. Unstable = of those, ones whose status differed " +
      "across observations.",
    group: "report",
  },
  {
    label: "Actionability",
    value: (m) => pct(m.actionabilityRate),
    description: "Of classified mutations, the share that identify a specific, justified test or subject " +
      "improvement. Higher is better, though there's no universal threshold — it's set per project.",
    formula: "Actionable ÷ (Actionable + Non-actionable)",
    formulaLegend: "Counted from your review classifications; “cannot determine” reviews are excluded from both terms.",
    group: "review",
  },
  {
    label: "Unresolved",
    value: (m) => pct(m.unresolvedProportion),
    description: "Share of reviewed mutations marked “cannot determine”. " +
      "Target as low as possible — high weakens confidence in the Actionability result above.",
    formula: "Cannot-determine ÷ (Actionable + Non-actionable + Cannot-determine)",
    formulaLegend: "Equivalent to cannot-determine ÷ all reviewed mutations.",
    group: "review",
  },
];

function renderMetricsGrid(gridId: string, group: "report" | "review", metrics: Metrics): void {
  const grid = el(gridId);
  grid.innerHTML = "";

  for (
    const { label, value, description, formula, formulaLegend, valueStatus } of METRIC_DEFINITIONS.filter((d) =>
      d.group === group
    )
  ) {
    const card = document.createElement("div");
    card.className = "metric-card";
    const labelEl = document.createElement("div");
    labelEl.className = "label";
    labelEl.textContent = label;
    const valueEl = document.createElement("div");
    valueEl.className = "value";
    const status = valueStatus?.(metrics);
    if (status !== undefined) valueEl.classList.add(`value-${status}`);
    valueEl.textContent = String(value(metrics));
    const descEl = document.createElement("div");
    descEl.className = "desc";
    descEl.textContent = description;

    // A <details> rather than a hover tooltip: readable at a glance once opened, doesn't disappear
    // the moment the mouse moves, and stays collapsed by default so the card isn't formula-first.
    // The formula itself stays bare (no "where X = ..." clause) so it reads as one line; term
    // definitions go in formulaLegend, right underneath but visually distinct from the formula.
    const formulaDetails = document.createElement("details");
    formulaDetails.className = "formula";
    const summary = document.createElement("summary");
    summary.textContent = "Formula";
    const formulaText = document.createElement("div");
    formulaText.className = "formula-text mono";
    formulaText.textContent = formula;
    const legendText = document.createElement("div");
    legendText.className = "formula-legend";
    legendText.textContent = formulaLegend;
    formulaDetails.append(summary, formulaText, legendText);

    card.append(labelEl, valueEl, descEl, formulaDetails);
    grid.appendChild(card);
  }
}

function renderMetrics(): void {
  const metrics = computeMetrics(state.mutations, state.reviews);

  // Lives in the Overview section's markup (above the donut), not the grid below — rendered here
  // rather than in renderStats() so it stays live after every review save, same as the cards do.
  renderReviewProgress({
    label: "metrics-review-progress-label",
    bar: "metrics-review-progress-bar",
    fill: "metrics-review-progress-fill",
  });

  renderMetricsGrid("metrics-grid-report", "report", metrics);
  renderMetricsGrid("metrics-grid-review", "review", metrics);
}

function renderDiff(diff: string | undefined): HTMLPreElement {
  const pre = document.createElement("pre");
  pre.className = "diff";

  for (const line of (diff ?? "").split("\n")) {
    const div = document.createElement("div");
    div.className = "line";
    if (line.startsWith("@@")) div.classList.add("hunk");
    else if (line.startsWith("+")) div.classList.add("add");
    else if (line.startsWith("-")) div.classList.add("del");
    div.textContent = line;
    pre.appendChild(div);
  }

  return pre;
}

function renderObservations(observations: Observation[]): HTMLDivElement {
  const wrap = document.createElement("div");

  for (const obs of observations) {
    const details = document.createElement("details");
    details.className = "obs";

    const summary = document.createElement("summary");
    const run = document.createElement("span");
    run.className = "run";
    run.textContent = `run ${obs.runId.slice(0, 10)}…`;
    const status = document.createElement("span");
    status.className = "status " + (BAD_STATUSES.has(obs.detectionStatus) ? "bad" : "good");
    status.textContent = obs.detectionStatus;
    summary.append(run, status);
    details.appendChild(summary);

    if (obs.tests.length > 0) {
      const ul = document.createElement("ul");
      ul.className = "tests";
      for (const t of obs.tests) {
        const li = document.createElement("li");
        li.textContent = t.method + (t.executionTimeSeconds != null ? ` (${t.executionTimeSeconds}s)` : "");
        ul.appendChild(li);
      }
      details.appendChild(ul);
    }

    if (obs.decisiveProcess?.output) {
      const output = document.createElement("div");
      output.className = "output";
      output.textContent = obs.decisiveProcess.output;
      details.appendChild(output);
    }

    wrap.appendChild(details);
  }

  return wrap;
}

let saveTimer: ReturnType<typeof setTimeout> | undefined;
let toastHideTimer: ReturnType<typeof setTimeout> | undefined;
let saveRequestId = 0;

/**
 * A single fixed-position toast (see public/index.html) rather than a per-form inline label — an
 * inline "Saving…"/"Saved" text next to the form was easy to miss. "Saved" auto-dismisses after a
 * beat; "Saving…" and errors stay up until the next state change so they're not missed either.
 */
function showToast(text: string, kind: "saving" | "saved" | "error"): void {
  const toast = document.getElementById("save-toast");
  if (toast === null) return;

  clearTimeout(toastHideTimer);
  toast.textContent = text;
  toast.className = `toast visible ${kind}`;

  if (kind === "saved") {
    toastHideTimer = setTimeout(() => toast.classList.remove("visible"), 1800);
  }
}

/**
 * Selecting a classification persists immediately (not debounced), so clicking through several in a row
 * starts several overlapping fetches. Without this guard, an earlier click's response can resolve
 * after a later one's and flash the toast back to "Saved"/an error out of order — only the
 * response to the most recently started save is allowed to update the toast.
 */
/** An empty classification tells the server to remove the review (see handlePostReviews in server.ts). */
async function persistReview(record: ReviewRecord): Promise<void> {
  const isRemoval = record.classification === "";
  const requestId = ++saveRequestId;
  showToast(isRemoval ? "Removing…" : "Saving…", "saving");

  let outcome: { text: string; kind: "saved" | "error" };
  try {
    const response = await fetch("/api/reviews", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(record),
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    outcome = { text: isRemoval ? "Removed" : "Saved", kind: "saved" };
  } catch (error) {
    outcome = {
      text: isRemoval ? "Failed to remove — check the server" : "Failed to save — check the server",
      kind: "error",
    };
    console.error(error);
  }

  if (requestId === saveRequestId) showToast(outcome.text, outcome.kind);
}

/**
 * Classification is the only mandatory field (see the "required" mark in renderReviewForm) — a
 * mutation isn't "reviewed" without one, so the other fields alone don't create or keep a saved
 * review. Typing rationale/etc. before picking a classification still updates the in-memory
 * draft (see renderReviewForm's field wiring), so nothing already typed is lost once a
 * classification is chosen — it just isn't persisted, and doesn't count towards "reviewed", until
 * then.
 */
function saveReview(mutationId: string, draft: ReviewDraft, debounced: boolean): void {
  const hasClassification = draft.classification !== "";
  const wasPersisted = state.reviews.has(mutationId);
  const record: ReviewRecord = {
    mutationId,
    reviewer: draft.reviewer || null,
    classification: draft.classification,
    rationale: draft.rationale || null,
    proposedImprovement: draft.proposedImprovement || null,
    uncertainty: draft.uncertainty || null,
  };

  if (hasClassification) state.reviews.set(mutationId, record);
  else state.reviews.delete(mutationId);

  renderMetrics();
  renderSidebar();

  if (!hasClassification) {
    // Nothing to un-review on the server if it was never saved there in the first place.
    if (wasPersisted) void persistReview(record);
    return;
  }

  clearTimeout(saveTimer);
  if (debounced) {
    saveTimer = setTimeout(() => void persistReview(record), 400);
  } else {
    void persistReview(record);
  }
}

function renderReviewForm(mutationId: string): HTMLDivElement {
  const card = document.createElement("div");
  card.className = "card";
  card.innerHTML = "<h2>Review</h2>";

  const existing = state.reviews.get(mutationId);
  const draft: ReviewDraft = existing
    ? {
      reviewer: existing.reviewer ?? "",
      classification: existing.classification,
      rationale: existing.rationale ?? "",
      proposedImprovement: existing.proposedImprovement ?? "",
      uncertainty: existing.uncertainty ?? "",
    }
    : {
      reviewer: "",
      classification: "",
      rationale: "",
      proposedImprovement: "",
      uncertainty: "",
    };

  const classificationLabel = document.createElement("div");
  classificationLabel.className = "field-label";
  classificationLabel.innerHTML = 'Classification <span class="required-mark">*</span>';
  card.appendChild(classificationLabel);

  const classificationList = document.createElement("div");
  classificationList.className = "classification-list";
  for (const c of CLASSIFICATIONS) {
    const option = document.createElement("label");
    option.className = "classification-option";

    const radio = document.createElement("input");
    radio.type = "radio";
    radio.name = "classification";
    radio.value = c.value;
    radio.checked = draft.classification === c.value;
    radio.onclick = () => {
      // A radio can't natively be unchecked by clicking it again, but that's how we support
      // un-reviewing (clearing the mandatory classification) — so intercept a click on the
      // already-selected option and force it back off instead of leaving it checked.
      const wasSelected = draft.classification === c.value;
      draft.classification = wasSelected ? "" : c.value;
      radio.checked = !wasSelected;
      saveReview(mutationId, draft, false);
    };

    const text = document.createElement("div");
    text.className = "option-text";
    const term = document.createElement("div");
    term.className = "term";
    term.textContent = c.value;
    const legend = document.createElement("div");
    legend.className = "legend-text";
    legend.textContent = c.description;
    text.append(term, legend);

    option.append(radio, text);
    classificationList.appendChild(option);
  }
  card.appendChild(classificationList);

  const fields: Array<[keyof ReviewDraft, string, "input" | "textarea"]> = [
    ["reviewer", "Reviewer", "input"],
    ["rationale", "Rationale", "textarea"],
    ["proposedImprovement", "Proposed improvement", "textarea"],
    ["uncertainty", "Uncertainty", "input"],
  ];

  for (const [key, label, tag] of fields) {
    const field = document.createElement("div");
    field.className = "field";
    const labelEl = document.createElement("label");
    labelEl.textContent = label;
    const input = document.createElement(tag) as HTMLInputElement | HTMLTextAreaElement;
    if (tag === "input") (input as HTMLInputElement).type = "text";
    input.value = draft[key];
    input.oninput = () => {
      draft[key] = input.value;
      saveReview(mutationId, draft, true);
    };
    field.append(labelEl, input);
    card.appendChild(field);
  }

  return card;
}

function renderDetail(mutationId: string | null): void {
  state.selectedId = mutationId;
  const detail = el("detail");
  detail.innerHTML = "";

  if (mutationId === null) {
    detail.innerHTML = '<div class="placeholder">Select a mutation on the left.</div>';
    renderSidebar();
    return;
  }

  const mutation = state.mutations.get(mutationId);
  if (mutation === undefined) return;

  const header = document.createElement("div");
  header.className = "card";
  header.id = "m-header";
  const h2 = document.createElement("h2");
  h2.textContent = mutation.meta.mutatorName || "(unknown mutator)";
  // An <a> without an href isn't interactive (no click, no hover affordance), so this quietly
  // falls back to plain text when source data is missing instead of needing a second code path.
  const loc = document.createElement("a");
  loc.className = "loc";
  loc.textContent = `${mutation.meta.source?.file ?? "?"}:${mutation.meta.source?.startLine ?? "?"}–${
    mutation.meta.source?.endLine ?? "?"
  }`;
  if (mutation.meta.source) {
    loc.href = `phpstorm://open?file=${
      encodeURIComponent(mutation.meta.source.file)
    }&line=${mutation.meta.source.startLine}`;
    loc.title = "Open in PhpStorm";
  }
  const id = document.createElement("div");
  id.className = "mutation-id mono";
  id.textContent = `ID: ${mutationId}`;
  header.append(h2, loc, id);
  detail.appendChild(header);

  const diffCard = document.createElement("div");
  diffCard.className = "card";
  diffCard.innerHTML = "<h2>Diff</h2>";
  diffCard.appendChild(renderDiff(mutation.meta.diff));
  detail.appendChild(diffCard);

  const obsCard = document.createElement("div");
  obsCard.className = "card";
  obsCard.innerHTML = `<h2>Observations (${mutation.observations.length})</h2>`;
  obsCard.appendChild(renderObservations(mutation.observations));
  detail.appendChild(obsCard);

  detail.appendChild(renderReviewForm(mutationId));

  renderSidebar();
}

// ---------- warnings ----------

function showWarnings(list: string[]): void {
  const box = el("warnings");
  if (list.length === 0) {
    box.classList.remove("active");
    return;
  }
  box.classList.add("active");
  box.innerHTML = "";
  for (const w of list) {
    const div = document.createElement("div");
    div.textContent = w;
    box.appendChild(div);
  }
}

// ---------- wiring ----------

function onFilterChange(): void {
  renderSidebar();
  updateUrl();
}

el("search").addEventListener("input", onFilterChange);
el("filter-status").addEventListener("change", onFilterChange);

for (const btn of document.querySelectorAll<HTMLButtonElement>(".tab-btn")) {
  btn.addEventListener("click", () => switchTab(btn.dataset.tab === "metrics" ? "metrics" : "review"));
}

/**
 * Subscribes to the server's /api/reload-events SSE stream (see server.ts) and reloads the page
 * on either message: an explicit "reload" event (the client bundle or public/*.html/css changed),
 * or the connection re-establishing after having been open before (the server process itself
 * restarted — e.g. a Docker rebuild — which the client bundle rebuild alone wouldn't catch).
 */
function connectHotReload(): void {
  let everConnected = false;

  const connect = () => {
    const source = new EventSource("/api/reload-events");

    source.onopen = () => {
      if (everConnected) globalThis.location.reload();
      everConnected = true;
    };

    source.onmessage = () => globalThis.location.reload();

    source.onerror = () => {
      source.close();
      setTimeout(connect, 1000);
    };
  };

  connect();
}

async function init(): Promise<void> {
  await loadReviews();
  await loadReport();
}

restoreFromUrl();
void init();
connectHotReload();
