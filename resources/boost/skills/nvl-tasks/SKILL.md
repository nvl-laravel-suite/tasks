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
- Configure string-backed enum classes through `tasks.enums` and matching values through `tasks.defaults`. Set `tasks.lifecycle.completed_status` to the configured completion status. Model casts, validation, and queries use those enums.

## Package integrations

- Plain description and small app hints belong on the task. Use package checklists for steps, tags for labels, time entries for effort, and task links for hierarchy or blockers. `target_at` is a planning target; `due_at` is the firm deadline.
- Activity records meaningful task lifecycle and assignment events. Use Activity's timeline reads for audit views.
- Task files use the private, exclusive Media `attachments` slot. Use Media's upload/attachment actions and delivery policy, not new file columns or public URLs.
- For tenant mode, adopt Media and Activity before Tasks. Task roots receive tenant identity from `TenantBoundary`; child records inherit it from their canonical task.

## Transport and verification

- Management routes are opt-in. Bind both `TaskAuthorization` and `TaskPrincipalResolver` before enabling HTTP assignment, and secure the host-selected middleware. Apps may keep their own routes and call the same actions.
- Use `ListTasksAction` for bounded, authorized pages and `GetTaskDashboardAction` for summary counts. Project authorized detail through `TaskDetailData`. Do not serialize task models or load unbounded relations for a list.
- Run focused Pest tests, PHPStan, and `nvl:tasks:doctor --strict` after changing schema, authorization, tenancy, or route configuration.
