<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/** Removes a performer's time entry from an authorized task. */
final readonly class DeleteTaskTimeEntryAction
{
    /** Construct the time-entry removal workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Remove one entry and advance the exact task revision. */
    public function execute(Task|string $task, TaskTimeEntry|string $entry, int $expectedRevision, TaskActorData $actor): bool
    {
        $taskId = $task instanceof Task ? $task->getKey() : $task;
        $entryId = $entry instanceof TaskTimeEntry ? $entry->id : $entry;

        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $entryId, $expectedRevision, $taskId): ?Task {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $timeEntry = TaskTimeEntry::query()->whereKey($entryId)
                ->where('task_id', $current->id)
                ->where('performer_type', $actor->type)
                ->where('performer_id', (string) $actor->id)
                ->firstOrFail();
            $deleted = $timeEntry->delete() === true;

            if ($deleted) {
                $current->forceFill(['revision' => $current->revision + 1])->save();
                $this->activity->timeEntryRemoved($current, $entryId, $actor);
            }

            return $deleted ? $current : null;
        });

        return $changedTask instanceof Task;
    }
}
