# Task events

### TaskChanged

`Nvl\Tasks\Events\TaskChanged` is an immutable native domain event published after the actual source connection's outer commit. It contains `taskId: string`, `revision: int`, `operation: TaskChangeOperation`, `actor: array{type: ?string, id: ?string, system: bool}`, safe scalar `context`, and `schemaVersion: int` (1). It privately captures a native `OwnershipJobEnvelope`, available through `ownershipJobEnvelope()`.

Actor values are historical attribution, never a current principal or authority. Payloads contain no models, descriptions, checklist titles, tag text or request state. Assignment context contains assignee type/id, checklist context item ID, time context entry ID and applicable duration, parent context parent ID and dependency context blocker ID. Other operations carry empty context. Exact operations and public fields are cataloged in `resources/event-catalog.json`.

No-op, duplicate and rejected stale mutations emit nothing. Rollback discards publication. Host listeners may add their own deferral. Native event callbacks are not crash-durable; optional Activity uses its separate durable source outbox and immutable delivery identity. Outbox replay does not replay TaskChanged. Events without captured native ownership fail closed across ownership cutover.
