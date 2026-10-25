<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Support\TasksConfiguration;

/** Native TaskRelationships operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskRelationships
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskGraphGuard $guard, private TasksActivity $activity, private TaskRecordWriter $records, private TaskPrincipalGuard $principals) {}

    /** Link canonical tasks after checking both expected revisions. */
    public function linkParent(
        Task|string $child,
        Task|string $parent,
        int $expectedChildRevision,
        int $expectedParentRevision,
        Authenticatable|SystemActor $actor,
    ): TaskRelationship {
        $actor = $this->principals->actor($actor);
        $relationship = DB::connection(TasksConfiguration::connection())->transaction(function () use (
            $child, $parent, $expectedChildRevision, $expectedParentRevision, $actor,
        ): TaskRelationship {
            [$canonicalChild, $canonicalParent] = $this->guard->lockPair(
                $child, $parent, $expectedChildRevision, $expectedParentRevision, $actor,
            );
            $existing = TaskRelationship::query()->where('child_task_id', $canonicalChild->id)->first();

            if ($existing !== null) {
                if ($existing->parent_task_id !== $canonicalParent->id) {
                    throw new InvalidArgumentException('The child already has another parent.');
                }

                return $existing;
            }

            $this->guard->assertAcyclicParent($canonicalChild, $canonicalParent);
            $relationship = $this->records->relationship([
                'tenant_id' => $canonicalChild->tenant_id,
                'child_task_id' => $canonicalChild->id,
                'parent_task_id' => $canonicalParent->id,
            ]);
            $this->guard->advanceRevisions($canonicalChild, $canonicalParent);
            $this->activity->parentLinked($canonicalChild, $canonicalParent->id, $actor);

            return $relationship->refresh();
        });

        return $relationship;
    }

    /** Remove a canonical parent edge after checking both expected revisions. */
    public function unlinkParent(
        Task|string $child,
        Task|string $parent,
        int $expectedChildRevision,
        int $expectedParentRevision,
        Authenticatable|SystemActor $actor,
    ): bool {
        $actor = $this->principals->actor($actor);
        $parentId = $parent instanceof Task ? $parent->id : $parent;
        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use (
            $child, $parent, $parentId, $expectedChildRevision, $expectedParentRevision, $actor,
        ): ?Task {
            [$canonicalChild, $canonicalParent] = $this->guard->lockPair(
                $child, $parent, $expectedChildRevision, $expectedParentRevision, $actor,
            );
            $relationship = TaskRelationship::query()
                ->where('child_task_id', $canonicalChild->id)
                ->where('parent_task_id', $canonicalParent->id)
                ->first();

            if ($relationship === null) {
                return null;
            }

            $relationship->delete();
            $this->guard->advanceRevisions($canonicalChild, $canonicalParent);
            $this->activity->parentUnlinked($canonicalChild, $parentId, $actor);

            return $canonicalChild;
        });

        return $changedTask instanceof Task;
    }

    /** Add one dependency after checking both canonical task revisions. */
    public function addDependency(
        Task|string $task,
        Task|string $blocker,
        int $expectedTaskRevision,
        int $expectedBlockerRevision,
        Authenticatable|SystemActor $actor,
    ): TaskDependency {
        $actor = $this->principals->actor($actor);
        $dependency = DB::connection(TasksConfiguration::connection())->transaction(function () use (
            $task, $blocker, $expectedTaskRevision, $expectedBlockerRevision, $actor,
        ): TaskDependency {
            [$canonicalTask, $canonicalBlocker] = $this->guard->lockPair(
                $task, $blocker, $expectedTaskRevision, $expectedBlockerRevision, $actor,
            );
            $existing = TaskDependency::query()
                ->where('task_id', $canonicalTask->id)
                ->where('blocker_task_id', $canonicalBlocker->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->guard->assertAcyclicDependency($canonicalTask, $canonicalBlocker);
            $dependency = $this->records->dependency([
                'tenant_id' => $canonicalTask->tenant_id,
                'task_id' => $canonicalTask->id,
                'blocker_task_id' => $canonicalBlocker->id,
            ]);
            $this->guard->advanceRevisions($canonicalTask, $canonicalBlocker);
            $this->activity->blockerAdded($canonicalTask, $canonicalBlocker->id, $actor);

            return $dependency->refresh();
        });

        return $dependency;
    }

    /** Remove one dependency after checking both canonical task revisions. */
    public function removeDependency(
        Task|string $task,
        Task|string $blocker,
        int $expectedTaskRevision,
        int $expectedBlockerRevision,
        Authenticatable|SystemActor $actor,
    ): bool {
        $actor = $this->principals->actor($actor);
        $blockerId = $blocker instanceof Task ? $blocker->id : $blocker;
        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use (
            $task, $blocker, $blockerId, $expectedTaskRevision, $expectedBlockerRevision, $actor,
        ): ?Task {
            [$canonicalTask, $canonicalBlocker] = $this->guard->lockPair(
                $task, $blocker, $expectedTaskRevision, $expectedBlockerRevision, $actor,
            );
            $dependency = TaskDependency::query()
                ->where('task_id', $canonicalTask->id)
                ->where('blocker_task_id', $canonicalBlocker->id)
                ->first();

            if ($dependency === null) {
                return null;
            }

            $dependency->delete();
            $this->guard->advanceRevisions($canonicalTask, $canonicalBlocker);
            $this->activity->blockerRemoved($canonicalTask, $blockerId, $actor);

            return $canonicalTask;
        });

        return $changedTask instanceof Task;
    }
}
