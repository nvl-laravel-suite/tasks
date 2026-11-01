# Upgrading to NVL Light

This is a breaking application API conversion. Internal family version 6 remains until the coordinated release; there are no compatibility aliases.

Replace the 28 operation Actions/interfaces with the seven service families documented in README. Inject services directly and replace mutation DTOs with validated native arrays/scalars. Pass the persisted actor explicitly; replace system actor wrappers with a deliberately authorized `SystemActor`. Move DTOs, frontend types, controllers, requests, routes and projections into the application. `GetTaskDetailAction` becomes `TaskReader::get` plus a consumer projection. The package registers no HTTP routes.

Implement `TaskAuthorization::scopeTasks` as well as `authorize`; visibility must be expressible before database pagination/counts. Remove old TaskPrincipalResolver bindings and current-request actor adapters. Native models remain available for scoped reads and casts; service writes preserve canonical admissions, CAS and transactional behavior.

Update payload keys to snake_case. Send expected revisions as explicit arguments, never mutable payload fields. Omitted update keys retain state; null clears nullable fields or resets configured classifications. Unknown/protected fields and type coercions are rejected. Dates are stored/read as UTC instants at second precision.

Run the new additive graph-lock migration before traffic. It creates an internal serialization row, retains all task/child data and prevents disjoint concurrent graph cycles. Existing graphs are checked during mutation; application-owned historical corruption must be reconciled explicitly.

Graph traversal now has explicit vertex/edge budgets (defaults 1000/5000), including the proposed edge/endpoints. The mutex and current locking reads remain required; a stale PostgreSQL or enforced MariaDB outer repeatable-read snapshot may require retrying the entire host transaction. Apply `2026_11_01_194707_nvl_tasks_add_activity_delivery_ready_index.php` explicitly for existing storage. It adds a ready index without rewriting historical tenant fields or delivery evidence. Delivered outbox pruning is a separate disabled-by-default capability with a null retention age; grant `TaskAbility::PruneActivity` for the entire admitted owner scope and configure an explicit age before scheduling `TasksActivityRetention`. Both dry previews and writes require no host transaction. Pending outbox and Activity audit records remain intact.

Replace legacy Tenancy setup with native Ownership registration/adoption. Do not reactivate old registrars or infer ownership from historical tenant columns. Pending outbox records retain captured immutable native scope. Review queued legacy payloads before cutover; a missing native ownership envelope fails closed.

Set Activity integration explicitly when desired and register its native provider. Keep the Tasks source outbox, queue worker, scheduler and recovery configuration. Old Activity facades and actor DTOs are not supported. Scalar historical actor IDs are audit facts, not permission to perform later writes.

Enable Media explicitly with `nvl-tasks.media.enabled=true` when native Media is installed. The implemented `MediaTaskAttachments` adapter uses Media-owned reads and writes through `TaskAttachments`; the enabled-profile tests cover private slots, lifecycle, limits, actor policy and foreign-association rejection. Base Tasks continues to boot with Media absent.
