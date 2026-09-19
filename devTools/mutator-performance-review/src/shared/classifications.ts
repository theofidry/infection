// Ported from WorkbookBuilder::CLASSIFICATIONS (devTools/mutator-performance-workbook/src/WorkbookBuilder.php)
// and doc/mutator-performance.md's Actionability Rate section, which is the source of truth for these values.

export type ClassificationKind = "actionable" | "non-actionable" | "unresolved";

export interface ClassificationOption {
  value: string;
  kind: ClassificationKind;
  description: string;
}

// Descriptions are verbatim from doc/mutator-performance.md's Actionability Rate section — keep
// them in sync with that doc rather than paraphrasing here.
export const CLASSIFICATIONS: readonly ClassificationOption[] = [
  { value: "actionable — tests", kind: "actionable", description: "Add or strengthen a test." },
  { value: "actionable — subject", kind: "actionable", description: "Correct a defect in the subject." },
  {
    value: "non-actionable — equivalent",
    kind: "non-actionable",
    description: "The mutant and subject have the same observable behaviour.",
  },
  {
    value: "non-actionable — redundant",
    kind: "non-actionable",
    description: "The same testing requirement is already represented by another mutation.",
  },
  {
    value: "non-actionable — irrelevant or arid",
    kind: "non-actionable",
    description: "Detecting the change would not exercise a meaningful requirement.",
  },
  { value: "cannot determine", kind: "unresolved", description: "The available evidence is insufficient." },
];

export function classificationKind(value: string): ClassificationKind | null {
  return CLASSIFICATIONS.find((c) => c.value === value)?.kind ?? null;
}

/**
 * The non-actionable options, in declaration order. Reported as a breakdown of their own
 * (doc/mutator-performance.md, Actionability Rate: the report "includes the non-actionable
 * subcategories, which indicate whether the applicability guards, duplicate suppression, or
 * transformation may require reconsideration"), so metrics and charts derive them from here rather
 * than naming the three values a second time.
 */
export const NON_ACTIONABLE_CLASSIFICATIONS: readonly ClassificationOption[] = CLASSIFICATIONS.filter(
  (classification) => classification.kind === "non-actionable",
);
