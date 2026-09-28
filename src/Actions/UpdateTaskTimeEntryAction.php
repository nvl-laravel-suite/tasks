<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\TimeEntryData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Replaces a performer's manual interval under the canonical task boundary. */
final readonly class UpdateTaskTimeEntryAction
{
    /** Construct the manual interval replacement workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Update one manual entry and advance the exact task revision. */
    public function execute(Task|string $task, TaskTimeEntry|string $entry, TimeEntryData $data, TaskActorData $actor): TaskTimeEntry
    {
        $taskId = $task instanceof Task ? $task->getKey() : $task;
        $entryId = $entry instanceof TaskTimeEntry ? $entry->getKey() : $entry;

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $entryId, $taskId): TaskTimeEntry {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $data->expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $timeEntry = TaskTimeEntry::query()->whereKey($entryId)
                ->where('task_id', $current->id)
                ->where('performer_type', $actor->type)
                ->where('performer_id', (string) $actor->id)
                ->whereNotNull('stopped_at')
                ->firstOrFail();
            $timeEntry->forceFill($data->interval())->save();
            $current->forceFill(['revision' => $current->revision + 1])->save();

            if ($timeEntry->wasChanged(['started_at', 'stopped_at', 'duration_seconds', 'description'])) {
                $durationSeconds = $timeEntry->duration_seconds
                    ?? throw new LogicException('A manual time entry must have a duration.');
                $this->activity->timeEntryUpdated($current, $timeEntry->id, $durationSeconds, $actor);
            }

            return $timeEntry;
        });

        return $updated->refresh();
    }
}
