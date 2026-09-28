<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Starts one running timer for a task performer. */
final readonly class StartTaskTimerAction
{
    /** Construct the timer start workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Start a timer at server time if the performer has none running. */
    public function execute(Task|string $task, int $expectedRevision, TaskActorData $actor): TaskTimeEntry
    {
        if ($actor->system || $actor->type === null || $actor->id === null) {
            throw new InvalidArgumentException('Timers require a performer identity.');
        }

        $id = $task instanceof Task ? $task->getKey() : $task;

        $entry = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $id): TaskTimeEntry {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $running = TaskTimeEntry::query()->where('task_id', $current->id)
                ->where('performer_type', $actor->type)
                ->where('performer_id', (string) $actor->id)
                ->whereNull('stopped_at')->exists();

            if ($running) {
                throw ValidationException::withMessages(['timer' => 'This performer already has a running timer for the task.']);
            }

            $entry = new TaskTimeEntry;
            $entry->forceFill([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'performer_type' => $actor->type,
                'performer_id' => (string) $actor->id,
                'started_at' => CarbonImmutable::now(),
            ])->save();
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->timerStarted($current, $entry->id, $actor);

            return $entry->refresh();
        });

        return $entry;
    }
}
