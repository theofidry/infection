# GitHub work analysis

This tool builds an offline graph of explicit relationships between GitHub issues and pull
requests (PRs).

## Getting started

The project populates a `var/github.sqlite` SQLite database pulling data from GitHub using the
following command:

```shell
bin/console github:synchronize
```

This does not require to be authenticated, but it is preferable to avoid rate limiting. To do so,
you can export a `GH_TOKEN` environment variable (using `gh auth token` or if within a sandbox `sbx secret set github --command 'gh auth token' --refresh on-demand`).

You can verify your status with:

```shell
bin/console github:ping
```

## Analysis

The idea is to pull data once from GitHub and then be able to work offline. The project ships an analysis pipeline which allows to both enrich the data and extract various pieces of information. To achieve this, the data contained in `var/github.sqlite` is imported into a separate database `var/github-work-analysis.sqlite`. The former should only be used as a readonly offline source.

## Analysis inputs and output

The default input is `var/github.sqlite`. The GitHub dataset importer creates this source
snapshot. The analysis opens a copy of the snapshot and does not change the source database.

The default output is `var/github-work-analysis.sqlite`. This database is disposable. Each run
replaces it with the synchronized source database before it adds the analysis schema and data. If
the analysis fails, run the command again to replace the incomplete output.

The default paths are container parameters:

```yaml
github_work_analysis.source_database: '%kernel.project_dir%/var/github.sqlite'
github_work_analysis.result_database: '%kernel.project_dir%/var/github-work-analysis.sqlite'
```

Change these values in `config/services.yaml`. The container injects the configured source
database into the import services. It injects both paths into the analysis service.

Run the analysis from the repository root:

```shell
bin/console analyze
```

The build copies all source tables, indexes, and constraints. It does not keep a fixed list of
source tables. It then adds the analysis tables and views described below. The analysis database
is disposable and can be regenerated after a schema change.

The command prints relationship counts followed by pull request category counts:

- `Relationships` is the number of directed, typed graph edges.
- `Issue/PR associations` is the number of distinct issue and PR pairs.
- `Unresolved references` is the number of references that cannot become local graph edges.
- Each `Pull requests categorized as ...` line counts PRs assigned to that category.
- `Uncategorized pull requests` counts PRs for which the available evidence produced no category.

These counts measure different things. For example, one PR can both reference and close the same
issue. This creates two relationships but only one association.

## Pull request category dashboard

Generate the interactive HTML dashboard after the analysis completes:

```shell
bin/console report:pull-request-categories
```

The command reads the analysis views and writes
`var/pull-requests-by-category-over-time.html`. The report contains the total PR volume, detailed
category trends, aggregated categories, and the breakdown of the aggregated `Other` category. Its
time-window controls update all charts.

## Pull request categorization

The categorization processor uses only a PR's labels, title, and body. It does not use associated
issues. A PR can have zero, one, or several categories:

- `feature`
- `bugfix`
- `performance`
- `refactoring`
- `maintenance`
- `dependency`
- `documentation`
- `testing`
- `build_ci`
- `internal_tooling`

The first rule set is deliberately conservative. It recognizes selected historical labels and
conventional prefixes such as `feat:`, `fix:`, and `refactor:`. A prefix in the body must begin a
line. Broad keyword matching is not used.

`pull_request_categories` stores each distinct PR and category assignment.
`pull_request_category_evidence` stores every signal that produced an assignment. Evidence records
whether the signal came from a `label`, `title`, or `body`, and preserves the matched text.

`pull_request_category_details` combines the assignments and evidence with PR details. Its
`closed_at` value is when a closed PR was closed or when a merged PR was merged.
`uncategorized_pull_requests` lists PRs for which no rule matched. It has the same columns as
`pull_request_category_details`; its category and evidence columns contain `NULL`.
Both views exclude drafts and PRs that were closed without being merged. Open non-draft and merged
PRs remain visible.

## Reference extraction

`ReferenceExtractor` reads issue and PR titles and descriptions. It recognizes these forms:

```text
#123
infection/infection#123
https://github.com/infection/infection/issues/123
https://github.com/infection/infection/pull/123
```

The extractor assigns one of two relationship types:

- `closes` for a reference after `close`, `closes`, `closed`, `fix`, `fixes`, `fixed`, `resolve`,
  `resolves`, or `resolved`;
- `references` for any other explicit reference.

A closing phrase can contain more than one target. For example, `Fixes #12, #13 and #14` creates
three `closes` relationships.

The extractor removes fenced code blocks, indented code blocks, and HTML comments before it finds
references. This prevents stack traces, code samples, and PR template instructions from creating
false relationships. The stored evidence is the visible source line that contains the reference.

## Graph model

All source issues and PRs are graph nodes. `work_item_relationships` stores directed edges. The
direction follows the source text:

```text
PR #20 -- closes --> Issue #10
Issue #10 -- references --> PR #20
```

The direction does not change the issue-to-PR association. It shows which item supplied the
evidence.

### `work_item_relationships`

This table stores:

- the source item;
- the target item;
- the `closes` or `references` type.

The unique constraint prevents the same typed edge from being added more than once. Self-references
do not create edges.

### `relationship_evidence`

