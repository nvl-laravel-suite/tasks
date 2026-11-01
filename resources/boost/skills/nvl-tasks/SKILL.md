---
name: nvl-tasks
description: Use when integrating task lifecycle, assignments, checklist, tags, time tracking, task graphs, authorized task queries or optional Activity and Media adapters with nvl/tasks.
---

# NVL Tasks

Tasks targets PHP 8.4 and Laravel 13 and requires Core.
The base package boots without Activity, Media, Ownership, Tenancy or DTO libraries.
Consumers own Actions, HTTP routes, request parsing, response projections and frontend types.

## Portable references

- [Installation](references/installation.md): provider, publish tags, migration ownership and schema prerequisites.
- [Native API](references/api.md): service signatures, contracts, model capabilities and typed arrays.
- [Usage](references/usage.md): full capability map, field/null rules, policy binding and verified examples.

Read usage when choosing services or building a consumer workflow.
Read installation before schema/adoption changes and API before supplying arguments.
Local references work in the published Laravel Boost skill tree.

## Choose the native capability

- `TaskLifecycle`: create, patch, soft-delete and restore tasks.
- `TaskAssignments`: add/remove canonical persisted assignees.
- `TaskChecklist`: add/edit/toggle/reorder/remove checklist entries.
- `TaskTags`: add/remove task tags.
- `TaskTimeTracking`: log/edit/delete intervals and start/stop timers.
- `TaskRelationships`: parent links and dependencies with cycle protection.
- `TaskReader`: authorized detail, pages, child pages and dashboard counts.
- `TaskAttachments`: narrow port for explicitly enabled native Media attachments.
- `TasksActivityRetention`: explicitly authorized, bounded delivered-outbox pruning.

## Supply actor visibility explicitly

Bind both `TaskAuthorization::authorize` and `scopeTasks`; defaults deny users and system actors.
Pass an explicit persisted Eloquent Authenticatable or deliberately authorized SystemActor.
Actors and assignees are reloaded from canonical storage.
`scopeTasks` must express complete row visibility as SQL.
Ownership predicates group the actor scope before filters, counts and pagination.
`TaskAbility::View` is still checked on individual task reads.
Raw Eloquent queries do not add the host's actor policy.

## Preserve native mutations

Use documented snake_case fields and separate integer revision arguments.
Omitted patch fields retain their values; explicit null clears only supported fields.
Unknown/protected IDs, ownership, actor, revision and computed completion fields reject.
Normalized no-op patches still authorize and check revisions while preserving timestamps/events.
Services own source transactions, locks, CAS, child attribution and refreshed returns.
Graph operations check both endpoints and the connection-wide graph mutex.
Keep the vertex/edge budgets (defaults 1000/5000, counting the proposed edge and
both endpoints), current locking edge reads and
mutex version write; overflow denies atomically. Retry the entire host transaction
after a PostgreSQL serialization failure or MariaDB enforced-snapshot conflict.
Install its additive migration before serving graph changes.
Timers enforce a human performer, interval validity and overlap exclusion.

## Enable integrations deliberately

Activity requires `activity.enabled=true` and registered emitted event policies.
Its native immutable envelopes use the Tasks source outbox for separate Activity storage.
Preserve outer commit, rollback, leases, replay conflict checks and scheduler recovery.
Delivered-outbox retention defaults off with no age. Grant `TaskAbility::PruneActivity` with a
null task for the entire admitted owner scope, configure a positive age, and call
`TasksActivityRetention::prune` outside host transactions (including previews).
It retains pending delivery and Activity evidence; no cleanup actor/schedule is inferred.
Media requires `media.enabled=true` and delegates to native Media services.
Preserve private slots, attachment/file limits, scan rules and foreign association exclusion.
Installing optional packages alone does not enable these profiles.

## Configure models and results

Select `models.task` before boot and retain native identity, casts, ownership and storage capabilities.
The host may configure classification enums/defaults and the completed status.
Reads return native task models/paginators or a bounded dashboard array.
Map `TaskRevisionConflict` and validation/authorization failures in the host.
Use `nvl:tasks:doctor --strict`; enabled Activity recovery uses `nvl:tasks:activity:drain`.
The usage reference includes a create/patch/checklist/page composition with exact revisions.
