# NVL Tasks — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/tasks/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/tasks/security/advisories/new). See [Contributing](CONTRIBUTING.md).

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/tasks:^2.0` |
| Module identifier | `nvl/tasks` |
| PHP namespace | `Nvl\Tasks` |
| Service provider | `Nvl\Tasks\Providers\TasksServiceProvider` |
| Configuration | `config/tasks.php` |

## Purpose and boundaries

Tasks owns task identity, lifecycle, assignee records, authorization entry points, and a bounded management table. Each task has a title, plain description, enum-backed status and priority, optional due and completion timestamps, small JSON metadata, a revision, and soft deletion. Assignees and creators are polymorphic persisted Eloquent principals, so the package does not require `nvl/auth` or a particular host `User` model. Register stable morph aliases for host principal models before storing tasks so later class renames do not strand creator or assignee references.

Other packages keep their own responsibilities. Task detail blocks use the `details` group in `nvl/content`; typed custom fields use the registered `task` owner in `nvl/metafields`; private files use the `attachments` Media slot. The Tasks package does not duplicate those packages' write or authorization APIs. Its own tables are `nvl_tasks` and `nvl_task_assignments` by default.

## Requirements and installation

Use PHP 8.4+ and Laravel 13. Install Tasks independently from Packagist. Publish configuration if you need different storage, limits, or an opt-in API:

```bash
composer require nvl/tasks:^2.0
php artisan vendor:publish --tag=tasks-config
php artisan vendor:publish --tag=tasks-skills
php artisan migrate
php artisan nvl:tasks:doctor --strict
```

Choose exactly one migration owner. For automatic vendor loading, leave `tasks.migrations.enabled=true` and do not publish `tasks-migrations`. For host-owned migrations, run `php artisan vendor:publish --tag=tasks-migrations`, set `tasks.migrations.enabled=false` before the first migration, and maintain the copied migrations as application migrations. Never run both sources; publishing retimestamps migrations.

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

`UpdateTaskAction` replaces editable fields and requires the current `expectedRevision`; stale writes throw `TaskRevisionConflict`. `DeleteTaskAction` soft-deletes and `RestoreTaskAction` restores a deleted task at an exact revision while retaining Content and Media ownership. `AssignTaskAction` and `UnassignTaskAction` accept any persisted Eloquent `Authenticatable` and re-read the task inside the current tenant boundary. `GetTaskAction` and `ListTasksAction` are authorized, tenant-scoped reads; listing is paginated and can filter by status, priority, or one assignee. Task enum values are cast by the model and included in generated TypeScript declarations.

The `List` authorization ability grants visibility to the tenant's task catalog. Apps with narrower per-user visibility should also implement `TaskQueryScope` on their `TaskAuthorization` binding; its constraints are grouped inside the tenant boundary before filtering and pagination. A host-owned HTTP endpoint may call the same list action.

The `metadata` JSON object is for small app-owned hints (at most 64 keys and 64 KiB), not arbitrary rich content or typed business fields. Put structured content in Content and typed owner values in Metafields. Upload and attach files through Media's actions using the `attachments` slot; it is private, exclusive, limited to 10 files of 20 MiB each, and accepts image/document MIME types by default. The limits are configurable under `tasks.media`.

## Optional management API

`tasks.routes.management.enabled` is false by default. An app may enable the `api/v1/tasks` route group after binding its authorization policy and securing the configured middleware. The group provides task list/create/read/replace/delete/restore and assignee add/remove endpoints. Its `data`/`meta` responses contain bounded `TaskData` and `TaskAssignmentData` projections, not raw Eloquent models or HTTP Resources. HTTP assignee lookup also requires a host binding for `TaskPrincipalResolver`; the default rejects assignment requests. Apps can instead keep routes entirely host-owned and wrap the same Data projections in their centralized API response.

The default middleware is `api`, `auth`, and `throttle:60,1`. The host must ensure the chosen authentication middleware authenticates a persisted Eloquent principal and must authorize every task ability, including assignment targets. Missing authorization or principal-resolution bindings fail closed. A stale HTTP replacement returns 409; invalid input returns 422.

## Optional tenancy

Standalone mode works with Tenancy disabled. For adopted tenant mode, configure `media`, `content`, `metafields`, and `tasks` as compatible tenant resources; adopt the dependencies before Tasks. Task roots receive the active tenant ID, and assignments inherit it from their task. Task actions always re-read the canonical task through `TenantBoundary`, including when the caller passes a loaded model. Review ownership mappings and activate only after the Tenancy verification phase succeeds; do not infer a tenant from request payloads or assignee IDs.

## Development and verification

From a standalone checkout of the public Tasks repository, run the package's Pint, PHPStan, and Pest gate:

```bash
composer install
composer quality
```

Maintainer CI also validates the package family. In a consuming Laravel application, the read-only `nvl:tasks:doctor --strict --format=json` command checks schema, owner registration, route registration, and consumer bindings. For database-backed production deployments, verify task migrations and the tenant adoption plan against the target database before enabling traffic.

## License

MIT. See [LICENSE](LICENSE). Security reports should follow [SECURITY.md](SECURITY.md); upgrading notes are in [UPGRADING.md](UPGRADING.md).
