---
name: nvl-tasks
description: Use when implementing or reviewing native Tasks services, actor authorization, model reads, graph/timer operations, ownership or optional Activity integration.
---

# Native NVL Tasks

Inject the seven services directly: TaskLifecycle, TaskAssignments, TaskChecklist, TaskTags, TaskTimeTracking, TaskRelationships and TaskReader. See README for exact native array/scalar signatures. Applications own DTOs, HTTP routes, request parsing, response projections and frontend types; the package supplies none of these transport layers.

Pass an explicit persisted Eloquent Authenticatable or deliberately authorized SystemActor. Bind TaskAuthorization::authorize and scopeTasks. Both users and system actors are denied by default. Never infer actors from current Auth/Request or turn scalar audit attribution into current authority. Canonical ownership and principal admission must remain fresh.

Use snake_case native fields, separate expected revisions, exact integer/boolean types and explicit omission/null semantics. Protect primary keys, revisions, owner and actor fields. Dates preserve instants in UTC at second precision. Services own validation, transactions, locking, CAS, graph cycles, timer overlap, child attribution, events and refreshed returns.

Use TaskReader for user-facing pages/aggregates: actor SQL visibility is grouped inside ownership before pagination/counts. Models expose useful casts, relations and scoped reads; raw model queries do not add host actor policy. Mutation writes belong to services.

Run the additive graph mutex migration before serving graph changes. It serializes all graph edits on the configured source connection before endpoint locks. Do not replace native race tests with sequential loops.

Activity is explicitly enabled, optional and uses native immutable envelope delivery through the durable Tasks source outbox. Preserve source outer commit, rollback, leases, idempotent replay, conflict rejection and recovery. Historical actor IDs survive deletion as facts. Native Ownership capture replaces legacy tenancy authority.

Media is explicitly enabled and uses the native MediaTaskAttachments adapter through TaskAttachments. Preserve private/exclusive/keepLatest slot behavior, file and attachment limits, scan admission, actor and owner policies, foreign-association exclusion and Media-owned writes. The enabled-profile tests exercise the implemented adapter. Keep the base package usable with Media physically absent; do not restore old Media Action/DTO imports.

Run the owning Pest suite and configured maximum package-quality gate; exercise the consumer-owned HTTP fixtures and real PostgreSQL/MySQL races for affected behavior. Installed optional dependencies do not enable integration profiles automatically.
