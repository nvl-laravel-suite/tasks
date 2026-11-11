<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskIdentifier;

/**
 * Applies ownership and actor visibility before protected reads and aggregates.
 *
 * @internal
 */
final readonly class TaskReadGuard
{
    /** Retain the policy and fresh ownership boundary. */
    public function __construct(private TaskAuthorization $authorization, private OwnershipBoundary $boundary) {}

    /** Construct mandatory grouped visibility for model reads.
     * @return Builder<Task>
     */
    public function query(Authenticatable|SystemActor $actor): Builder
    {
        $query = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE);
        $query->where(function (Builder $visibility) use ($actor): void {
            $this->authorization->scopeTasks($visibility, $actor);
        });

        return $query;
    }

    /** Return one canonical task only after both visibility and View authorization. */
    public function resolve(Task|string $task, Authenticatable|SystemActor $actor): Task
    {
        $id = TaskIdentifier::of($task, Task::class);
        $current = $this->query($actor)->withCount('assignments')->whereKey($id)->firstOrFail();
        $this->authorization->authorize(TaskAbility::View, $actor, $current);

        return $current;
    }
}
