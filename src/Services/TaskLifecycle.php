<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Native TaskLifecycle operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskLifecycle
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskAuthorization $authorization, private TaskMutationValues $values, private TasksActivity $activity, private OwnershipBoundary $boundary, private TaskPrincipalGuard $principals) {}

    /** Persist a validated task and return its post-write state.
     * @param  array{title: string, description?: string|null, status?: \BackedEnum|string|null, priority?: \BackedEnum|string|null, type?: \BackedEnum|string|null, category?: \BackedEnum|string|null, importance?: \BackedEnum|string|null, target_at?: \DateTimeInterface|string|null, due_at?: \DateTimeInterface|string|null, estimated_seconds?: int|null, metadata?: array<string, mixed>}  $attributes
     */
    public function create(array $attributes, Authenticatable|SystemActor $actor): Task
    {
        $actor = $this->principals->actor($actor);
        $this->authorization->authorize(TaskAbility::Create, $actor);
        $values = $this->values->create($attributes);

        $task = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $values): Task {
            $task = TaskModelRegistry::create([
                ...$values,
                'creator_type' => TaskPrincipalGuard::type($actor),
                'creator_id' => TaskPrincipalGuard::id($actor) === null ? null : (string) TaskPrincipalGuard::id($actor),
            ]);
            $this->activity->created($task, $actor);

            return $task;
        });

        return $task->refresh();
    }

    /** Patch editable fields: omission preserves, nullable values clear, null classifications restore defaults.
     * @param  array{title?: string, description?: string|null, status?: \BackedEnum|string|null, priority?: \BackedEnum|string|null, type?: \BackedEnum|string|null, category?: \BackedEnum|string|null, importance?: \BackedEnum|string|null, target_at?: \DateTimeInterface|string|null, due_at?: \DateTimeInterface|string|null, estimated_seconds?: int|null, metadata?: array<string, mixed>}  $changes
     */
    public function update(Task|string $task, array $changes, int $expectedRevision, Authenticatable|SystemActor $actor): Task
    {
        $actor = $this->principals->actor($actor);
        $id = $task instanceof Task ? $task->getKey() : $task;

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $changes, $expectedRevision, $id): Task {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $current->forceFill($this->values->replace($changes, $current));
            if ($current->isClean()) {
                return $current;
            }
            $current->revision++;
            $current->save();
            $this->activity->updated($current, $actor);

            return $current;
        });

        return $updated->refresh();
    }

    /** Delete one authorized task in the active ownership scope. */
    public function delete(Task|string $task, Authenticatable|SystemActor $actor): bool
    {
        $actor = $this->principals->actor($actor);
        $id = $task instanceof Task ? $task->getKey() : $task;

        $deleted = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $id): ?Task {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Delete, $actor, $current);

            if ($current->delete() !== true) {
                return null;
            }

            $this->activity->deleted($current, $actor);

            return $current;
        });

        return $deleted instanceof Task;
    }

    /** Restore a ownership-visible task only at its exact deleted revision. */
    public function restore(Task|string $task, int $expectedRevision, Authenticatable|SystemActor $actor): Task
    {
        $actor = $this->principals->actor($actor);
        $id = $task instanceof Task ? $task->getKey() : $task;

        $restored = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $id): Task {
            $current = $this->boundary->query(TaskModelRegistry::query()->withTrashed(), Task::TENANT_RESOURCE)
                ->whereKey($id)->whereNotNull('deleted_at')->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Restore, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $current->forceFill(['revision' => $current->revision + 1]);
            $current->restore();
            $this->activity->restored($current, $actor);

            return $current;
        });

        return $restored->refresh();
    }
}
