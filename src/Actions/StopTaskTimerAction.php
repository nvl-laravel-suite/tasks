<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Stops a performer's running timer with a server-calculated duration.
 *
 * @api
 */
final readonly class StopTaskTimerAction
{
    /** Construct the timer stop workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Stop one running timer and advance the exact task revision. */
    public function execute(Task|string $task, TaskTimeEntry|string $entry, int $expectedRevision, TaskActorData $actor): TaskTimeEntry
    {
        $taskId = $task instanceof Task ? $task->getKey() : $task;
        $entryId = $entry instanceof TaskTimeEntry ? $entry->getKey() : $entry;

        $stopped = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $entryId, $expectedRevision, $taskId): TaskTimeEntry {
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
                ->whereNull('stopped_at')
                ->firstOrFail();
            $stopped = CarbonImmutable::now();
            $seconds = $stopped->getTimestamp() - $timeEntry->started_at->getTimestamp();

            if ($seconds < 1 || $seconds > 604_800) {
                throw ValidationException::withMessages(['timer' => 'The timer must last between one second and seven days.']);
            }

            $timeEntry->forceFill([
                'stopped_at' => $stopped,
                'duration_seconds' => $seconds,
            ])->save();
            $current->forceFill(['revision' => $current->revision + 1])->save();

            $durationSeconds = $timeEntry->duration_seconds
                ?? throw new LogicException('A stopped timer must have a duration.');
            $this->activity->timerStopped($current, $timeEntry->id, $durationSeconds, $actor);

            return $timeEntry->refresh();
        });

        return $stopped;
    }
}
