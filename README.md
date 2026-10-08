# NVL Tasks — API and usage

## Quickstart

```sh
composer require nvl/tasks:^5.0
php artisan nvl:install tasks --dry-run
php artisan nvl:install tasks
```

Required NVL dependencies: `nvl/core` (`^5.0`). Bind TaskAuthorization for user operations and TaskPrincipalResolver when enabling management HTTP assignments. Supply validated TaskActorData; trusted system actors are a separate explicit capability.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Tasks\Contracts\ListTasksContract` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Tasks\Contracts\ListTasksContract;

/** @var ListTasksContract $capability */
$result = $capability->execute($actor);
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/tasks/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/tasks/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/tasks:^5.0` |
| Module identifier | `nvl/tasks` |
| PHP namespace | `Nvl\Tasks` |
| Service provider | `Nvl\Tasks\Providers\TasksServiceProvider` |
| Configuration | `config/nvl-tasks.php` |

## Purpose and boundaries

Tasks owns task identity, lifecycle, classifications, assignees, checklists, tags, time entries, running timers, parent/subtask and blocker links, dashboard aggregates, audit activity, authorization entry points, and a bounded management API. A task has a title, description, status, priority, type, category, importance, optional target and firm due timestamps, optional estimate, small JSON metadata, a revision, and soft deletion. Assignees and creators are polymorphic persisted Eloquent principals, so the package does not require `nvl/auth` or a particular host `User` model. Register stable morph aliases for host principal models before storing tasks so later class renames do not strand references.

Collaboration integrations are Activity for task audit events and Media for private files in the `attachments` slot. All task management facts live in package-owned tables. Apps may use the public actions without enabling HTTP routes.

When the Activity adapter is active, task mutations stage immutable Activity events in `nvl_task_activity_outbox` within the same database transaction. When Activity shares the Tasks connection, the Activity row is written atomically before commit. With a separate connection, a queued job delivers after the outer commit; `nvl:tasks:activity:drain` runs every minute by default to recover missed dispatches and retry failed writes with backoff. Replays use the outbox UUID as the Activity UUID, so a write that succeeded before its acknowledgement does not create a duplicate. Run the effective Tasks queue worker and the Laravel scheduler. `nvl-tasks.queue.connection` and `nvl-tasks.queue.name` default to `null`: package overrides take priority, then Core queue defaults, then the selected Laravel connection and queue. Explicit `sync` is preserved; the old `nvl-tasks.activity.queue` override is deprecated for removal in major 6. In tenant mode, configure Activity's reviewed active-tenant worklist for the scheduled sweep; `nvl:tasks:doctor --strict` flags an empty worklist. Keep pending outbox rows until delivery succeeds; inspect `attempts` and `last_error` if delivery stalls. During later tenancy adoption, outbox rows whose tasks were hard deleted cannot be assigned a tenant automatically. Resolve their audit delivery and ownership before activating tenancy; adoption fails closed until those orphan rows are reconciled.

When Activity integration is active, Tasks registers an every-minute outbox correctness schedule with `onOneServer()` and overlap protection; `nvl-tasks.activity.schedule.enabled` defaults to `true`. Run Laravel's scheduler every minute and use a shared cache with atomic locks. Doctor requires a heartbeat from actual scheduled execution within three minutes, or a host implementation of `Nvl\Tasks\Contracts\TaskSchedulerReadiness`; merely registering an event does not prove cron is running. Disabling this schedule requires the host to arrange equivalent outbox recovery.

## Requirements and installation

Use PHP 8.4+ and Laravel 12–13. Install Tasks independently from Packagist. Publish configuration if you need different storage, limits, or an opt-in API:

```bash
composer require nvl/tasks:^5.0
php artisan vendor:publish --tag=nvl-tasks-translations
php artisan vendor:publish --tag=nvl-tasks-config
php artisan vendor:publish --tag=nvl-tasks-skills
php artisan migrate
php artisan nvl:tasks:doctor --strict
```

