<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Services\TaskGraphGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Adds a blocker that a task must wait for.
 *
 * @api
 */
final readonly class AddTaskDependencyAction
{
    /** Construct the blocker-addition workflow. */
    public function __construct(private TaskGraphGuard $guard, private TasksActivity $activity) {}

    /** Add one dependency after checking both canonical task revisions. */
    public function execute(
        Task|string $task,
        Task|string $blocker,
        int $expectedTaskRevision,
        int $expectedBlockerRevision,
        TaskActorData $actor,
    ): TaskDependency {
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
                if ($existing->tenant_id !== $canonicalTask->tenant_id) {
                    throw new InvalidArgumentException('The dependency belongs to another tenant.');
                }

                return $existing;
            }

            $this->guard->assertAcyclicDependency($canonicalTask, $canonicalBlocker);
            $dependency = new TaskDependency;
            $dependency->forceFill([
                'tenant_id' => $canonicalTask->tenant_id,
                'task_id' => $canonicalTask->id,
                'blocker_task_id' => $canonicalBlocker->id,
            ]);
            $dependency->save();
            $this->guard->advanceRevisions($canonicalTask, $canonicalBlocker);
            $this->activity->blockerAdded($canonicalTask, $canonicalBlocker->id, $actor);

            return $dependency->refresh();
        });

        return $dependency;
    }
}
