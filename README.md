# NVL Tasks

## Purpose and boundaries

Tasks owns task lifecycle, assignments, checklists, tags, timers, time entries, parent links, dependencies and durable optional Activity delivery. It exposes seven native services and useful Eloquent read models. Applications own request validation, routes, controllers, resources, DTOs, response shapes and frontend types. The package boots without registering HTTP routes or requiring Data, TypeScript, Tenancy, Activity, Media or Ownership.

## Requirements and installation

PHP 8.4+, Laravel 13 and `nvl/core` are required. Internal family versions remain 6 during the coordinated Light conversion; the breaking release is coordinated separately.

```sh
composer require nvl/tasks:^7.0
php artisan vendor:publish --tag=nvl-tasks-config
php artisan migrate
php artisan nvl:tasks:doctor --strict
```

Choose one migration owner: automatic vendor loading with `nvl-tasks.migrations.enabled=true`, or published migrations maintained by the application with vendor loading disabled. Existing task data and legacy nullable ownership columns are retained; they are not authority. Apply the additive graph-lock migration before deploying these services.

## Application use

Inject `Nvl\Tasks\Services\TaskLifecycle`, `TaskAssignments`, `TaskChecklist`, `TaskTags`, `TaskTimeTracking`, `TaskRelationships` and `TaskReader` directly. Each public operation takes an explicit persisted Eloquent `Authenticatable` or `Nvl\Support\Enums\SystemActor`. A system enum conveys intent, not permission. Default authorization rejects both; no service infers authority from Auth, a Request or historical scalar attribution. Actors and assignment targets are reread from their canonical database identity, and adopted ownership is checked fresh.

```php
$tasks = app(\Nvl\Tasks\Services\TaskLifecycle::class);
$task = $tasks->create(['title' => 'Review campaign', 'metadata' => ['source' => 'editor']], $actor);
$task = $tasks->update($task, ['description' => null], $task->revision, $actor);
$page = app(\Nvl\Tasks\Services\TaskReader::class)->paginate(['status' => 'open'], $actor, 25, 1);
```

Bind `Nvl\Tasks\Contracts\TaskAuthorization`. Implement both `authorize(TaskAbility, Authenticatable|SystemActor, ?Task, ?Model): void` and `scopeTasks(Builder, Authenticatable|SystemActor): void`. The query method must express the actor's complete row visibility as SQL, for example `$query->where('creator_id', $actor->getAuthIdentifier())` with the matching morph type restriction. SystemActor handling must be deliberate. Scope predicates are grouped inside the canonical ownership boundary **before** filters, counts, aggregates and pagination. `View` authorization remains a second check. A no-op scope is appropriate only when that actor can see the complete active ownership catalog.

| Service | Native operations |
| --- | --- |
| TaskLifecycle | `create(array, actor): Task`, `update(task, array, revision, actor): Task`, `delete(task, actor): bool`, `restore(task, revision, actor): Task` |
| TaskAssignments | `assign(task, persistedAssignee, actor): TaskAssignment`, `unassign(task, persistedAssignee, actor): bool` |
| TaskChecklist | `add(task, title, revision, actor)`, `update(task, item, title, revision, actor)`, `toggle(task, item, completed, revision, actor)`, `reorder(task, listOfIds, revision, actor)`, `remove(task, item, revision, actor)` |
| TaskTags | `add(task, tag, revision, actor): TaskTag`, `remove(task, tag, revision, actor): bool` |
| TaskTimeTracking | `add(task, interval, revision, actor)`, `update(task, entry, interval, revision, actor)`, `delete(task, entry, revision, actor)`, `startTimer(task, revision, actor)`, `stopTimer(task, entry, revision, actor)` |
| TaskRelationships | `linkParent(child, parent, childRevision, parentRevision, actor)`, `unlinkParent(...)`, `addDependency(task, blocker, taskRevision, blockerRevision, actor)`, `removeDependency(...)` |
| TaskReader | `get(task, actor): Task`, `paginate(criteria, actor, perPage=25, page=1)`, `checklist(task, actor, perPage=null, page=1)`, `timeEntries(...)`, `dashboard(actor, dueSoonDays=null): array` |

Task references accept a model or string ID; child item references accept the corresponding model or string ID. Mutations reread canonical records and lock on the actual configured source connection. Revisions are separate integer parameters; stale mutations throw `TaskRevisionConflict`. No-op writes preserve revisions and emit no semantic event. Child changes attribute to the supplied explicit actor, enforce membership in the parent task and advance its revision. Parent/dependency operations check both endpoint revisions and prevent cycles; a connection-wide lock serializes graph writes, including edits with disjoint endpoints. This deliberately trades graph-write throughput for cycle safety.

An empty or normalized-equal lifecycle patch still checks the canonical actor, task authorization, expected revision and input validation. It returns the current task without saving, changing timestamps or publishing Activity/domain events. Completed no-op tasks retain their original completion instant.

