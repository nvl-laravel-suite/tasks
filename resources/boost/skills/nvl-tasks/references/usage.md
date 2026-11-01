# Native task usage

Read [installation](installation.md) for publication/migrations and [API](api.md) for complete signatures and model/result shapes. Service namespace is `Nvl\Tasks\Services`.

## Host authorization and capability choice

Bind `Nvl\Tasks\Contracts\TaskAuthorization`. Implement `authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void` and `scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void`. Both default to denial. An explicit system enum is intent, not a permission. Persisted actors and assignees are canonicalized; changed storage/identity or stale caller attributes cannot confer authority.

The SQL scope defines complete visibility before filtering, totals, aggregates and pagination. Include both creator/assignee morph type and key when implementing identity predicates. Group alternatives inside the scope so they cannot escape ownership. A no-op scope is appropriate only for an actor authorized for the whole currently admitted catalog. The detail path additionally checks `View`.

| Capability | Operations / revision |
| --- | --- |
| `TaskLifecycle` | `create(attributes, actor)`; `update(task, changes, expectedRevision, actor)`; `delete(task, actor)`; `restore(task, expectedRevision, actor)` |
| `TaskAssignments` | `assign(task, persistedAssignee, actor)` / `unassign(...)`; assignee is `Model&Authenticatable` |
| `TaskChecklist` | `add`, `update`, `toggle`, `reorder`, `remove`; supply the parent task revision; reorder is a list of exact item IDs |
| `TaskTags` | `add(task, tag, revision, actor)` / `remove(...)` |
| `TaskTimeTracking` | `add`, `update`, `delete`, `startTimer`, `stopTimer`; parent revision and human performer required |
| `TaskRelationships` | `linkParent`, `unlinkParent`, `addDependency`, `removeDependency`; both endpoint revisions |
| `TaskReader` | `get`, `paginate`, `checklist`, `timeEntries`, `dashboard` |
| `TaskAttachments` | `attach(Task, mediaId, actor): string`, `detach(...): int`, `ids(Task, actor): list<string>` through enabled Media adapter |

Task/child references accept their native model or string ID where declared. Services reload canonical rows; passing a modified model is not a bypass. Child mutations check parent membership, attribute to the explicit actor and advance the parent revision. Stale versions raise `TaskRevisionConflict`.

## Native fields and omission

Creation requires nonblank `title`; optional fields are `description`, `status`, `priority`, `type`, `category`, `importance`, `target_at`, `due_at`, `estimated_seconds`, `metadata`. Update accepts exactly the same fields, all optional. IDs, revisions, ownership, actor attribution and completion timestamps are protected. Revisions are separate parameters, not array fields.

Omission preserves stored values. Null clears description/dates/estimate, and null classification enums reset configured defaults. Title cannot be null. Metadata is an object-shaped JSON array, empty allowed, at most 64 keys and 64 KiB. Estimates are exact integers from 1 to 31,536,000; numeric strings and floats reject. Enum values are configured backed cases or strings. A normalized-equal/empty patch still checks permission, canonical identity and revision; it preserves timestamps, completion instant and semantic events.

Task dates accept `DateTimeInterface`, parseable strings or permitted null. Invalid/impossible dates reject, `target_at` cannot follow `due_at`, offsets preserve instants in UTC at second precision. Completion is managed by lifecycle transitions. Time entry arrays require `started_at`, `ended_at`, optional nullable `description`; duration is computed and start precedes end. Timers/intervals enforce performer ownership and overlap under native locks.

## Verified create-to-read workflow

Preconditions: an admitted persisted `$actor` and host policy implementing both decisions. The example works with base classifications and no optional adapters:

```php
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;

$lifecycle = app(TaskLifecycle::class);
$task = $lifecycle->create([
    'title' => 'Review campaign',
    'description' => 'Confirm final copy',
    'metadata' => ['source' => 'editor'],
], $actor);

$task = $lifecycle->update($task, ['description' => null], $task->revision, $actor);
$item = app(TaskChecklist::class)->add($task, 'Approve copy', $task->revision, $actor);
$task = app(TaskReader::class)->get($task->id, $actor);
$item = app(TaskChecklist::class)->toggle($task, $item, true, $task->revision, $actor);
$page = app(TaskReader::class)->paginate(['status' => 'open'], $actor, 25, 1);
```

