<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Connection\Analysis;

use PDO;

/**
 * Adds the relationship graph schema to a copied synchronization database.
 */
final class SchemaInitializer
{
    private const string STATEMENT = <<<'SQL'
        CREATE TABLE work_item_relationships (
            id INTEGER PRIMARY KEY,
            source_item_id INTEGER NOT NULL,
            target_item_id INTEGER NOT NULL,
            relationship_type TEXT NOT NULL CHECK (relationship_type IN ('closes', 'references')),
            FOREIGN KEY (source_item_id) REFERENCES items (id),
            FOREIGN KEY (target_item_id) REFERENCES items (id),
            UNIQUE (source_item_id, target_item_id, relationship_type)
        );

        CREATE TABLE relationship_evidence (
            id INTEGER PRIMARY KEY,
            relationship_id INTEGER NOT NULL,
            source_field TEXT NOT NULL CHECK (source_field IN ('title', 'body')),
            evidence_text TEXT NOT NULL,
            FOREIGN KEY (relationship_id) REFERENCES work_item_relationships (id) ON DELETE CASCADE,
            UNIQUE (relationship_id, source_field, evidence_text)
        );

        CREATE TABLE unresolved_work_item_references (
            id INTEGER PRIMARY KEY,
            source_item_id INTEGER NOT NULL,
            source_field TEXT NOT NULL CHECK (source_field IN ('title', 'body')),
            target_owner TEXT,
            target_repository TEXT,
            target_number INTEGER NOT NULL,
            reason TEXT NOT NULL CHECK (reason IN ('external_repository', 'missing_item')),
            evidence_text TEXT NOT NULL,
            FOREIGN KEY (source_item_id) REFERENCES items (id),
            UNIQUE (source_item_id, source_field, target_owner, target_repository, target_number,
                    reason, evidence_text)
        );

        CREATE TABLE pull_request_categories (
            id INTEGER PRIMARY KEY,
            pull_request_id INTEGER NOT NULL,
            category TEXT NOT NULL CHECK (
                category IN (
                    'feature',
                    'bugfix',
                    'performance',
                    'refactoring',
                    'maintenance',
                    'dependency',
                    'documentation',
                    'testing',
                    'build_ci',
                    'internal_tooling'
                )
            ),
            FOREIGN KEY (pull_request_id) REFERENCES items (id),
            UNIQUE (pull_request_id, category)
        );

        CREATE TABLE pull_request_category_evidence (
            id INTEGER PRIMARY KEY,
            pull_request_category_id INTEGER NOT NULL,
            source TEXT NOT NULL CHECK (source IN ('label', 'title', 'body')),
            evidence_text TEXT NOT NULL,
            FOREIGN KEY (pull_request_category_id) REFERENCES pull_request_categories (id) ON DELETE CASCADE,
            UNIQUE (pull_request_category_id, source, evidence_text)
        );

        CREATE VIEW issue_pull_request_relationships AS
        SELECT
            CASE WHEN source.type = 'issue' THEN source.id ELSE target.id END AS issue_id,
            CASE WHEN source.type = 'pull_request' THEN source.id ELSE target.id END AS pull_request_id,
            CASE WHEN source.type = 'issue' THEN source.html_url ELSE target.html_url END AS issue_url,
            CASE WHEN source.type = 'pull_request' THEN source.html_url ELSE target.html_url END AS pull_request_url,
            work_item_relationships.id AS relationship_id,
            work_item_relationships.relationship_type,
            source.id AS referenced_from_item_id
        FROM work_item_relationships
        JOIN items source ON source.id = work_item_relationships.source_item_id
        JOIN items target ON target.id = work_item_relationships.target_item_id
        WHERE (source.type = 'issue' AND target.type = 'pull_request')
           OR (source.type = 'pull_request' AND target.type = 'issue');

        CREATE VIEW issue_pull_request_associations AS
        SELECT DISTINCT issue_id, pull_request_id
        FROM issue_pull_request_relationships;

        CREATE VIEW work_items_without_relationships AS
        SELECT
            item.id AS item_id,
            repository.owner AS repository_owner,
            repository.name AS repository_name,
            item.type AS item_type,
            item.number AS item_number,
            item.title AS item_title,
            item.html_url AS item_url,
            item.closed_at
        FROM items item
        JOIN repositories repository ON repository.id = item.repository_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM work_item_relationships relationship
            WHERE relationship.source_item_id = item.id
               OR relationship.target_item_id = item.id
        );

        CREATE VIEW unresolved_work_item_reference_details AS
        SELECT
            unresolved_work_item_references.id AS reference_id,
            source.id AS source_item_id,
            source.type AS source_item_type,
            source.number AS source_item_number,
            source.title AS source_item_title,
            source.html_url AS source_item_url,
            unresolved_work_item_references.source_field,
            unresolved_work_item_references.evidence_text,
            COALESCE(unresolved_work_item_references.target_owner, source_repository.owner) AS target_owner,
            COALESCE(unresolved_work_item_references.target_repository, source_repository.name) AS target_repository,
            unresolved_work_item_references.target_number,
            printf(
                'https://github.com/%s/%s/issues/%d',
                COALESCE(unresolved_work_item_references.target_owner, source_repository.owner),
                COALESCE(unresolved_work_item_references.target_repository, source_repository.name),
                unresolved_work_item_references.target_number
            ) AS target_url,
            unresolved_work_item_references.reason
        FROM unresolved_work_item_references
        JOIN items source ON source.id = unresolved_work_item_references.source_item_id
        JOIN repositories source_repository ON source_repository.id = source.repository_id;

        CREATE VIEW pull_request_category_details AS
        SELECT
            pull_request.html_url AS pull_request_url,
            pull_request.title AS pull_request_title,
            pull_request.created_at,
            COALESCE(pull_request.merged_at, pull_request.closed_at) AS closed_at,
            pull_request_categories.category,
            pull_request_category_evidence.source AS evidence_source,
            pull_request_category_evidence.evidence_text,
            pull_request.id AS pull_request_id
        FROM pull_request_categories
        JOIN items pull_request ON pull_request.id = pull_request_categories.pull_request_id
        JOIN pull_request_category_evidence
          ON pull_request_category_evidence.pull_request_category_id = pull_request_categories.id
        WHERE pull_request.draft = 0
          AND (pull_request.state = 'open' OR pull_request.merged_at IS NOT NULL);

        CREATE VIEW uncategorized_pull_requests AS
        SELECT
            pull_request.html_url AS pull_request_url,
            pull_request.title AS pull_request_title,
            pull_request.created_at,
            COALESCE(pull_request.merged_at, pull_request.closed_at) AS closed_at,
            NULL AS category,
            NULL AS evidence_source,
            NULL AS evidence_text,
            pull_request.id AS pull_request_id
        FROM items pull_request
        WHERE pull_request.type = 'pull_request'
          AND pull_request.draft = 0
          AND (pull_request.state = 'open' OR pull_request.merged_at IS NOT NULL)
          AND NOT EXISTS (
              SELECT 1
              FROM pull_request_categories
              WHERE pull_request_categories.pull_request_id = pull_request.id
          );
        SQL;

    public function initialize(PDO $pdo): void
    {
        $pdo->exec(self::STATEMENT);
    }
}
