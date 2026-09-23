---
name: nvl-tasks
description: Implement or review nvl/tasks lifecycle, assignees, tenant isolation, private Media attachments, Content details, Metafields, and opt-in management routes.
---

# NVL Tasks

Use this skill for task-management work in a Laravel application consuming `nvl/tasks`.

## Ownership and policy

- Use `CreateTaskAction`, `UpdateTaskAction`, `DeleteTaskAction`, `RestoreTaskAction`, `AssignTaskAction`, and `UnassignTaskAction` for writes. They re-read task identity through the tenant boundary; never let request data set tenant or creator columns.
- Bind `TaskAuthorization` for every user-facing ability. The default rejects user work. Use `TaskActorData::fromAuthenticatable()` with a persisted Eloquent principal; reserve `TaskActorData::system()` for trusted jobs or seeders.
- `UpdateTaskAction` requires the current `expectedRevision`. Surface `TaskRevisionConflict` as a conflict, not an unguarded retry.
- Keep assignees in task assignment records. Register stable morph aliases for host principal models; do not couple Tasks to a specific Auth package model or write polymorphic type names from HTTP input.

## Package integrations

- Plain description and small app hints belong on the task. Rich detail blocks use the `details` Content group; typed custom fields use the `task` Metafields owner. Follow those packages' own actions and authorization contracts.
- Task files use the private, exclusive Media `attachments` slot. Use Media's upload/attachment actions and delivery policy, not new file columns or public URLs.
- For tenant mode, adopt Media, Content, and Metafields before Tasks. Task roots receive tenant identity from `TenantBoundary`; assignments inherit it from their canonical task.

## Transport and verification

- Management routes are opt-in. Bind both `TaskAuthorization` and `TaskPrincipalResolver` before enabling HTTP assignment, and secure the host-selected middleware. Apps may keep their own routes and call the same actions.
- Use `ListTasksAction` for bounded, authorized table pages. Do not serialize the task model or load all assignees for a list.
- Run focused Pest tests, PHPStan, and `nvl:tasks:doctor --strict` after changing schema, authorization, tenancy, or route configuration.
