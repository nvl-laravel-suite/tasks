# Upgrading NVL Tasks

## 3.0.0

Upgrade `nvl/activity` to 2.3 or later in the 2.x series before installing Tasks 3.0. Install the new Activity and Tasks releases together, then run the Tasks migrations and restart workers. Configure a queue worker and the scheduler for durable Activity delivery.

Run the new task management migrations before deploying code that reads classifications or child records. The migrations add type, category, importance, target time, and estimate columns, plus checklist, tag, time-entry, hierarchy, and dependency tables. Preserve the package's migration ownership choice, bind `TaskAuthorization`, and run `nvl:tasks:doctor --strict`.

Content and Metafields are no longer Tasks dependencies or model integrations. Existing records in those packages are not deleted by Tasks migrations. If an application stored task details there, move them into task descriptions, metadata, checklists, tags, or application-owned storage before removing access to the old integrations.

The management API is disabled by default. If enabled, secure its middleware and bind `TaskPrincipalResolver` for assignee lookup. Existing applications may keep HTTP routes in the host and call Tasks actions directly.

For optional tenant adoption, classify existing task roots with reviewed ownership mappings. Adopt Media and Activity first; then backfill Tasks and its inherited assignments, checklists, tags, time entries, hierarchy links, and blocker links through Tenancy's prepare, backfill, verify, and activate phases. Restart workers after a completed adoption. Never copy client-supplied tenant IDs into task records.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Optional collaboration dependencies

Activity and Media move from runtime requirements to suggestions. Install and load the integrations your application uses; nullable `nvl-tasks.activity.enabled` and `nvl-tasks.media.enabled` preserve automatic activation in the loaded suite. Use `false` to disable or `true` to require each integration. Replace Task model Media trait calls with the Tasks-owned `TaskAttachments` contract. Existing pending Activity outbox rows are retained unchanged while delivery is inactive and must be delivered after re-enabling Activity.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=tasks --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=tasks --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

`TaskData::fromModel` and `TaskDetailData::fromModel` are internal projectors. Use `GetTaskDetailAction` with the authorized TaskActorData for the bounded public detail DTO; package Task handles do not permit checklist/relation traversal.
