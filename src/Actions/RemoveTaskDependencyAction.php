<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Contracts\RemoveTaskDependencyContract;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Services\TaskGraphGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Removes one blocker edge from a task.
 *
 * @api
 */
final readonly class RemoveTaskDependencyAction implements RemoveTaskDependencyContract
{
    /** Construct the blocker-removal workflow. */
    public function __construct(private TaskGraphGuard $guard, private TasksActivity $activity) {}

    /** Remove one dependency after checking both canonical task revisions. */
    public function execute(
        Task|string $task,
        Task|string $blocker,
        int $expectedTaskRevision,
        int $expectedBlockerRevision,
        TaskActorData $actor,
    ): bool {
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
                ->where('tenant_id', $canonicalTask->tenant_id)
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