This table stores the title or body field and the source line for each relationship. Evidence is
separate because one relationship can have evidence in more than one field or line.

### `unresolved_work_item_references`

This table stores references that do not resolve to an item in the local snapshot. The reason is:

- `external_repository` when the reference names a repository that is not in the snapshot;
- `missing_item` when the repository is known but the target number is absent.

An unresolved reference is a data-health fact. It is not a graph edge and it does not prove that
no relationship exists.

### `unresolved_work_item_reference_details`

This view adds source item details and normalized target details to unresolved references. For a
shorthand reference such as `#123`, it gets the target owner and repository from the source item.
The source and target URLs make the records easier to inspect.

### `issue_pull_request_relationships`

This view selects relationships where one endpoint is an issue and the other endpoint is a PR. It
normalizes the endpoints into `issue_id`, `issue_url`, `pull_request_id`, and `pull_request_url`. It
also keeps the relationship type and direction.

Use this view when the relationship type or evidence direction matters.

### `issue_pull_request_associations`

This view contains distinct `issue_id` and `pull_request_id` pairs. It removes duplicate pairs
caused by direction or by different relationship types.

Use this view to answer these questions:

- Which PRs are associated with this issue?
- Which issues are associated with this PR?

### `work_items_without_relationships`

This view lists issues and PRs that do not reference another local item and are not referenced by
another local item. In graph terms, these items have no incoming or outgoing relationship.
Unresolved references do not count because they do not identify a local target.

## Inspection queries

Count PRs by category:

```sql
SELECT category, COUNT(*) AS pull_request_count
FROM pull_request_categories
GROUP BY category
ORDER BY pull_request_count DESC, category;
```

Inspect the evidence for a sample of categorized PRs:

```sql
SELECT pull_request_url,
       pull_request_title,
       created_at,
       closed_at,
       category,
       evidence_source,
       evidence_text
FROM pull_request_category_details
ORDER BY pull_request_url, category, evidence_source, evidence_text;
```

List PRs with no category:

```sql
SELECT pull_request_url,
       pull_request_title,
       created_at,
       closed_at
FROM uncategorized_pull_requests
ORDER BY pull_request_url;
```

List all PRs associated with issue `123`:

```sql
SELECT pull_request.number, pull_request.title, pull_request.html_url
FROM issue_pull_request_associations association
JOIN items issue ON issue.id = association.issue_id
JOIN items pull_request ON pull_request.id = association.pull_request_id
WHERE issue.number = 123
ORDER BY pull_request.number;
```

List all issues associated with PR `456`:

```sql
SELECT issue.number, issue.title, issue.html_url
FROM issue_pull_request_associations association
JOIN items issue ON issue.id = association.issue_id
JOIN items pull_request ON pull_request.id = association.pull_request_id
WHERE pull_request.number = 456
ORDER BY issue.number;
```

Inspect the relationship types, direction, and evidence for one issue and PR pair:

```sql
SELECT relationship.relationship_type,
       source.type AS evidence_item_type,
       source.number AS evidence_item_number,
       evidence.source_field,
       evidence.evidence_text
FROM issue_pull_request_relationships issue_pr
JOIN work_item_relationships relationship ON relationship.id = issue_pr.relationship_id
JOIN items issue ON issue.id = issue_pr.issue_id
JOIN items pull_request ON pull_request.id = issue_pr.pull_request_id
JOIN items source ON source.id = issue_pr.referenced_from_item_id
JOIN relationship_evidence evidence ON evidence.relationship_id = relationship.id
WHERE issue.number = 123 AND pull_request.number = 456
ORDER BY relationship.id, evidence.id;
```

List work items without relationships:

```sql
SELECT repository_owner,
       repository_name,
       item_type,
       item_number,
       item_title,
       item_url,
       closed_at
FROM work_items_without_relationships
ORDER BY repository_owner, repository_name, item_number;
```

Inspect unresolved references:

```sql
SELECT source_item_type,
       source_item_number,
       source_item_title,
       source_item_url,
       source_field,
       evidence_text,
       target_owner,
       target_repository,
       target_number,
       target_url,
       reason
FROM unresolved_work_item_reference_details
ORDER BY source_item_number, reference_id;
```

## Limits

Relationship analysis uses explicit references only. It does not infer relationships from
similar titles, shared components, paths, authors, or dates. It does not contain manual
corrections.

Categorization is a rule-based first pass, not a semantic interpretation of every PR. Missing or
ambiguous signals can leave a PR uncategorized or assign categories that a consumer considers to
overlap. Review category evidence and samples before changing a rule.

The source snapshot has no comments, review discussions, timeline events, GitHub sidebar links,
or commit cross-reference events. A relationship that exists only in one of these sources is not
in the graph. Reports must state this limit.

The parser handles the explicit forms and Markdown exclusions listed above. It is not a complete
GitHub Markdown parser. Review unresolved records and result samples before a rule change.

## Tests and checks

The focused test covers extraction, relationship direction, distinct associations, unresolved
references, source database safety, and copied indexes.

Run it with a PHP build that has `pdo_sqlite`:

```shell
composer test
```

Run the complete reviewer checks before completion:

```shell
composer check
```
