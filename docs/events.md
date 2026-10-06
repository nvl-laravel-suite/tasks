# NVL tasks events

This document describes the implemented source behavior. Executable acceptance proof is pending the final testing phase. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Scalar actor copied into TaskEventActorData, task revision, operation and safe context; no TaskActorData principal model, description, checklist title, tag text or private field values. Private captured TenantJobEnvelope is available through tenantJobEnvelope(). Assignment IDs, relation IDs and existing durations are operation context.

No-op updates, technical timestamp/revision-only changes, duplicate assignment, replayed mutations and rejected revisions emit nothing. TaskEvents runs at TasksActivity semantic boundaries before optional Activity publication. Activity installation and outbox retries do not create or replay TaskChanged.

Task context: assigned/unassigned use assignee_type and assignee_id; checklist item operations use item_id; time_entry_added/time_entry_updated/timer_stopped use entry_id and duration_seconds; removed/time-start use entry_id; parent operations use parent_task_id; blocker operations use blocker_task_id. Other operations use an empty context, including updated and tag changes.

TaskActorData may privately retain a model and is deliberately not embedded. TaskEventActorData uses the native int|string|null identifier. TenantJobEnvelope is captured inside the semantic writer boundary.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [TaskChanged](#taskchanged) | 1 | Task semantic operation persisted. |

### TaskChanged

`Nvl\Tasks\Events\TaskChanged` · [source](../src/Events/TaskChanged.php) · event schema `1`.

Task semantic operation persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$taskId` | `string` | public | `required` | — |
| `$revision` | `int` | public | `required` | — |
| `$operation` | `Nvl\Tasks\Enums\TaskChangeOperation` | public | `required` | — |
| `$actor` | `Nvl\Tasks\Data\TaskEventActorData` | public | `required` | — |
| `$context` | `array` | public | `[]` | `array<string, bool\|float\|int\|string\|null>` |
| `$envelope` | `?Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope` | private | `null` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$taskId` | `string` | — |
| `$revision` | `int` | — |
| `$operation` | `Nvl\Tasks\Enums\TaskChangeOperation` | — |
| `$actor` | `Nvl\Tasks\Data\TaskEventActorData` | — |
| `$context` | `array` | `array<string, bool\|float\|int\|string\|null>` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/TaskEvents.php](../src/Services/TaskEvents.php) | `$task->getConnection()` |

Publisher callers (the publisher above supplies the exact model connection):

| Caller | Boundary |
| --- | --- |
| [Services/TasksActivity.php](../src/Services/TasksActivity.php) | `TaskEvents::record` |

Operation-specific producer context:

| Operation | Context shape |
| --- | --- |
| `created` | `array{}` |
| `updated` | `array{}` |
| `deleted` | `array{}` |
| `restored` | `array{}` |
| `checklist_reordered` | `array{}` |
| `tag_added` | `array{}` |
| `tag_removed` | `array{}` |
| `assigned` | `array{assignee_type:?string, assignee_id:int\|string\|null}` |
| `unassigned` | `array{assignee_type:?string, assignee_id:int\|string\|null}` |
| `checklist_item_added` | `array{item_id:string}` |
| `checklist_item_updated` | `array{item_id:string}` |
| `checklist_item_completed` | `array{item_id:string}` |
| `checklist_item_reopened` | `array{item_id:string}` |
| `checklist_item_removed` | `array{item_id:string}` |
| `time_entry_added` | `array{entry_id:string, duration_seconds:int}` |
| `time_entry_updated` | `array{entry_id:string, duration_seconds:int}` |
| `timer_stopped` | `array{entry_id:string, duration_seconds:int}` |
| `time_entry_removed` | `array{entry_id:string}` |
| `timer_started` | `array{entry_id:string}` |
| `parent_linked` | `array{parent_task_id:string}` |
| `parent_unlinked` | `array{parent_task_id:string}` |
| `blocker_added` | `array{blocker_task_id:string}` |
| `blocker_removed` | `array{blocker_task_id:string}` |

## Referenced payload types

Native event field types are listed above; nested declared fields and backed enum values follow. Private captured envelopes are included because serialized/queued objects retain them. Dates use `Carbon\CarbonImmutable`. Spatie Data serialization can also carry its protected transformation metadata; recursive graph acceptance checks remain pending.

### TenantContextMode

`Nvl\Support\Tenancy\Enums\TenantContextMode` · [source](../../core/support/src/Tenancy/Enums/TenantContextMode.php).

Backed string values: `Disabled = disabled`, `Unresolved = unresolved`, `Tenant = tenant`, `Platform = platform`.

### TenantContextSnapshot

`Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot` · [source](../../core/support/src/Tenancy/ValueObjects/TenantContextSnapshot.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$mode` | `Nvl\Support\Tenancy\Enums\TenantContextMode` | — |
| `$tenantId` | `?Nvl\Support\Tenancy\ValueObjects\TenantId` | — |

### TenantId

`Nvl\Support\Tenancy\ValueObjects\TenantId` · [source](../../core/support/src/Tenancy/ValueObjects/TenantId.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$value` | `string` | — |

### TenantJobEnvelope

`Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope` · [source](../../core/support/src/Tenancy/ValueObjects/TenantJobEnvelope.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$context` | `Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot` | — |
| `$version` | `int` | — |

### TaskEventActorData

`Nvl\Tasks\Data\TaskEventActorData` · [source](../src/Data/TaskEventActorData.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$type` | `?string` | — |
| `$id` | `int\|string\|null` | — |
| `$system` | `bool` | — |

### TaskChangeOperation

`Nvl\Tasks\Enums\TaskChangeOperation` · [source](../src/Enums/TaskChangeOperation.php).

Backed string values: `Created = created`, `Updated = updated`, `Deleted = deleted`, `Restored = restored`, `Assigned = assigned`, `Unassigned = unassigned`, `ChecklistItemAdded = checklist_item_added`, `ChecklistItemUpdated = checklist_item_updated`, `ChecklistItemCompleted = checklist_item_completed`, `ChecklistItemReopened = checklist_item_reopened`, `ChecklistReordered = checklist_reordered`, `ChecklistItemRemoved = checklist_item_removed`, `TagAdded = tag_added`, `TagRemoved = tag_removed`, `TimeEntryAdded = time_entry_added`, `TimeEntryUpdated = time_entry_updated`, `TimeEntryRemoved = time_entry_removed`, `TimerStarted = timer_started`, `TimerStopped = timer_stopped`, `ParentLinked = parent_linked`, `ParentUnlinked = parent_unlinked`, `BlockerAdded = blocker_added`, `BlockerRemoved = blocker_removed`.

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.
