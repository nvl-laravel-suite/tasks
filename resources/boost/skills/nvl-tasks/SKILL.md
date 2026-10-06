---
name: nvl-tasks
description: Use when implementing or reviewing nvl/tasks lifecycle, classification enums, checklists, tags, time tracking, task relationships, Activity audit, Media attachments, tenant isolation, or management routes.
---

# NVL Tasks

Use this skill for task-management work in a Laravel application consuming `nvl/tasks`.

## Ownership and policy

- Use package actions for task, checklist, tag, time-entry, timer, hierarchy, dependency, and assignment writes. They re-read task identity through the tenant boundary; never let request data set tenant or creator columns.
- Bind `TaskAuthorization` for every user-facing ability. The default rejects user work. Use `TaskActorData::fromAuthenticatable()` with a persisted Eloquent principal; reserve `TaskActorData::system()` for trusted jobs or seeders.
- `UpdateTaskAction` requires the current `expectedRevision`. Surface `TaskRevisionConflict` as a conflict, not an unguarded retry.
- Keep assignees in task assignment records. Register stable morph aliases for host principal models; do not couple Tasks to a specific Auth package model or write polymorphic type names from HTTP input.
- Configure string-backed enum classes through `nvl-tasks.enums` and matching values through `nvl-tasks.defaults`. Set `nvl-tasks.lifecycle.completed_status` to the configured completion status. Model casts, validation, and queries use those enums.

## Package integrations

- Plain description and small app hints belong on the task. Use package checklists for steps, tags for labels, time entries for effort, and task links for hierarchy or blockers. `target_at` is a planning target; `due_at` is the firm deadline.
- Activity records meaningful task lifecycle and assignment events. Use Activity's timeline reads for audit views.
- Task files use the private, exclusive Media `attachments` slot. Use Media's upload/attachment actions and delivery policy, not new file columns or public URLs.
- For tenant mode, adopt Media and Activity before Tasks. Task roots receive tenant identity from `TenantBoundary`; child records inherit it from their canonical task.

## Transport and verification

- Management routes are opt-in. Bind both `TaskAuthorization` and `TaskPrincipalResolver` before enabling HTTP assignment, and secure the host-selected middleware. Apps may keep their own routes and call the same actions.
- Use `ListTasksAction` for bounded, authorized pages and `GetTaskDashboardAction` for summary counts. Project authorized detail through `TaskDetailData`. Do not serialize task models or load unbounded relations for a list.
- Run focused Pest tests, PHPStan, and `nvl:tasks:doctor --strict` after changing schema, authorization, tenancy, or route configuration.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

## Optional Activity and Media adapters

Tasks installs with Core only. Install `nvl/activity` or `nvl/media` and load its provider to activate that integration. `nvl-tasks.activity.enabled` and `nvl-tasks.media.enabled` accept `null` (automatic activation from loaded providers), `false` (disabled), or `true` (required). Explicitly requiring an unavailable adapter produces a configuration error; Core Doctor reports inactive automatic integrations as information.

With Activity inactive, ordinary task mutations remain available and create no new Activity outbox events. Existing pending outbox rows remain unchanged, including payloads, attempts, and leases. Delivery and draining return without consuming them. Re-enable the Activity provider before delivering those rows; do not delete them as part of removing the integration.

Use the Tasks-owned `Nvl\Tasks\Contracts\TaskAttachments` boundary for attachment operations: `attach($task, $mediaId, $actor)`, `detach($task, $mediaId, $actor)`, and `ids($task, $actor)`. Its Media adapter retains private file validation, exclusive ownership, bounded retention, tenant checks, and both packages' authorization policies. Requesting attachments while Media is inactive throws a clear exception. The Task model no longer composes foreign Media traits or implements `HasMedia`.

Host adapters can bind `TaskActivityPublisher`, `TaskActivityWorklist`, or `TaskAttachments` before package defaults are registered.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-tasks.tables.*`, connections through `nvl-tasks.connection` with Core/Laravel inheritance. Defaults use `nvl_tasks_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=tasks --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Canonical configuration ownership

- Read/write `nvl-tasks` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.