Choose exactly one migration owner. For automatic vendor loading, leave `nvl-tasks.migrations.enabled=true` and do not publish `nvl-tasks-migrations`. For host-owned migrations, run `php artisan vendor:publish --tag=nvl-tasks-migrations`, set `nvl-tasks.migrations.enabled=false` before the first migration, and maintain the copied migrations as application migrations. Never run both sources; publishing retimestamps migrations.

## Application use

Bind `TaskAuthorization` in the host application. The default rejects user operations. Trusted background work may use `TaskActorData::system()`; never construct that actor from an HTTP request. Ordinary callers use a persisted Eloquent authenticatable:

```php
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;

$task = app(CreateTaskAction::class)->execute(
    new CreateTaskData(title: 'Review the campaign'),
    TaskActorData::fromAuthenticatable($request->user()),
);
```

`UpdateTaskAction` replaces editable fields and requires the current `expectedRevision`; stale writes throw `TaskRevisionConflict`. `DeleteTaskAction` soft-deletes and `RestoreTaskAction` restores a deleted task at an exact revision while retaining Media ownership. `AssignTaskAction` and `UnassignTaskAction` accept any persisted Eloquent `Authenticatable` and re-read the task inside the current tenant boundary. Checklist, tag, time-entry, timer, hierarchy, and dependency actions also enforce authorization, tenant ownership, and exact revisions. `GetTaskAction`, `GetTaskDetailAction`, `ListTasksAction`, `ListTaskChecklistItemsAction`, `ListTaskTimeEntriesAction`, and `GetTaskDashboardAction` are authorized, tenant-scoped reads. Listing supports status, priority, type, category, importance, tag, assignee, target/due date windows, and overdue filters. `TaskDetailData` projects a task with bounded related records; the checklist and time-entry list actions page the full history.

The built-in `TaskStatus`, `TaskPriority`, `TaskType`, `TaskCategory`, and `TaskImportance` string enums are model casts. Replace any of their classes through `nvl-tasks.enums`, and set the matching persisted value in `nvl-tasks.defaults`. Set `nvl-tasks.lifecycle.completed_status` to the configured status that completes a task. Date fields are distinct: `target_at` is a planning target; `due_at` is the firm deadline used by overdue queries and dashboard counts. Dashboard status mappings may be configured under `nvl-tasks.dashboard.statuses` when using custom workflow statuses.

The `List` authorization ability grants visibility to the tenant's task catalog. Apps with narrower per-user visibility should also implement `TaskQueryScope` on their `TaskAuthorization` binding; its constraints are grouped inside the tenant boundary before filtering and pagination. A host-owned HTTP endpoint may call the same list action.

The `metadata` JSON object is for small app-owned hints (at most 64 keys and 64 KiB). Use package checklists for actionable steps, tags for labels, time entries for effort, and parent/blocker links for task relationships. Upload and attach files through Media's actions using the `attachments` slot; it is private, exclusive, limited to 10 files of 20 MiB each, and accepts image/document MIME types by default. The limits are configurable under `nvl-tasks.media`.

## Optional management API

`nvl-tasks.routes.management.enabled` is false by default. An app may enable the `nvl/api/v1/tasks` route group after binding its authorization policy and securing the configured middleware. The group provides task, assignment, checklist, tag, time tracking, relationship, dependency, dashboard, and detail operations. `GET /{task}/checklist-items` and `GET /{task}/time-entries` accept `page` and `perPage` to read beyond the bounded detail view. Its `data`/`meta` responses contain bounded Data projections, not raw Eloquent models or HTTP Resources. HTTP assignee lookup also requires a host binding for `TaskPrincipalResolver`; the default rejects assignment requests. Apps can instead keep routes host-owned and wrap the same Data projections in their API response.

