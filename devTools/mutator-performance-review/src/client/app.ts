/// <reference lib="dom" />
/// <reference lib="dom.iterable" />

import { filterByMutator, indexMutations, parseJsonl, parseReviewsJson, summariseMutators } from "../shared/parse.ts";
import {
  BAD_STATUSES,
  computeMetrics,
  computeMutatorMetrics,
  NOT_EVALUATED,
  STATUS,
  summariseSpread,
} from "../shared/metrics.ts";
import { classificationKind, CLASSIFICATIONS, NON_ACTIONABLE_CLASSIFICATIONS } from "../shared/classifications.ts";
import type {
  IndexedMutation,
  Metrics,
  MutatorMetrics,
  Observation,
  ObservationRecord,
  ReviewRecord,
  Spread,
} from "../shared/types.ts";
import {
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  DoughnutController,
  LinearScale,
  Tooltip,
} from "chart.js";

Chart.register(ArcElement, BarController, BarElement, CategoryScale, DoughnutController, LinearScale, Tooltip);

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

/** Resolves a CSS custom property to a concrete color — Canvas 2D fills don't understand `var()`. */
function token(name: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/** Resolves a status's declared `--status-N` custom property (see app.css). */
function statusColor(status: string): string {
  return token(`--status-${EVALUATED_STATUS_ORDER.indexOf(status) + 1}`);
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
  reviews: Map<string, ReviewRecord>;
  selectedId: string | null;
  filteredIds: string[];
  /** The mutator the Metrics tab is scoped to; "" is every mutator. Does not affect the Review tab. */
  mutatorScope: string;
  /** Compare tab: mutators with fewer mutations than this are left out of every chart. */
  compareMinMutations: number;
}

const state: State = {
  mutations: new Map(),
  order: [],
  reviews: new Map(),
  selectedId: null,
  filteredIds: [],
  mutatorScope: "",
  compareMinMutations: 1,
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
  const { mutations, order, warnings } = indexMutations(records);

  state.mutations = mutations;
  state.order = order;

  showWarnings([...errors, ...warnings]);

  if (mutations.size === 0) {
    showEmptyState("The report has no observations yet.");
    return;
  }

  el("empty-state").style.display = "none";
  el("tabs").style.display = "flex";

  populateMutatorScope();
  renderSidebar();
  renderMetrics();
  renderCompareIfActive();
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

type Tab = "review" | "metrics" | "compare";

const TABS: readonly Tab[] = ["review", "metrics", "compare"];

function switchTab(tab: Tab): void {
  for (const btn of document.querySelectorAll<HTMLButtonElement>(".tab-btn")) {
    btn.classList.toggle("active", btn.dataset.tab === tab);
  }
  for (const name of TABS) el(`tab-${name}`).classList.toggle("active", name === tab);

  // Chart.js sizes a responsive chart from its container, which measures 0 while the panel is
  // display:none — so the Compare charts are drawn on activation rather than kept up to date in
  // the background, where they would all render as empty.
  if (tab === "compare") renderCompare();

  updateUrl();
}

/** Keeps the Compare charts in step with a review save, but only while they are actually on screen. */
function renderCompareIfActive(): void {
  if (el("tab-compare").classList.contains("active")) renderCompare();
}

const STATUS_FILTERS = new Set(["all", "unreviewed", "reviewed", "unstable"]);

/**
 * Reflects the active tab and the sidebar's search/status filter in the URL query string (via
 * replaceState, so it doesn't spam browser history on every keystroke), so this state survives a
 * reload and can be bookmarked/shared — rather than relying on the browser's incidental "restore
 * form values on reload" behaviour, which only covers a same-tab reload.
 */
function updateUrl(): void {
  const tab = TABS.find((name) => el(`tab-${name}`).classList.contains("active")) ?? "review";
  const status = el<HTMLSelectElement>("filter-status").value;
  const q = el<HTMLInputElement>("search").value;

  const url = new URL(globalThis.location.href);
  if (tab === "review") url.searchParams.delete("tab");
  else url.searchParams.set("tab", tab);
  if (status === "all" || status === "") url.searchParams.delete("status");
  else url.searchParams.set("status", status);
  if (q === "") url.searchParams.delete("q");
  else url.searchParams.set("q", q);
  if (state.mutatorScope === "") url.searchParams.delete("mutator");
  else url.searchParams.set("mutator", state.mutatorScope);
  if (state.compareMinMutations === 1) url.searchParams.delete("min");
  else url.searchParams.set("min", String(state.compareMinMutations));

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

  // Read before the switchTab() below, which writes the current state back to the URL: the scope
  // selector cannot be populated until the report has loaded, so the value is parked in state and
  // validated against the option list in populateMutatorScope().
  state.mutatorScope = params.get("mutator") ?? "";

  const min = el<HTMLSelectElement>("compare-min-mutations");
  const wantedMin = params.get("min");
  if (wantedMin !== null && [...min.options].some((option) => option.value === wantedMin)) {
    min.value = wantedMin;
    state.compareMinMutations = Number(wantedMin);
  }

  const tab = params.get("tab");
  switchTab(TABS.find((name) => name === tab) ?? "review");
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

  renderReviewProgress(
    { label: "review-progress-label", bar: "review-progress-bar", fill: "review-progress-fill" },
    state.mutations,
  );
}

/**
 * How much of the review workflow is done — the whole point of this tool — shown both above the
 * sidebar's mutation list and above the Metrics tab's donut (hence the id parameters: same
 * rendering, two independent DOM targets). Goes through computeMetrics() rather than
 * `state.reviews.size` directly so a review left over from a mutation no longer in the current
 * report (e.g. the report was regenerated) can't inflate this past what's actually reviewed.
 */
function renderReviewProgress(
  ids: { label: string; bar: string; fill: string },
  mutations: ReadonlyMap<string, IndexedMutation>,
): void {
  const total = mutations.size;
  const reviewed = computeMetrics(mutations, state.reviews).reviewedCount;
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

function renderStats(mutations: ReadonlyMap<string, IndexedMutation>): void {
  const stats = el("stats");
  stats.innerHTML = "";

  const counts: Record<string, number> = {};
  // Runs are counted from the observations in scope rather than from state.runIds: scoped to a
  // mutator that a given run generated nothing for, that run contributes no evidence here and
  // saying otherwise would overstate how repeatedly this mutator was observed.
  const runIds = new Set<string>();
  for (const { observations } of mutations.values()) {
    for (const o of observations) {
      counts[o.detectionStatus] = (counts[o.detectionStatus] ?? 0) + 1;
      runIds.add(o.runId);
    }
  }

  const chips: string[] = [
    `Runs: <b>${runIds.size}</b>`,
    `Mutations: <b>${mutations.size}</b>`,
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

  // Emptying the wrapper drops the canvas but not the Chart bound to it; scoping to a mutator
  // with no evaluated observation would otherwise leak the previous scope's chart instance.
  statusChart?.destroy();
  statusChart = undefined;

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

/** The subcategory on its own, sentence-cased: "non-actionable — equivalent" → "Equivalent". */
function subcategoryLabel(classification: string): string {
  const subcategory = classification.replace(/^non-actionable — /, "");

  return subcategory.charAt(0).toUpperCase() + subcategory.slice(1);
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
type MetricDefinition = {
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
  /**
   * Present when the metric can be charted per mutator on the Compare tab. Omitted for a metric
   * that only means something report-wide — a total that would rank mutators by how often they
   * fire rather than by how they behave.
   */
  compare?: {
    /** null leaves that mutator out of this chart: no denominator is not the same as zero. */
    value: (m: Metrics) => number | null;
    format: (value: number) => string;
    /** "percent" fixes the axis at 0–100%, so a bar length means the same thing in every rate chart. */
    axis: "percent" | "linear";
    /** Which end of the scale is the good one. Drives the chart's subtitle only, never the order. */
    better?: "higher" | "lower";
    /**
     * Replaces the card's description on the Compare tab. Set it when the chart plots something the
     * description does not describe — a different statistic, or a filtered subset of the mutators.
     */
    note?: string;
    /**
     * Mutators this chart leaves out because their value carries no comparison signal — a metric
     * already at its target has nothing to rank against. Only on the Compare tab: the Metrics tab's
     * card still counts them, because they are part of the figure.
     */
    omit?: {
      when: (value: number) => boolean;
      /** Describes what was left out, for the count under the chart: "48 mutators at 100%". */
      omitted: string;
      /** Describes what is left, for the chart's own heading: "Syntactic validity below 100%". */
      remaining: string;
    };
  };
};

const METRIC_DEFINITIONS: readonly MetricDefinition[] = [
  {
    label: "Evaluated",
    value: (m) => m.evaluated,
    description: "Mutations Infection ran mutant evaluation for — excludes not covered, skipped, and ignored. " +
      "Informational only: there's no target value, just the denominator behind the metrics below.",
    formula: "count(mutations with ≥1 evaluated observation)",
    formulaLegend: "Excludes not covered, skipped, and ignored — statuses with no mutant process at all.",
    group: "report",
    compare: { value: (m) => m.evaluated, format: (v) => String(v), axis: "linear" },
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
    compare: {
      value: (m) => m.syntacticValidityRate,
      format: pct,
      axis: "percent",
      better: "higher",
      note: "The mutators that produced a syntax error in at least one evaluated mutation, by the share of their " +
        "mutations that still parsed. Every bar here is a suspected mutator defect, and a shorter one is a bigger one.",
      // Validity is 100% for nearly every mutator, so charting them all is one short bar buried in
      // a wall of full ones. The chart is the exception report; the count of the rest is stated.
      omit: { when: (value) => value === 1, omitted: "at 100%", remaining: "below 100%" },
    },
  },
  {
    label: "Test workload",
    value: (m) => m.workload,
    description: "Total tests selected across evaluated mutations — the workload behind the runtime below. " +
      "Informational only: no target value, just context for interpreting Recorded runtime.",
    formula: "Σ tests selected",
    formulaLegend: "Summed over every evaluated observation.",
    group: "report",
    compare: {
      value: (m) => m.meanTestsSelected,
      format: (v) => v.toFixed(1),
      axis: "linear",
      note: "Mean tests per evaluated observation, not the total above — a total ranks mutators by how often " +
        "they fire, which is not a property of the mutator's behaviour.",
    },
  },
  {
    label: "Recorded runtime",
    value: (m) => m.runtime.toFixed(2) + "s",
    description: "Total decisive-process wall time across evaluated mutations. " +
      "Informational only: no target value — expected runtime scales with Test workload above.",
    formula: "Σ decisive-process runtime (seconds)",
    formulaLegend: "Summed over every evaluated observation.",
    group: "report",
    compare: {
      value: (m) => m.meanRuntimeSeconds,
      format: (v) => v.toFixed(3) + "s",
      axis: "linear",
      note: "Mean decisive-process runtime per timed observation, not the total above. Observations with no " +
        "recorded process are left out of the denominator rather than counted as instant.",
    },
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
    compare: {
      value: (m) => m.instabilityRate,
      format: pct,
      axis: "percent",
      better: "lower",
      note: "The mutators with at least one mutation whose status changed between runs, by the share of their " +
        "repeatedly-evaluated mutations that changed. Still a reliability check on the run, not on the mutator: a bar " +
        "points at a flaky test or environment noise, not at a defect in the mutator it names.",
      // The mirror of syntactic validity: a stable mutator is the expected case and every one of
      // them is a zero-length bar, so the chart is the exception report and the rest is a count.
      omit: { when: (value) => value === 0, omitted: "at 0%", remaining: "above 0%" },
    },
    valueStatus: (m) => m.instabilityRate === null ? undefined : m.instabilityRate === 0 ? "good" : "critical",
  },
  {
    label: "Actionability",
    value: (m) => pct(m.actionabilityRate),
    description: "Of classified mutations, the share that identify a specific, justified test or subject " +
      "improvement. Higher is better, though there's no universal threshold — it's set per project.",
    formula: "Actionable ÷ (Actionable + Non-actionable)",
    formulaLegend: "Counted from your review classifications; “cannot determine” reviews are excluded from both terms.",
    group: "review",
    compare: { value: (m) => m.actionabilityRate, format: pct, axis: "percent", better: "higher" },
  },
  {
    label: "Unresolved",
    value: (m) => pct(m.unresolvedProportion),
    description: "Share of reviewed mutations marked “cannot determine”. " +
      "Target as low as possible — high weakens confidence in the Actionability result above.",
    formula: "Cannot-determine ÷ (Actionable + Non-actionable + Cannot-determine)",
    formulaLegend: "Equivalent to cannot-determine ÷ all reviewed mutations.",
    group: "review",
    compare: { value: (m) => m.unresolvedProportion, format: pct, axis: "percent", better: "lower" },
  },
  // One card per non-actionable subcategory, generated from the classification list rather than
  // written out three times — the three differ only in which classification they count. The doc
  // reports them alongside the actionability rate because each points somewhere different: too many
  // equivalent mutations question the mutator's guards, redundant ones its overlap with another
  // mutator, irrelevant or arid ones the transformation itself.
  ...NON_ACTIONABLE_CLASSIFICATIONS.map((classification): MetricDefinition => ({
    // "non-actionable — equivalent" → "Equivalent". The prefix is dropped because the card sits in
    // the review group directly under Actionability, where repeating it on all three says nothing.
    label: subcategoryLabel(classification.value),
    value: (m) => pct(m.nonActionableRates[classification.value]),
    description: `${classification.description} Reported as a share of classified mutations, on the same ` +
      "denominator as Actionability above — so the three subcategories and Actionability sum to 100%, and " +
      "the one to act on is whichever dominates rather than any single target value.",
    formula: `${subcategoryLabel(classification.value)} ÷ (Actionable + Non-actionable)`,
    formulaLegend: "Counted from your review classifications; “cannot determine” reviews are excluded from both " +
      "terms, exactly as in Actionability.",
    group: "review",
    compare: {
      value: (m) => m.nonActionableRates[classification.value],
      format: pct,
      axis: "percent",
      // Same reasoning as Instability: a mutator with none of this subcategory is the expected
      // case and charts as a zero-length bar, so the chart is the exception report.
      omit: { when: (value) => value === 0, omitted: "at 0%", remaining: "above 0%" },
    },
  })),
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

/**
 * The mutations the Metrics tab is currently reporting on. Everything on that tab — the chips, the
 * donut, the review progress bar, and every card — reads from this one function, so a scoped page
 * can never mix a scoped figure with a report-wide one.
 */
function scopedMutations(): ReadonlyMap<string, IndexedMutation> {
  return state.mutatorScope === "" ? state.mutations : filterByMutator(state.mutations, state.mutatorScope);
}

/**
 * Fills the scope selector from the report itself, so it only ever offers mutators that actually
 * generated something. Each option carries its mutation count because that count is the sample size
 * behind every rate on the page: a 100% validity over 3 mutations and over 300 look identical on a
 * card, and the selector is the only place that difference can be shown once.
 */
function populateMutatorScope(): void {
  const select = el<HTMLSelectElement>("metrics-mutator");
  const wanted = state.mutatorScope;

  select.innerHTML = "";
  const all = document.createElement("option");
  all.value = "";
  all.textContent = `All mutators (${state.mutations.size})`;
  select.appendChild(all);

  for (const { name, mutationCount } of summariseMutators(state.mutations)) {
    const option = document.createElement("option");
    option.value = name;
    option.textContent = `${name === "" ? "(unknown mutator)" : name} (${mutationCount})`;
    select.appendChild(option);
  }

  // A scope restored from the URL can name a mutator this report has no mutation for — the report
  // was regenerated, or the link came from a different corpus. Fall back to every mutator rather
  // than showing an empty page for a scope the selector cannot even display.
  const known = [...select.options].some((option) => option.value === wanted);
  state.mutatorScope = known ? wanted : "";
  select.value = state.mutatorScope;
  updateUrl();
}

function renderMetrics(): void {
  const mutations = scopedMutations();
  const metrics = computeMetrics(mutations, state.reviews);

  renderStats(mutations);

  // Lives in the Overview section's markup (above the donut), not the grid below — rendered from
  // here, like everything else on this tab, so it stays live after every review save and follows
  // the scope rather than always reporting the whole report.
  renderReviewProgress({
    label: "metrics-review-progress-label",
    bar: "metrics-review-progress-bar",
    fill: "metrics-review-progress-fill",
  }, mutations);

  el("metrics-scope-hint").textContent = state.mutatorScope === ""
    ? "Every mutation in the report."
    : `${mutations.size} mutation${mutations.size === 1 ? "" : "s"} generated by ${state.mutatorScope}.`;

  renderMetricsGrid("metrics-grid-report", "report", metrics);
  renderMetricsGrid("metrics-grid-review", "review", metrics);
}

let compareCharts: Chart<"bar">[] = [];

/** How many mutators a card names before falling back to a count. */
const MAX_NAMED_MUTATORS = 5;

/**
 * Draws each bar's value just past its tip. A bar chart's whole job is comparing lengths, and the
 * exact figure at the end is what keeps the value axis recessive; the chart reserves right-hand
 * padding for this, so a label never overflows the canvas or sits on top of its own bar.
 */
const barValuePlugin = {
  id: "barValue",
  afterDatasetsDraw(chart: Chart): void {
    const labels = (chart.config.data.datasets[0] as { valueLabels?: string[] }).valueLabels;
    if (labels === undefined) return;

    const { ctx } = chart;
    ctx.save();
    ctx.textAlign = "left";
    ctx.textBaseline = "middle";
    ctx.fillStyle = token("--text-muted");
    ctx.font = "11px system-ui, sans-serif";

    for (const [i, element] of chart.getDatasetMeta(0).data.entries()) {
      ctx.fillText(labels[i] ?? "", element.x + 8, element.y);
    }

    ctx.restore();
  },
};

/**
 * Draws the middle half of the mutators as a band, and the median as a line through it, behind the
 * bars. This is the context a sorted bar chart cannot give on its own: the bars say which mutator
 * is highest, the band says whether the spread between them is worth acting on.
 */
const referencePlugin = {
  id: "reference",
  beforeDatasetsDraw(chart: Chart): void {
    const dataset = chart.config.data.datasets[0] as { spread?: Spread; spreadLabel?: string };
    const spread = dataset.spread;
    if (spread === undefined) return;

    const { ctx, chartArea: { top, bottom } } = chart;
    const x = chart.scales.x;
    const left = x.getPixelForValue(spread.p25);
    const right = x.getPixelForValue(spread.p75);
    const median = x.getPixelForValue(spread.median);

    ctx.save();
    ctx.fillStyle = token("--reference-band");
    ctx.fillRect(left, top, right - left, bottom - top);

    ctx.strokeStyle = token("--reference-line");
    ctx.lineWidth = 1;
    ctx.beginPath();
    // Half-pixel offset: a 1px line on an integer coordinate straddles two device pixels and
    // renders as a 2px blur.
    ctx.moveTo(Math.round(median) + 0.5, top);
    ctx.lineTo(Math.round(median) + 0.5, bottom);
    ctx.stroke();

    // The label flips to the left of the line near the right edge, rather than being clipped.
    const label = dataset.spreadLabel ?? "";
    const flip = median > (chart.chartArea.left + chart.chartArea.right) / 2;
    ctx.fillStyle = token("--text-muted");
    ctx.font = "11px system-ui, sans-serif";
    ctx.textAlign = flip ? "right" : "left";
    ctx.textBaseline = "bottom";
    ctx.fillText(label, median + (flip ? -5 : 5), top - 3);
    ctx.restore();
  },
};

/**
 * One horizontal bar chart for one metric, one bar per mutator. Single series throughout, so every
 * bar wears the same accent hue: the mutator's identity is its axis label, and colouring bars by
 * value or by rank would encode the length a second time in a channel that cannot be read exactly.
 */
function buildCompareChart(
  definition: CompareDefinition,
  rows: ReadonlyArray<{ label: string; value: number }>,
  spread: Spread | null,
): HTMLDivElement {
  const wrap = document.createElement("div");
  wrap.className = "chart-wrap";
  // 26px a row is the bar (max 18px) plus the air the spec wants around it; the floor keeps a
  // one-mutator chart from collapsing to a sliver, and the last term is the median label's line.
  wrap.style.height = `${Math.max(90, rows.length * 26 + 34 + (spread === null ? 0 : 16))}px`;

  const canvas = document.createElement("canvas");
  wrap.appendChild(canvas);

  const { format, axis } = definition.compare;
  const percent = axis === "percent";

  compareCharts.push(
    new Chart<"bar">(canvas, {
      type: "bar",
      data: {
        labels: rows.map((r) => r.label),
        datasets: [{
          data: rows.map((r) => r.value),
          backgroundColor: token("--accent"),
          borderRadius: 4,
          borderSkipped: "start",
          maxBarThickness: 18,
          valueLabels: rows.map((r) => format(r.value)),
          spread: spread ?? undefined,
          spreadLabel: spread === null ? undefined : `median ${format(spread.median)}`,
        } as never],
      },
      options: {
        indexAxis: "y",
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        // Top padding is where the median label goes; without it the label is clipped by the canvas.
        layout: { padding: { right: 64, top: spread === null ? 0 : 16 } },
        scales: {
          x: {
            beginAtZero: true,
            // A rate always spans the full 0-100%, so two rate charts can be compared by eye; a
            // count or a duration has no such ceiling and is left to fit its own data.
            max: percent ? 1 : undefined,
            border: { display: false },
            grid: { color: token("--border") },
            ticks: {
              color: token("--text-muted"),
              font: { size: 11 },
              callback: (value) => percent ? `${Number(value) * 100}%` : String(value),
            },
          },
          y: {
            border: { display: false },
            grid: { display: false },
            ticks: { color: token("--text"), font: { size: 11 }, autoSkip: false },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (item) => format(item.parsed.x) } },
        },
      },
      plugins: [referencePlugin, barValuePlugin],
    }),
  );

  return wrap;
}

/** A metric definition known to be chartable — narrows `compare` away from undefined for the callees. */
type CompareDefinition = MetricDefinition & { compare: NonNullable<MetricDefinition["compare"]> };

function buildCompareCard(definition: CompareDefinition, perMutator: readonly MutatorMetrics[]): HTMLDivElement {
  const card = document.createElement("div");
  card.className = "compare-card";

  const heading = document.createElement("h3");
  // The heading carries the filter, not just the note under the chart: a reader who scrolls past a
  // half-empty "Syntactic validity" chart must not read it as the whole population.
  heading.textContent = definition.compare.omit === undefined
    ? definition.label
    : `${definition.label} ${definition.compare.omit.remaining}`;
  if (definition.compare.better !== undefined) {
    const hint = document.createElement("span");
    hint.className = "compare-better";
    hint.textContent = `${definition.compare.better} is better`;
    heading.appendChild(hint);
  }

  const desc = document.createElement("p");
  desc.className = "desc";
  desc.textContent = definition.compare.note ?? definition.description;
  card.append(heading, desc);

  const rows = perMutator.map(({ name, mutationCount, metrics }) => ({
    name,
    label: `${name === "" ? "(unknown mutator)" : name} (${mutationCount})`,
    value: definition.compare.value(metrics),
  }));

  // Each chart ranks on its own value, largest bar first, so the chart reads as a ranking of the
  // thing it plots. Equal values fall back to the mutator name, which keeps the order stable: a
  // chart where every mutator scores the same (all-100% validity, all-0% instability is common)
  // would otherwise shuffle its rows on every re-render.
  const measured = rows
    .filter((row): row is typeof rows[number] & { value: number } => row.value !== null)
    .sort((a, b) => b.value - a.value || a.name.localeCompare(b.name));

  const { omit } = definition.compare;
  const omitted = omit === undefined ? [] : measured.filter((row) => omit.when(row.value));
  const plotted = omit === undefined ? measured : measured.filter((row) => !omit.when(row.value));

  if (plotted.length === 0) {
    const none = document.createElement("p");
    none.className = "compare-missing";
    // Two different nothings: every mutator is at target, or none has been measured at all.
    none.textContent = omitted.length > 0
      ? `Every measured mutator is ${omit?.omitted} — nothing to compare.`
      : "No mutator has a value for this metric yet.";
    card.appendChild(none);

    return card;
  }

  const spread = summariseSpread(plotted.map((row) => row.value));
  card.appendChild(buildCompareChart(definition, plotted, spread));

  // The band and the line have no legend of their own — a two-entry legend box for context marks
  // would outweigh them — so they are named here, with the numbers spelled out for anyone who
  // cannot read them off the chart.
  if (spread !== null) {
    const legend = document.createElement("p");
    legend.className = "compare-legend";
    const { format } = definition.compare;
    legend.textContent = `Band: the middle half of these mutators, ${format(spread.p25)} to ${format(spread.p75)}. ` +
      `Line: the median, ${format(spread.median)}.`;
    card.appendChild(legend);
  }

  if (omitted.length > 0) {
    const hidden = document.createElement("p");
    hidden.className = "compare-missing";
    hidden.textContent = `Not shown: ${omitted.length} mutator${omitted.length === 1 ? "" : "s"} ${omit?.omitted}.`;
    card.appendChild(hidden);
  }

  // A missing value is not a zero — the mutator has no denominator for this metric (nothing
  // evaluated, nothing repeated, nothing classified). Naming them keeps a mutator from looking
  // like it scored badly when it was simply not measured.
  if (measured.length < rows.length) {
    const unmeasured = rows
      .filter((row) => row.value === null)
      .sort((a, b) => a.name.localeCompare(b.name))
      .map((row) => row.label);

    // Naming them is the point — an unmeasured mutator must not read as one that scored badly —
    // but a review that has barely started leaves every mutator unmeasured, and a list of 48 names
    // is a wall of text nobody reads. Name the first few, count the rest.
    const named = unmeasured.slice(0, MAX_NAMED_MUTATORS);
    const rest = unmeasured.length - named.length;

    const missing = document.createElement("p");
    missing.className = "compare-missing";
    missing.textContent = `No data: ${named.join(", ")}${rest > 0 ? ` and ${rest} more` : ""}`;
    card.appendChild(missing);
  }

  return card;
}

function renderCompareGrid(gridId: string, group: "report" | "review", perMutator: readonly MutatorMetrics[]): void {
  const grid = el(gridId);
  grid.innerHTML = "";

  for (const definition of METRIC_DEFINITIONS) {
    if (definition.group !== group || definition.compare === undefined) continue;
    grid.appendChild(buildCompareCard(definition as CompareDefinition, perMutator));
  }
}

/**
 * The Compare tab: the same metrics as the Metrics tab, one bar per mutator instead of one number.
 * Only metrics with a `compare` spec appear — a report-wide total would rank mutators by how often
 * each one fires rather than by how it behaves, so those are charted as per-observation means.
 */
function renderCompare(): void {
  const all = computeMutatorMetrics(state.mutations, state.reviews);
  const perMutator = all.filter((m) => m.mutationCount >= state.compareMinMutations);

  const shown = perMutator.length === all.length
    ? `${all.length} mutator${all.length === 1 ? "" : "s"} in the report`
    : `Showing ${perMutator.length} of ${all.length} mutators`;
  el("compare-scope-hint").textContent = `${shown}. Each chart is sorted by its own value, ties by mutator name.`;

  for (const chart of compareCharts) chart.destroy();
  compareCharts = [];

  renderCompareGrid("compare-grid-report", "report", perMutator);
  renderCompareGrid("compare-grid-review", "review", perMutator);
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
  renderCompareIfActive();
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

el("metrics-mutator").addEventListener("change", (event) => {
  state.mutatorScope = (event.target as HTMLSelectElement).value;
  renderMetrics();
  updateUrl();
});

el("compare-min-mutations").addEventListener("change", (event) => {
  state.compareMinMutations = Number((event.target as HTMLSelectElement).value);
  renderCompare();
  updateUrl();
});

for (const btn of document.querySelectorAll<HTMLButtonElement>(".tab-btn")) {
  btn.addEventListener("click", () => switchTab(TABS.find((name) => name === btn.dataset.tab) ?? "review"));
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