Reload after child mutations before supplying another expected parent revision. The returned checklist item does not replace the parent task's revision. For graph writes obtain both fresh endpoint revisions; do not compose raw parent/dependency table writes.

Graph traversal counts the proposed edge and both endpoints. Defaults are 1000
vertices and 5000 edges, configured under `limits.maximum_graph_vertices` and
`limits.maximum_graph_edges`; overflow denies before domain changes. Keep the
database mutex and locking reads. PostgreSQL or enforced MariaDB stale repeatable-read snapshots
escalate to the host's whole-transaction retry boundary.

## Bounded reads and time windows

Reader criteria are `status`, `priority`, `type`, `category`, `importance`, `assignee`, `tag`, `target_from`, `target_to`, `due_from`, `due_to`, `overdue`. Assignee is a persisted authenticatable, windows are strings, and overdue is an exact boolean. Supply `perPage` and `page` as scalar arguments, rather than invented filter aliases. Date-only windows use the application calendar timezone/DST and convert local midnight edges to UTC; due-soon advances local calendar days. Overdue/outbox comparisons use UTC instants.

`get` includes bounded relations and `logged_seconds`; use `checklist` / `timeEntries` pagination beyond configured detail limits. Link projections contain only actor-visible endpoints. `dashboard` returns counts and time aggregates documented in the API, including configured due-soon days. Raw model queries retain ownership but do not apply the host actor policy. Project native results in host resources.

## Transactions, model selection and optional integrations

Services own actual source transactions, locks and revisions. A same-source host transaction can compose native operations. Domain events follow outer commit; rollback discards them. Graph mutations acquire the connection-wide mutex before endpoint locks, preventing cycles even for disjoint concurrent edits. Apply its additive migration first; sequential calls cannot prove race safety.

Set `nvl-tasks.models.task` to a concrete `Task` subclass before boot. Preserve UUID identity, required casts/hooks, ownership, native table and actual connection. Child models stay native. Configure enum classes/defaults and `lifecycle.completed_status` consistently; register stable principal morph identities before attribution is written. Locale/copy and translations of classifications belong to the host; stored task timestamps remain UTC.

Activity is enabled only by `nvl-tasks.activity.enabled=true`. Register every emitted `TaskChangeOperation` event in the Activity policy catalog with admitted change/context fields. Same-source delivery is transactional; separate Activity storage receives trusted immutable envelopes via the durable Tasks source outbox after commit. Preserve UUID facts, historical scalar attribution, leases, backoff, conflict detection and recovery. Run the queue worker and scheduler; the enabled profile registers every-minute guarded drain. Doctor requires scheduler heartbeat or `TaskSchedulerReadiness`. Use `nvl:tasks:activity:drain --limit=100` within reviewed owner worklists.

`TasksActivityRetention::prune($actor, $dryRun)` is separately opt-in: enable
`activity.retention.enabled`, choose positive `days`, and explicitly authorize
`TaskAbility::PruneActivity` with a null task for the entire admitted owner scope.
Default age is null; no CLI/schedule/system bypass is inferred. Run outside host
transactions, including previews. Bounded keyset batches use a fixed UTC cutoff
and repeat the delivered predicate at deletion. Results are matched/deleted/
has_more; dry runs delete nothing, and completed batches can precede later denial.
Pending facts and Activity audit evidence are retained, including deleted-task
history. Apply the forward delivery-ready index on existing storage.

Media is enabled only by `nvl-tasks.media.enabled=true`. `MediaTaskAttachments` implements `TaskAttachments` by calling native `MediaAttachments`/`MediaReader`; it enforces private/exclusive/keepLatest slots, configured file/count limits, scan admission, actor/owner policies and foreign-association exclusion. Do not write Media tables or restore removed Action/DTO adapters. Base Tasks boots with these packages physically absent.

`nvl:tasks:doctor --strict --format=json` checks readiness. The host maps revision conflict, validation and authorization errors, chooses HTTP statuses and owns request parsing; the package supplies no routes/controllers/DTO layer.