The default middleware is `api`, `auth`, and `throttle:60,1`. The host must ensure the chosen authentication middleware authenticates a persisted Eloquent principal and must authorize every task ability, including assignment targets. Missing authorization or principal-resolution bindings fail closed. A stale HTTP replacement returns 409; invalid input returns 422.

## Optional tenancy

Standalone mode works with Tenancy disabled. For adopted tenant mode, configure `media`, `activity`, and `tasks` as tenant resources; adopt dependencies before Tasks. Task roots receive the active tenant ID, and assignments, checklists, tags, time entries, hierarchy links, and dependency links inherit it from their task. Task actions always re-read the canonical task through `TenantBoundary`, including when the caller passes a loaded model. Review ownership mappings and activate only after the Tenancy verification phase succeeds; do not infer a tenant from request payloads or assignee IDs.

## Development and verification

From a standalone checkout of the public Tasks repository, run the package's Pint, PHPStan, and Pest gate:

```bash
composer install
composer quality
```

Local Dagger verification also validates the package family. In a consuming Laravel application, the read-only `nvl:tasks:doctor --strict --format=json` command checks schema, owner registration, route registration, and consumer bindings. For database-backed production deployments, verify task migrations and the tenant adoption plan against the target database before enabling traffic.

## Injectable workflow contracts

Constructor-inject focused interfaces from `Nvl\Tasks\Contracts` when composing host workflows. Each interface retains the native Action’s complete `execute` parameters, defaults, return type, and documented generic/shape result. Concrete Actions remain directly usable in major 5.

```php
use Nvl\Tasks\Contracts\CreateTaskContract;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

final readonly class CreateTaskWorkflow
{
    public function __construct(private CreateTaskContract $workflow) {}

    public function execute(CreateTaskData $data, TaskActorData $actor): Task
    {
        return $this->workflow->execute($data, $actor);
    }
}
```

The provider installs conditional transient defaults (`bindIf`) for the following selected workflows. A host interface binding registered before package discovery is retained; a later binding/instance replacement is used by newly resolved host services. Keep authorization, validation, query ownership, and mutation behavior inside the owning package workflow.

| Contract | Native implementation |
| --- | --- |
| `AddTaskChecklistItemContract` | `AddTaskChecklistItemAction` |
| `AddTaskDependencyContract` | `AddTaskDependencyAction` |
| `AddTaskTagContract` | `AddTaskTagAction` |
| `AddTaskTimeEntryContract` | `AddTaskTimeEntryAction` |
| `AssignTaskContract` | `AssignTaskAction` |
| `CreateTaskContract` | `CreateTaskAction` |
| `DeleteTaskContract` | `DeleteTaskAction` |
| `DeleteTaskTimeEntryContract` | `DeleteTaskTimeEntryAction` |
| `GetTaskContract` | `GetTaskAction` |
| `GetTaskDashboardContract` | `GetTaskDashboardAction` |
| `GetTaskDetailContract` | `GetTaskDetailAction` |
| `LinkTaskParentContract` | `LinkTaskParentAction` |
| `ListTaskChecklistItemsContract` | `ListTaskChecklistItemsAction` |
| `ListTaskTimeEntriesContract` | `ListTaskTimeEntriesAction` |
| `ListTasksContract` | `ListTasksAction` |
| `RemoveTaskChecklistItemContract` | `RemoveTaskChecklistItemAction` |
| `RemoveTaskDependencyContract` | `RemoveTaskDependencyAction` |
| `RemoveTaskTagContract` | `RemoveTaskTagAction` |
| `ReorderTaskChecklistItemsContract` | `ReorderTaskChecklistItemsAction` |
| `RestoreTaskContract` | `RestoreTaskAction` |
| `StartTaskTimerContract` | `StartTaskTimerAction` |
| `StopTaskTimerContract` | `StopTaskTimerAction` |
| `ToggleTaskChecklistItemContract` | `ToggleTaskChecklistItemAction` |
| `UnassignTaskContract` | `UnassignTaskAction` |
| `UnlinkTaskParentContract` | `UnlinkTaskParentAction` |
| `UpdateTaskContract` | `UpdateTaskAction` |
| `UpdateTaskChecklistItemContract` | `UpdateTaskChecklistItemAction` |
| `UpdateTaskTimeEntryContract` | `UpdateTaskTimeEntryAction` |

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.

