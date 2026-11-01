# Public API: nvl/tasks

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Tasks\Contracts\TaskActivityPublisher`
- `Nvl\Tasks\Contracts\TaskAttachments`
- `Nvl\Tasks\Contracts\TaskAuthorization`
- `Nvl\Tasks\Contracts\TaskQueryScope`
- `Nvl\Tasks\Contracts\TaskSchedulerReadiness`
- `Nvl\Tasks\Database\Factories\TaskAssignmentFactory`
- `Nvl\Tasks\Database\Factories\TaskChecklistItemFactory`
- `Nvl\Tasks\Database\Factories\TaskDependencyFactory`
- `Nvl\Tasks\Database\Factories\TaskFactory`
- `Nvl\Tasks\Database\Factories\TaskRelationshipFactory`
- `Nvl\Tasks\Database\Factories\TaskTagFactory`
- `Nvl\Tasks\Database\Factories\TaskTimeEntryFactory`
- `Nvl\Tasks\Enums\TaskAbility`
- `Nvl\Tasks\Enums\TaskCategory`
- `Nvl\Tasks\Enums\TaskChangeOperation`
- `Nvl\Tasks\Enums\TaskImportance`
- `Nvl\Tasks\Enums\TaskPriority`
- `Nvl\Tasks\Enums\TaskStatus`
- `Nvl\Tasks\Enums\TaskType`
- `Nvl\Tasks\Enums\TasksResponseCode`
- `Nvl\Tasks\Events\TaskChanged`
- `Nvl\Tasks\Exceptions\TaskRevisionConflict`
- `Nvl\Tasks\Exceptions\TasksException`
- `Nvl\Tasks\Models\Task`
- `Nvl\Tasks\Models\TaskAssignment`
- `Nvl\Tasks\Models\TaskChecklistItem`
- `Nvl\Tasks\Models\TaskDependency`
- `Nvl\Tasks\Models\TaskRelationship`
- `Nvl\Tasks\Models\TaskTag`
- `Nvl\Tasks\Models\TaskTimeEntry`
- `Nvl\Tasks\Services\TaskAssignments`
- `Nvl\Tasks\Services\TaskChecklist`
- `Nvl\Tasks\Services\TaskLifecycle`
- `Nvl\Tasks\Services\TaskModelRegistry`
- `Nvl\Tasks\Services\TaskReader`
- `Nvl\Tasks\Services\TaskRelationships`
- `Nvl\Tasks\Services\TaskTags`
- `Nvl\Tasks\Services\TaskTimeTracking`
- `Nvl\Tasks\Services\TasksActivityRetention`

## Nvl\Tasks\Contracts\TaskActivityPublisher

```text
interface Nvl\Tasks\Contracts\TaskActivityPublisher
public abstract function record(Nvl\Tasks\Models\Task $task, string $event, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $context=[], ?array $attributes=null, ?array $old=null): void
@param array<string, mixed> $context
@param (array<string, mixed> | null) $attributes
@param (array<string, mixed> | null) $old
```

## Nvl\Tasks\Contracts\TaskAttachments

```text
interface Nvl\Tasks\Contracts\TaskAttachments
public abstract function attach(Nvl\Tasks\Models\Task $task, string $mediaId, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): string
public abstract function detach(Nvl\Tasks\Models\Task $task, string $mediaId, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): int
public abstract function ids(Nvl\Tasks\Models\Task $task, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@return list<string>
```

## Nvl\Tasks\Contracts\TaskAuthorization

```text
interface Nvl\Tasks\Contracts\TaskAuthorization extends Nvl\Tasks\Contracts\TaskQueryScope
public abstract function authorize(Nvl\Tasks\Enums\TaskAbility $ability, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?Nvl\Tasks\Models\Task $task=null, ?Illuminate\Database\Eloquent\Model $subject=null): void
public abstract function scopeTasks(Illuminate\Database\Eloquent\Builder $query, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
@param Builder<Task> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\Task> $query
```

## Nvl\Tasks\Contracts\TaskQueryScope

```text
interface Nvl\Tasks\Contracts\TaskQueryScope
public abstract function scopeTasks(Illuminate\Database\Eloquent\Builder $query, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
@param Builder<Task> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\Task> $query
```

## Nvl\Tasks\Contracts\TaskSchedulerReadiness

```text
interface Nvl\Tasks\Contracts\TaskSchedulerReadiness
public abstract function running(): bool
```

## Nvl\Tasks\Database\Factories\TaskAssignmentFactory

```text
final class Nvl\Tasks\Database\Factories\TaskAssignmentFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TaskAssignment>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\TaskAssignment>
public function definition(): array
@return array<model-property<TaskAssignment>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\TaskAssignment>, mixed>
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function forTask(Nvl\Tasks\Models\Task $parent): static
```

## Nvl\Tasks\Database\Factories\TaskChecklistItemFactory

```text
final class Nvl\Tasks\Database\Factories\TaskChecklistItemFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TaskChecklistItem>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\TaskChecklistItem>
public function definition(): array
@return array<model-property<TaskChecklistItem>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\TaskChecklistItem>, mixed>
public function forTask(Nvl\Tasks\Models\Task $parent): static
```

## Nvl\Tasks\Database\Factories\TaskDependencyFactory

```text
final class Nvl\Tasks\Database\Factories\TaskDependencyFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TaskDependency>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\TaskDependency>
public function definition(): array
@return array<model-property<TaskDependency>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\TaskDependency>, mixed>
public function forBlocker(Nvl\Tasks\Models\Task $parent): static
public function forTask(Nvl\Tasks\Models\Task $parent): static
```

## Nvl\Tasks\Database\Factories\TaskFactory

```text
final class Nvl\Tasks\Database\Factories\TaskFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Task>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\Task>
public function definition(): array
@return array<model-property<Task>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\Task>, mixed>
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function modelName(): string
```

## Nvl\Tasks\Database\Factories\TaskRelationshipFactory

```text
final class Nvl\Tasks\Database\Factories\TaskRelationshipFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TaskRelationship>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\TaskRelationship>
public function definition(): array
@return array<model-property<TaskRelationship>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\TaskRelationship>, mixed>
public function forChild(Nvl\Tasks\Models\Task $parent): static
public function forParent(Nvl\Tasks\Models\Task $parent): static
```

## Nvl\Tasks\Database\Factories\TaskTagFactory

```text
final class Nvl\Tasks\Database\Factories\TaskTagFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TaskTag>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\TaskTag>
public function definition(): array
@return array<model-property<TaskTag>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\TaskTag>, mixed>
public function forTask(Nvl\Tasks\Models\Task $parent): static
```

## Nvl\Tasks\Database\Factories\TaskTimeEntryFactory

```text
final class Nvl\Tasks\Database\Factories\TaskTimeEntryFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TaskTimeEntry>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Tasks\Models\TaskTimeEntry>
public function definition(): array
@return array<model-property<TaskTimeEntry>, mixed>
resolved @return array<model-property<Nvl\Tasks\Models\TaskTimeEntry>, mixed>
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function forTask(Nvl\Tasks\Models\Task $parent): static
```

## Nvl\Tasks\Enums\TaskAbility

```text
enum Nvl\Tasks\Enums\TaskAbility implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskAbility Assign=Nvl\Tasks\Enums\TaskAbility::Assign="assign"
public const Nvl\Tasks\Enums\TaskAbility Create=Nvl\Tasks\Enums\TaskAbility::Create="create"
public const Nvl\Tasks\Enums\TaskAbility Delete=Nvl\Tasks\Enums\TaskAbility::Delete="delete"
public const Nvl\Tasks\Enums\TaskAbility List=Nvl\Tasks\Enums\TaskAbility::List="list"
public const Nvl\Tasks\Enums\TaskAbility PruneActivity=Nvl\Tasks\Enums\TaskAbility::PruneActivity="prune_activity"
public const Nvl\Tasks\Enums\TaskAbility Restore=Nvl\Tasks\Enums\TaskAbility::Restore="restore"
public const Nvl\Tasks\Enums\TaskAbility Update=Nvl\Tasks\Enums\TaskAbility::Update="update"
public const Nvl\Tasks\Enums\TaskAbility View=Nvl\Tasks\Enums\TaskAbility::View="view"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TaskCategory

```text
enum Nvl\Tasks\Enums\TaskCategory implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskCategory Administrative=Nvl\Tasks\Enums\TaskCategory::Administrative="administrative"
public const Nvl\Tasks\Enums\TaskCategory General=Nvl\Tasks\Enums\TaskCategory::General="general"
public const Nvl\Tasks\Enums\TaskCategory Operations=Nvl\Tasks\Enums\TaskCategory::Operations="operations"
public const Nvl\Tasks\Enums\TaskCategory Project=Nvl\Tasks\Enums\TaskCategory::Project="project"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TaskChangeOperation

```text
enum Nvl\Tasks\Enums\TaskChangeOperation implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskChangeOperation Assigned=Nvl\Tasks\Enums\TaskChangeOperation::Assigned="assigned"
public const Nvl\Tasks\Enums\TaskChangeOperation BlockerAdded=Nvl\Tasks\Enums\TaskChangeOperation::BlockerAdded="blocker_added"
public const Nvl\Tasks\Enums\TaskChangeOperation BlockerRemoved=Nvl\Tasks\Enums\TaskChangeOperation::BlockerRemoved="blocker_removed"
public const Nvl\Tasks\Enums\TaskChangeOperation ChecklistItemAdded=Nvl\Tasks\Enums\TaskChangeOperation::ChecklistItemAdded="checklist_item_added"
public const Nvl\Tasks\Enums\TaskChangeOperation ChecklistItemCompleted=Nvl\Tasks\Enums\TaskChangeOperation::ChecklistItemCompleted="checklist_item_completed"
public const Nvl\Tasks\Enums\TaskChangeOperation ChecklistItemRemoved=Nvl\Tasks\Enums\TaskChangeOperation::ChecklistItemRemoved="checklist_item_removed"
public const Nvl\Tasks\Enums\TaskChangeOperation ChecklistItemReopened=Nvl\Tasks\Enums\TaskChangeOperation::ChecklistItemReopened="checklist_item_reopened"
public const Nvl\Tasks\Enums\TaskChangeOperation ChecklistItemUpdated=Nvl\Tasks\Enums\TaskChangeOperation::ChecklistItemUpdated="checklist_item_updated"
public const Nvl\Tasks\Enums\TaskChangeOperation ChecklistReordered=Nvl\Tasks\Enums\TaskChangeOperation::ChecklistReordered="checklist_reordered"
public const Nvl\Tasks\Enums\TaskChangeOperation Created=Nvl\Tasks\Enums\TaskChangeOperation::Created="created"
public const Nvl\Tasks\Enums\TaskChangeOperation Deleted=Nvl\Tasks\Enums\TaskChangeOperation::Deleted="deleted"
public const Nvl\Tasks\Enums\TaskChangeOperation ParentLinked=Nvl\Tasks\Enums\TaskChangeOperation::ParentLinked="parent_linked"
public const Nvl\Tasks\Enums\TaskChangeOperation ParentUnlinked=Nvl\Tasks\Enums\TaskChangeOperation::ParentUnlinked="parent_unlinked"
public const Nvl\Tasks\Enums\TaskChangeOperation Restored=Nvl\Tasks\Enums\TaskChangeOperation::Restored="restored"
public const Nvl\Tasks\Enums\TaskChangeOperation TagAdded=Nvl\Tasks\Enums\TaskChangeOperation::TagAdded="tag_added"
public const Nvl\Tasks\Enums\TaskChangeOperation TagRemoved=Nvl\Tasks\Enums\TaskChangeOperation::TagRemoved="tag_removed"
public const Nvl\Tasks\Enums\TaskChangeOperation TimeEntryAdded=Nvl\Tasks\Enums\TaskChangeOperation::TimeEntryAdded="time_entry_added"
public const Nvl\Tasks\Enums\TaskChangeOperation TimeEntryRemoved=Nvl\Tasks\Enums\TaskChangeOperation::TimeEntryRemoved="time_entry_removed"
public const Nvl\Tasks\Enums\TaskChangeOperation TimeEntryUpdated=Nvl\Tasks\Enums\TaskChangeOperation::TimeEntryUpdated="time_entry_updated"
public const Nvl\Tasks\Enums\TaskChangeOperation TimerStarted=Nvl\Tasks\Enums\TaskChangeOperation::TimerStarted="timer_started"
public const Nvl\Tasks\Enums\TaskChangeOperation TimerStopped=Nvl\Tasks\Enums\TaskChangeOperation::TimerStopped="timer_stopped"
public const Nvl\Tasks\Enums\TaskChangeOperation Unassigned=Nvl\Tasks\Enums\TaskChangeOperation::Unassigned="unassigned"
public const Nvl\Tasks\Enums\TaskChangeOperation Updated=Nvl\Tasks\Enums\TaskChangeOperation::Updated="updated"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TaskImportance

```text
enum Nvl\Tasks\Enums\TaskImportance implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskImportance Critical=Nvl\Tasks\Enums\TaskImportance::Critical="critical"
public const Nvl\Tasks\Enums\TaskImportance High=Nvl\Tasks\Enums\TaskImportance::High="high"
public const Nvl\Tasks\Enums\TaskImportance Low=Nvl\Tasks\Enums\TaskImportance::Low="low"
public const Nvl\Tasks\Enums\TaskImportance Normal=Nvl\Tasks\Enums\TaskImportance::Normal="normal"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TaskPriority

```text
enum Nvl\Tasks\Enums\TaskPriority implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskPriority High=Nvl\Tasks\Enums\TaskPriority::High="high"
public const Nvl\Tasks\Enums\TaskPriority Low=Nvl\Tasks\Enums\TaskPriority::Low="low"
public const Nvl\Tasks\Enums\TaskPriority Normal=Nvl\Tasks\Enums\TaskPriority::Normal="normal"
public const Nvl\Tasks\Enums\TaskPriority Urgent=Nvl\Tasks\Enums\TaskPriority::Urgent="urgent"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TaskStatus

```text
enum Nvl\Tasks\Enums\TaskStatus implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskStatus Blocked=Nvl\Tasks\Enums\TaskStatus::Blocked="blocked"
public const Nvl\Tasks\Enums\TaskStatus Cancelled=Nvl\Tasks\Enums\TaskStatus::Cancelled="cancelled"
public const Nvl\Tasks\Enums\TaskStatus Completed=Nvl\Tasks\Enums\TaskStatus::Completed="completed"
public const Nvl\Tasks\Enums\TaskStatus InProgress=Nvl\Tasks\Enums\TaskStatus::InProgress="in_progress"
public const Nvl\Tasks\Enums\TaskStatus Open=Nvl\Tasks\Enums\TaskStatus::Open="open"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TaskType

```text
enum Nvl\Tasks\Enums\TaskType implements BackedEnum, UnitEnum: string
public const Nvl\Tasks\Enums\TaskType Action=Nvl\Tasks\Enums\TaskType::Action="action"
public const Nvl\Tasks\Enums\TaskType Approval=Nvl\Tasks\Enums\TaskType::Approval="approval"
public const Nvl\Tasks\Enums\TaskType FollowUp=Nvl\Tasks\Enums\TaskType::FollowUp="follow_up"
public const Nvl\Tasks\Enums\TaskType General=Nvl\Tasks\Enums\TaskType::General="general"
public const Nvl\Tasks\Enums\TaskType Review=Nvl\Tasks\Enums\TaskType::Review="review"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Enums\TasksResponseCode

```text
enum Nvl\Tasks\Enums\TasksResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Tasks\Enums\TasksResponseCode BindingRequired=Nvl\Tasks\Enums\TasksResponseCode::BindingRequired="binding_required"
public const Nvl\Tasks\Enums\TasksResponseCode OperationFailed=Nvl\Tasks\Enums\TasksResponseCode::OperationFailed="operation_failed"
public const Nvl\Tasks\Enums\TasksResponseCode TaskRevisionConflict=Nvl\Tasks\Enums\TasksResponseCode::TaskRevisionConflict="task_revision_conflict"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Tasks\Events\TaskChanged

```text
final readonly class Nvl\Tasks\Events\TaskChanged implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $taskId, int $revision, Nvl\Tasks\Enums\TaskChangeOperation $operation, array $actor, array $context=[], int $schemaVersion=1, ?Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope $ownership=null): mixed
@param array{type: (string | null), id: (string | null), system: bool} $actor
@param array<string, (bool | float | int | string | null)> $context
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $actor
@param array{type: (string | null), id: (string | null), system: bool} $actor
public readonly array $context
@param array<string, (bool | float | int | string | null)> $context
public readonly Nvl\Tasks\Enums\TaskChangeOperation $operation
public readonly int $revision
public readonly int $schemaVersion
public readonly string $taskId
```

## Nvl\Tasks\Exceptions\TaskRevisionConflict

```text
final class Nvl\Tasks\Exceptions\TaskRevisionConflict extends Nvl\Tasks\Exceptions\TasksException
public function __construct(): mixed
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Tasks\Exceptions\TasksException

```text
class Nvl\Tasks\Exceptions\TasksException extends RuntimeException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Tasks\Models\Task

```text
class Nvl\Tasks\Models\Task extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TENANT_RESOURCE="tasks.tasks"
public function assignments(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskAssignment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskAssignment, $this>
public function blockedTaskLinks(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskDependency, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskDependency, $this>
public function blockerLinks(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskDependency, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskDependency, $this>
public function checklistItems(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskChecklistItem, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskChecklistItem, $this>
public function childLinks(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskRelationship, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskRelationship, $this>
public function creator(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\Task>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function parentLink(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskRelationship, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskRelationship, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function tags(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskTag, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskTag, $this>
public function timeEntries(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<TaskTimeEntry, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Tasks\Models\TaskTimeEntry, $this>
@property-read Collection<int, TaskAssignment> $assignments
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Tasks\Models\TaskAssignment> $assignments
@property-read int $assignments_count
@property BackedEnum $category
@property-read Collection<int, TaskChecklistItem> $checklistItems
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Tasks\Models\TaskChecklistItem> $checklistItems
@property (CarbonImmutable | null) $completed_at
resolved @property (Carbon\CarbonImmutable | null) $completed_at
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property-read (Model | null) $creator
resolved @property-read (Illuminate\Database\Eloquent\Model | null) $creator
@property (string | null) $creator_id
@property (string | null) $creator_type
@property (Carbon | null) $deleted_at
resolved @property (Illuminate\Support\Carbon | null) $deleted_at
@property (string | null) $description
@property (CarbonImmutable | null) $due_at
resolved @property (Carbon\CarbonImmutable | null) $due_at
@property (int | null) $estimated_seconds
@property string $id
@property BackedEnum $importance
@property (array<string, mixed> | null) $metadata
@property BackedEnum $priority
@property int $revision
@property BackedEnum $status
@property-read Collection<int, TaskTag> $tags
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Tasks\Models\TaskTag> $tags
@property (CarbonImmutable | null) $target_at
resolved @property (Carbon\CarbonImmutable | null) $target_at
@property (string | null) $tenant_id
@property-read Collection<int, TaskTimeEntry> $timeEntries
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Tasks\Models\TaskTimeEntry> $timeEntries
@property string $title
@property BackedEnum $type
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Models\TaskAssignment

```text
final class Nvl\Tasks\Models\TaskAssignment extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function assignedBy(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function assignee(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\TaskAssignment>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function task(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
@property-read (Model | null) $assignedBy
resolved @property-read (Illuminate\Database\Eloquent\Model | null) $assignedBy
@property (string | null) $assigned_by_id
@property (string | null) $assigned_by_type
@property-read (Model | null) $assignee
resolved @property-read (Illuminate\Database\Eloquent\Model | null) $assignee
@property string $assignee_id
@property string $assignee_type
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property-read Task $task
resolved @property-read Nvl\Tasks\Models\Task $task
@property string $task_id
@property (string | null) $tenant_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Models\TaskChecklistItem

```text
final class Nvl\Tasks\Models\TaskChecklistItem extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function completedBy(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\TaskChecklistItem>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function task(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
@property-read (Model | null) $completedBy
resolved @property-read (Illuminate\Database\Eloquent\Model | null) $completedBy
@property (CarbonImmutable | null) $completed_at
resolved @property (Carbon\CarbonImmutable | null) $completed_at
@property (string | null) $completed_by_id
@property (string | null) $completed_by_type
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property int $position
@property-read Task $task
resolved @property-read Nvl\Tasks\Models\Task $task
@property string $task_id
@property (string | null) $tenant_id
@property string $title
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Models\TaskDependency

```text
final class Nvl\Tasks\Models\TaskDependency extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function blocker(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\TaskDependency>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function task(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
@property-read Task $blocker
resolved @property-read Nvl\Tasks\Models\Task $blocker
@property string $blocker_task_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property-read Task $task
resolved @property-read Nvl\Tasks\Models\Task $task
@property string $task_id
@property (string | null) $tenant_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Models\TaskRelationship

```text
final class Nvl\Tasks\Models\TaskRelationship extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function child(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\TaskRelationship>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function parent(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property-read Task $child
resolved @property-read Nvl\Tasks\Models\Task $child
@property string $child_task_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property-read Task $parent
resolved @property-read Nvl\Tasks\Models\Task $parent
@property string $parent_task_id
@property (string | null) $tenant_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Models\TaskTag

```text
final class Nvl\Tasks\Models\TaskTag extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\TaskTag>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function task(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property string $tag
@property-read Task $task
resolved @property-read Nvl\Tasks\Models\Task $task
@property string $task_id
@property (string | null) $tenant_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Models\TaskTimeEntry

```text
final class Nvl\Tasks\Models\TaskTimeEntry extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Tasks\Models\TaskTimeEntry>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function performer(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function task(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Task, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Tasks\Models\Task, $this>
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property (string | null) $description
@property (int | null) $duration_seconds
@property string $id
@property-read (Model | null) $performer
resolved @property-read (Illuminate\Database\Eloquent\Model | null) $performer
@property string $performer_id
@property string $performer_type
@property CarbonImmutable $started_at
resolved @property Carbon\CarbonImmutable $started_at
@property (CarbonImmutable | null) $stopped_at
resolved @property (Carbon\CarbonImmutable | null) $stopped_at
@property-read Task $task
resolved @property-read Nvl\Tasks\Models\Task $task
@property string $task_id
@property (string | null) $tenant_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Tasks\Services\TaskAssignments

```text
final readonly class Nvl\Tasks\Services\TaskAssignments
public function __construct(Nvl\Tasks\Contracts\TaskAuthorization $authorization, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Tasks\Services\TasksActivity $activity, Nvl\Tasks\Services\TaskRecordWriter $records, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function assign(Nvl\Tasks\Models\Task|string $task, Illuminate\Database\Eloquent\Model&Illuminate\Contracts\Auth\Authenticatable $assignee, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskAssignment
public function unassign(Nvl\Tasks\Models\Task|string $task, Illuminate\Database\Eloquent\Model&Illuminate\Contracts\Auth\Authenticatable $assignee, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
```

## Nvl\Tasks\Services\TaskChecklist

```text
final readonly class Nvl\Tasks\Services\TaskChecklist
public function __construct(Nvl\Tasks\Services\TaskChecklistMutationGuard $guard, Nvl\Tasks\Services\TasksActivity $activity, Nvl\Tasks\Services\TaskRecordWriter $records, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function add(Nvl\Tasks\Models\Task|string $task, string $title, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskChecklistItem
public function remove(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\TaskChecklistItem|string $item, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\Task
public function reorder(Nvl\Tasks\Models\Task|string $task, array $itemIds, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\Task
@param list<string> $itemIds
public function toggle(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\TaskChecklistItem|string $item, bool $completed, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskChecklistItem
public function update(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\TaskChecklistItem|string $item, string $title, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskChecklistItem
```

## Nvl\Tasks\Services\TaskLifecycle

```text
final readonly class Nvl\Tasks\Services\TaskLifecycle
public function __construct(Nvl\Tasks\Contracts\TaskAuthorization $authorization, Nvl\Tasks\Services\TaskMutationValues $values, Nvl\Tasks\Services\TasksActivity $activity, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function create(array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\Task
@param array{title: string, description?: (string | null), status?: (\BackedEnum | string | null), priority?: (\BackedEnum | string | null), type?: (\BackedEnum | string | null), category?: (\BackedEnum | string | null), importance?: (\BackedEnum | string | null), target_at?: (\DateTimeInterface | string | null), due_at?: (\DateTimeInterface | string | null), estimated_seconds?: (int | null), metadata?: array<string, mixed>} $attributes
resolved @param array{title: string, description?: (string | null), status?: (BackedEnum | string | null), priority?: (BackedEnum | string | null), type?: (BackedEnum | string | null), category?: (BackedEnum | string | null), importance?: (BackedEnum | string | null), target_at?: (DateTimeInterface | string | null), due_at?: (DateTimeInterface | string | null), estimated_seconds?: (int | null), metadata?: array<string, mixed>} $attributes
public function delete(Nvl\Tasks\Models\Task|string $task, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
public function restore(Nvl\Tasks\Models\Task|string $task, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\Task
public function update(Nvl\Tasks\Models\Task|string $task, array $changes, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\Task
@param array{title?: string, description?: (string | null), status?: (\BackedEnum | string | null), priority?: (\BackedEnum | string | null), type?: (\BackedEnum | string | null), category?: (\BackedEnum | string | null), importance?: (\BackedEnum | string | null), target_at?: (\DateTimeInterface | string | null), due_at?: (\DateTimeInterface | string | null), estimated_seconds?: (int | null), metadata?: array<string, mixed>} $changes
resolved @param array{title?: string, description?: (string | null), status?: (BackedEnum | string | null), priority?: (BackedEnum | string | null), type?: (BackedEnum | string | null), category?: (BackedEnum | string | null), importance?: (BackedEnum | string | null), target_at?: (DateTimeInterface | string | null), due_at?: (DateTimeInterface | string | null), estimated_seconds?: (int | null), metadata?: array<string, mixed>} $changes
```

## Nvl\Tasks\Services\TaskModelRegistry

```text
final class Nvl\Tasks\Services\TaskModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function taskClass(): string
@return class-string<Task>
resolved @return class-string<Nvl\Tasks\Models\Task>
```

## Nvl\Tasks\Services\TaskReader

```text
final readonly class Nvl\Tasks\Services\TaskReader
public function __construct(Nvl\Tasks\Services\TaskReadGuard $reads, Nvl\Tasks\Contracts\TaskAuthorization $authorization, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function checklist(Nvl\Tasks\Models\Task|string $task, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?int $perPage=null, int $page=1): Illuminate\Contracts\Pagination\LengthAwarePaginator
@return LengthAwarePaginator<int, TaskChecklistItem>
resolved @return Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Tasks\Models\TaskChecklistItem>
public function dashboard(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?int $dueSoonDays=null): array
@return array{total: int, open: int, in_progress: int, blocked: int, completed: int, overdue: int, due_soon: int, due_soon_days: int, unassigned: int, estimated_seconds: int, logged_seconds: int}
public function get(Nvl\Tasks\Models\Task|string $task, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\Task
public function paginate(array $criteria, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, int $perPage=25, int $page=1): Illuminate\Contracts\Pagination\LengthAwarePaginator
@param array{status?: (BackedEnum | string | null), priority?: (BackedEnum | string | null), assignee?: (Authenticatable | null), type?: (BackedEnum | string | null), category?: (BackedEnum | string | null), importance?: (BackedEnum | string | null), tag?: (string | null), target_from?: (string | null), target_to?: (string | null), due_from?: (string | null), due_to?: (string | null), overdue?: (bool | null)} $criteria
resolved @param array{status?: (BackedEnum | string | null), priority?: (BackedEnum | string | null), assignee?: (Illuminate\Contracts\Auth\Authenticatable | null), type?: (BackedEnum | string | null), category?: (BackedEnum | string | null), importance?: (BackedEnum | string | null), tag?: (string | null), target_from?: (string | null), target_to?: (string | null), due_from?: (string | null), due_to?: (string | null), overdue?: (bool | null)} $criteria
@return LengthAwarePaginator<int, Task>
resolved @return Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Tasks\Models\Task>
public function timeEntries(Nvl\Tasks\Models\Task|string $task, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?int $perPage=null, int $page=1): Illuminate\Contracts\Pagination\LengthAwarePaginator
@return LengthAwarePaginator<int, TaskTimeEntry>
resolved @return Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Tasks\Models\TaskTimeEntry>
```

## Nvl\Tasks\Services\TaskRelationships

```text
final readonly class Nvl\Tasks\Services\TaskRelationships
public function __construct(Nvl\Tasks\Services\TaskGraphGuard $guard, Nvl\Tasks\Services\TasksActivity $activity, Nvl\Tasks\Services\TaskRecordWriter $records, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function addDependency(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\Task|string $blocker, int $expectedTaskRevision, int $expectedBlockerRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskDependency
public function linkParent(Nvl\Tasks\Models\Task|string $child, Nvl\Tasks\Models\Task|string $parent, int $expectedChildRevision, int $expectedParentRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskRelationship
public function removeDependency(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\Task|string $blocker, int $expectedTaskRevision, int $expectedBlockerRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
public function unlinkParent(Nvl\Tasks\Models\Task|string $child, Nvl\Tasks\Models\Task|string $parent, int $expectedChildRevision, int $expectedParentRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
```

## Nvl\Tasks\Services\TaskTags

```text
final readonly class Nvl\Tasks\Services\TaskTags
public function __construct(Nvl\Tasks\Contracts\TaskAuthorization $authorization, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Tasks\Services\TasksActivity $activity, Nvl\Tasks\Services\TaskRecordWriter $records, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function add(Nvl\Tasks\Models\Task|string $task, string $tag, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskTag
public function remove(Nvl\Tasks\Models\Task|string $task, string $tag, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
```

## Nvl\Tasks\Services\TaskTimeTracking

```text
final readonly class Nvl\Tasks\Services\TaskTimeTracking
public function __construct(Nvl\Tasks\Contracts\TaskAuthorization $authorization, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Tasks\Services\TasksActivity $activity, Nvl\Tasks\Services\TaskRecordWriter $records, Nvl\Tasks\Services\TaskPrincipalGuard $principals): mixed
public function add(Nvl\Tasks\Models\Task|string $task, array $attributes, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskTimeEntry
@param array{started_at: (\DateTimeInterface | string), ended_at: (\DateTimeInterface | string), description?: (string | null)} $attributes
resolved @param array{started_at: (DateTimeInterface | string), ended_at: (DateTimeInterface | string), description?: (string | null)} $attributes
public function delete(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\TaskTimeEntry|string $entry, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): bool
public function startTimer(Nvl\Tasks\Models\Task|string $task, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskTimeEntry
public function stopTimer(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\TaskTimeEntry|string $entry, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskTimeEntry
public function update(Nvl\Tasks\Models\Task|string $task, Nvl\Tasks\Models\TaskTimeEntry|string $entry, array $attributes, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Tasks\Models\TaskTimeEntry
@param array{started_at: (\DateTimeInterface | string), ended_at: (\DateTimeInterface | string), description?: (string | null)} $attributes
resolved @param array{started_at: (DateTimeInterface | string), ended_at: (DateTimeInterface | string), description?: (string | null)} $attributes
```

## Nvl\Tasks\Services\TasksActivityRetention

```text
final readonly class Nvl\Tasks\Services\TasksActivityRetention
public function __construct(Nvl\Tasks\Contracts\TaskAuthorization $authorization, Nvl\Tasks\Services\TaskPrincipalGuard $principals, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary): mixed
public function prune(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, bool $dryRun=false): array
@return array{matched: int, deleted: int, has_more: bool}
```