Creation accepts `title` and optional `description`, `status`, `priority`, `type`, `category`, `importance`, `target_at`, `due_at`, `estimated_seconds`, `metadata`. Update accepts the same fields with every key optional. Omitted keys retain stored values. Explicit null clears nullable description/dates/estimate and resets classification enums to configured defaults. Title must be a nonblank string. Estimates are exact positive integers from 1 to 31,536,000; numeric strings and floats are rejected. Metadata is an object-shaped array (empty allowed), at most 64 keys and 64 KiB, with valid JSON values. Unknown keys and protected IDs, owner/actor attribution, revisions and computed completion fields are rejected, including in direct PHP calls.

Dates accept `DateTimeInterface`, parseable date strings or null where documented; invalid/impossible dates are rejected. Supplied offsets preserve the instant through UTC second-precision storage and immutable UTC model reads, independently of the host timezone. `target_at` may not follow `due_at`. Completion time is managed by lifecycle transitions. Time intervals require `started_at` and `ended_at`, plus optional nullable `description`; duration is computed, and start must precede end. A human performer is required to log time or start a timer. Timer overlap and performer ownership are enforced under the task lock.

Reader criteria are optional `status`, `priority`, `type`, `category`, `importance`, `assignee`, `tag`, `target_from`, `target_to`, `due_from`, `due_to`, `overdue`. Enum criteria accept configured backed enum cases or strings; assignee is a persisted authenticatable; date windows are strings; overdue is an exact boolean. Date-only windows use the host application calendar timezone, including DST, with local midnight edges converted to UTC for SQL. Due-soon advances calendar days in that timezone before conversion; overdue and outbox eligibility compare UTC instants. Pagination is bounded by configured limits. Dashboard returns integer counts under `open`, `completed`, `overdue`, `due_soon`, `in_progress`, `blocked`, `unassigned`, `total`, `due_soon_days`, `estimated_seconds`, `logged_seconds` (see the service's array shape for the authoritative keys).

## Model reads and consumer presentation

`Task` exposes classification enum casts, immutable dates, integer revision/estimate, array metadata, soft deletion, assignments, checklist items, tags, time entries, parent/child links and blocker/dependent links. `TaskReader::get` returns a task with bounded loaded relations and `logged_seconds`; dedicated checklist/time-entry pagination reads beyond detail limits. Link projections include only visible endpoints. Models and relations remain useful for host composition within canonical ownership; a raw Eloquent query does not apply an application's actor policy. Use TaskReader for user-facing lists and aggregates. Writes belong to the services.

Configure a Task subclass through `nvl-tasks.models.task`; retain required native capabilities, ownership, table/connection and casts. Enum classes/defaults and the completed status are configurable. Stable principal morph aliases should be registered before persisting attribution.

The active HTTP suites under `tests/Fixtures/Consumer` demonstrate application-owned parsing, authorization, 409 revision conflicts, 422 validation, bounded pagination and projections. They are examples, not runtime package controllers or a supported route prefix.

## Optional native integrations

Integrations require an explicit `nvl-tasks.activity.enabled=true` or `nvl-tasks.media.enabled=true`; package installation alone enables nothing.

Activity uses native `ActivityRecorder::recordEnvelope` with trusted immutable facts already authorized at the Tasks writer boundary. A same-connection delivery is transactional; a separate Activity connection receives a durable Tasks outbox job only after the actual source outer commit. UUID identity, leases, recovery/backoff and conflicting-fact checks preserve retry safety. Historical scalar actor IDs remain attribution after actor deletion and are not rehydrated as current authority. Rollback discards source writes and delivery. Run the effective queue worker and Laravel scheduler; the enabled Activity profile registers a guarded every-minute recovery drain. Doctor requires a fresh scheduler heartbeat or the explicit `TaskSchedulerReadiness` host contract.

With native Ownership enabled, adopt/register Tasks resources and required principals through Ownership's native ports. Every child derives owner attribution from the canonical task. Queued events and outbox delivery capture immutable ownership and reject foreign scope or changed facts. Pending orphan audit records require explicit verified ownership; do not guess authority from an old tenant column. The scheduled owned drain uses the native Ownership worklist/system runner.

Media is staged for Task12. Explicit enable against the old Media implementation fails with an actionable configuration error. The four retained enabled-profile tests must be activated by Task12 when its native `MediaAttachments` adapter is implemented. It must preserve private/exclusive/keepLatest slot behavior, limits/MIME/scan rules, actor and owner admission, foreign association exclusion and Media-owned writes. Base Tasks remains fully usable with Media physically absent. `TaskAttachments` is the narrow optional port; this migration does not guess future Media service signatures.

## Development and verification

```sh
php tools/run-package-tests.php tasks --concurrency=1
php tools/run-package-quality.php tasks --format=json
```

The package uses Testbench 11 and Pest. Native PostgreSQL/MySQL races use bounded forked workers and shared barriers through the existing database test runner. SQLite tests prove ordinary behavior, not process concurrency. Consumer API metadata is collected with the scoped developer collector while unconverted family leaves prevent whole-family regeneration. See `docs/events.md` for the scalar semantic event contract and `UPGRADING.md` for the breaking native migration.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