## Optional Activity and Media adapters

Tasks installs with Core only. Install `nvl/activity` or `nvl/media` and load its provider to activate that integration. `nvl-tasks.activity.enabled` and `nvl-tasks.media.enabled` accept `null` (automatic activation from loaded providers), `false` (disabled), or `true` (required). Explicitly requiring an unavailable adapter produces a configuration error; Core Doctor reports inactive automatic integrations as information.

With Activity inactive, ordinary task mutations remain available and create no new Activity outbox events. Existing pending outbox rows remain unchanged, including payloads, attempts, and leases. Delivery and draining return without consuming them. Re-enable the Activity provider before delivering those rows; do not delete them as part of removing the integration.

Use the Tasks-owned `Nvl\Tasks\Contracts\TaskAttachments` boundary for attachment operations: `attach($task, $mediaId, $actor)`, `detach($task, $mediaId, $actor)`, and `ids($task, $actor)`. Its Media adapter retains private file validation, exclusive ownership, bounded retention, tenant checks, and both packages' authorization policies. Requesting attachments while Media is inactive throws a clear exception. The Task model no longer composes foreign Media traits or implements `HasMedia`.

Host adapters can bind `TaskActivityPublisher`, `TaskActivityWorklist`, or `TaskAttachments` before package defaults are registered.

## Next major: isolated schema identities

Use `nvl-tasks.tables.<logical-key>` for every table and `nvl-tasks.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `tasks` | `nvl_tasks_tasks` | `nvl_tasks` |
| `assignments` | `nvl_tasks_assignments` | `nvl_task_assignments` |
| `checklist_items` | `nvl_tasks_checklist_items` | `nvl_task_checklist_items` |
| `time_entries` | `nvl_tasks_time_entries` | `nvl_task_time_entries` |
| `relationships` | `nvl_tasks_relationships` | `nvl_task_relationships` |
| `dependencies` | `nvl_tasks_dependencies` | `nvl_task_dependencies` |
| `tags` | `nvl_tasks_tags` | `nvl_task_tags` |
| `activity_outbox` | `nvl_tasks_activity_outbox` | `nvl_task_activity_outbox` |

Migration filenames contain `nvl_tasks_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

## Canonical configuration ownership

Use `nvl-tasks` settings in `config/nvl-tasks.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Tasks\Contracts\ListTasksContract` in Laravel's native container for a host-workflow test:

```php
use Nvl\Tasks\Contracts\ListTasksContract;

$double = Mockery::mock(ListTasksContract::class);
$this->app->instance(ListTasksContract::class, $double);
// Configure the exact execute arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

A detached fixture for a returned identity/data handle is:

```php
use Nvl\Tasks\Models\Task;
$fixture = Task::factory()->withoutParents()->make();
```

Ordinary `make()` may persist declared package parents. `withoutParents()->make()` disables parent expansion/admission for detached fixtures; use explicit persisted parents/owners and matching effective connections for a real `create()`. Factories do not authorize workflows, call Stripe, create backing Media objects or publish Template artifacts. Enabled tenancy requires explicit admitted persisted tenants/parents. Your host test installation supplies Faker; no test runner is a runtime package dependency.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. The published 5.x family is verified through the local Dagger release gate on PHP 8.4/Laravel 13, including owning suites, MySQL/PostgreSQL persistence contracts and sealed Tenancy consumers. Fresh public Composer installation, discovery and configuration/route caching are verified. PHP 8.5, Laravel 12, MariaDB and the full independent archive matrix require separate evidence. See the [verification and release policy](https://github.com/nvl-laravel-suite/laravel-suite#verification-and-releases).

### Shipped factory states

These runtime builders keep Laravel's native Factory API. The listed methods name explicit supported parent/owner/lifecycle states; follow each factory's native admission requirements. Detached examples above do not assert persistence validity.

| Factory | Explicit states |
| --- | --- |
| [`TaskAssignmentFactory`](database/factories/TaskAssignmentFactory.php) | `forTask(Task $parent)`, `forOwner(Model $owner)` |
| [`TaskChecklistItemFactory`](database/factories/TaskChecklistItemFactory.php) | `forTask(Task $parent)` |
| [`TaskDependencyFactory`](database/factories/TaskDependencyFactory.php) | `forTask(Task $parent)`, `forBlocker(Task $parent)` |
| [`TaskFactory`](database/factories/TaskFactory.php) | `forOwner(Model $owner)` |
| [`TaskRelationshipFactory`](database/factories/TaskRelationshipFactory.php) | `forChild(Task $parent)`, `forParent(Task $parent)` |
| [`TaskTagFactory`](database/factories/TaskTagFactory.php) | `forTask(Task $parent)` |
| [`TaskTimeEntryFactory`](database/factories/TaskTimeEntryFactory.php) | `forTask(Task $parent)`, `forOwner(Model $owner)` |

## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `binding_required` | 500 | {} | `nvl-tasks::responsecode.binding_required` |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-tasks::responsecode.operation_failed` |
| `task_revision_conflict` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-tasks::responsecode.task_revision_conflict` |

### Operational logging

`nvl-core.logging` defaults to channel `nvl`, normal verbosity and a CSV quiet override. Configure package `channel`/`verbosity` overrides under `packages`; verbosity is `quiet`, `normal` or `verbose`. Warnings/errors survive every setting. The absent `nvl` channel becomes a stack of the host default; a configured host channel wins. Do not configure a self-referential stack. Doctor diagnoses missing/cyclic channels without logging to them. Stable `nvl.<package>.<operation>.<result>` keys carry bounded diagnostics, never retained tenant/job context. CSV logs one failed-row warning summary per chunk; row details require verbose mode and contain no raw row values.



## Required bindings

The shipped placeholders fail closed with Core `binding_required`/500 before capability work. These are configuration failures; a configured adapter must preserve native authorization/not-found failures for actual user denial. Register your implementations in the host AppServiceProvider::register(), using these exact contracts. The `App` classes below are host adapters you implement, not package-provided defaults.

```php
use Nvl\Tasks\Contracts\TaskAuthorization;
use App\Tasks\HostTaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use App\Tasks\HostTaskPrincipals;

public function register(): void
{
    $this->app->bind(TaskAuthorization::class, HostTaskAuthorization::class);
    $this->app->bind(TaskPrincipalResolver::class, HostTaskPrincipals::class);
}
```

| Host adapter contract | Required native signature |
| --- | --- |
| `TaskAuthorization` | `authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void` |
| `TaskPrincipalResolver` | `resolve(string $identifier): Model&Authenticatable` |

`Authenticatable` is Laravel’s contract and `Model` is Eloquent’s base. Use trusted persisted host identity; never return an arbitrary request-provided principal or infer ownership from a matching amount. DTOs/enums come from this package; `TenantId` comes from neutral Core Tenancy.

TaskAuthorization is required for user mutation; its trusted system branch remains available. TaskPrincipalResolver is required when `nvl-tasks.routes.management.enabled=true` and returns a persisted model implementing Authenticatable that the current caller may access. A null enabled flag is an on-use requirement, not a Doctor error before choosing the capability.

Run `php artisan nvl:doctor --strict --format=json` after selecting the capability. RequiredBindings metadata inspection never executes your adapter factory or proves that a configured adapter authorizes correctly; retain host adapter integration tests.

## License

MIT. See [LICENSE](LICENSE). Security reports should follow [SECURITY.md](SECURITY.md); upgrading notes are in [UPGRADING.md](UPGRADING.md).
