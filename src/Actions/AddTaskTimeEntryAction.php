<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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

/** Adds a manual time interval for one authorized task performer. */
final readonly class AddTaskTimeEntryAction
{
    /** Construct the manual time-entry workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Store a server-calculated interval against an exact task revision. */
    public function execute(Task|string $task, TimeEntryData $data, TaskActorData $actor): TaskTimeEntry
    {
        if ($actor->system || $actor->type === null || $actor->id === null) {
            throw new InvalidArgumentException('Time entries require a performer identity.');
        }

        $id = $task instanceof Task ? $task->getKey() : $task;

        $entry = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $id): TaskTimeEntry {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $data->expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $entry = new TaskTimeEntry;
            $entry->forceFill([
                ...$data->interval(),
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'performer_type' => $actor->type,
                'performer_id' => (string) $actor->id,
            ])->save();
            $current->forceFill(['revision' => $current->revision + 1])->save();

            $durationSeconds = $entry->duration_seconds
                ?? throw new LogicException('A manual time entry must have a duration.');
            $this->activity->timeEntryAdded($current, $entry->id, $durationSeconds, $actor);

            return $entry->refresh();
        });

        return $entry;
    }
}
