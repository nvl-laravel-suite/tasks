<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Native TaskTimeTracking operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskTimeTracking
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskAuthorization $authorization, private OwnershipBoundary $boundary, private TasksActivity $activity, private TaskRecordWriter $records, private TaskPrincipalGuard $principals) {}

    /** Store a server-calculated interval against an exact task revision.
     * @param  array{started_at: \DateTimeInterface|string, ended_at: \DateTimeInterface|string, description?: string|null}  $attributes
     */
    public function add(Task|string $task, array $attributes, int $expectedRevision, Authenticatable|SystemActor $actor): TaskTimeEntry
    {
        $actor = $this->principals->actor($actor);
        if (($actor instanceof SystemActor) || TaskPrincipalGuard::type($actor) === null || TaskPrincipalGuard::id($actor) === null) {
            throw new InvalidArgumentException('Time entries require a performer identity.');
        }

        $id = $task instanceof Task ? $task->getKey() : $task;

        $entry = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $attributes, $expectedRevision, $id): TaskTimeEntry {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $entry = $this->records->timeEntry([
                ...$this->interval($attributes),
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'performer_type' => TaskPrincipalGuard::type($actor),
                'performer_id' => (string) TaskPrincipalGuard::id($actor),
            ]);
            $current->forceFill(['revision' => $current->revision + 1])->save();

            $durationSeconds = $entry->duration_seconds
                ?? throw new LogicException('A manual time entry must have a duration.');
            $this->activity->timeEntryAdded($current, $entry->id, $durationSeconds, $actor);

            return $entry->refresh();
        });

        return $entry;
    }

    /** Update one manual entry and advance the exact task revision.
     * @param  array{started_at: \DateTimeInterface|string, ended_at: \DateTimeInterface|string, description?: string|null}  $attributes
     */
    public function update(Task|string $task, TaskTimeEntry|string $entry, array $attributes, int $expectedRevision, Authenticatable|SystemActor $actor): TaskTimeEntry
    {
        $actor = $this->principals->actor($actor);
        $taskId = $task instanceof Task ? $task->getKey() : $task;
        $entryId = $entry instanceof TaskTimeEntry ? $entry->getKey() : $entry;

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $attributes, $expectedRevision, $entryId, $taskId): TaskTimeEntry {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $timeEntry = TaskTimeEntry::query()->whereKey($entryId)
                ->where('task_id', $current->id)
                ->where('performer_type', TaskPrincipalGuard::type($actor))
                ->where('performer_id', (string) TaskPrincipalGuard::id($actor))
                ->whereNotNull('stopped_at')
                ->firstOrFail();
            $timeEntry->forceFill($this->interval($attributes))->save();
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

    /** Remove one entry and advance the exact task revision. */
    public function delete(Task|string $task, TaskTimeEntry|string $entry, int $expectedRevision, Authenticatable|SystemActor $actor): bool
    {
        $actor = $this->principals->actor($actor);
        $taskId = $task instanceof Task ? $task->getKey() : $task;
        $entryId = $entry instanceof TaskTimeEntry ? $entry->id : $entry;

        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $entryId, $expectedRevision, $taskId): ?Task {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $timeEntry = TaskTimeEntry::query()->whereKey($entryId)
                ->where('task_id', $current->id)
                ->where('performer_type', TaskPrincipalGuard::type($actor))
                ->where('performer_id', (string) TaskPrincipalGuard::id($actor))
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

    /** Start a timer at server time if the performer has none running. */
    public function startTimer(Task|string $task, int $expectedRevision, Authenticatable|SystemActor $actor): TaskTimeEntry
    {
        $actor = $this->principals->actor($actor);
        if (($actor instanceof SystemActor) || TaskPrincipalGuard::type($actor) === null || TaskPrincipalGuard::id($actor) === null) {
            throw new InvalidArgumentException('Timers require a performer identity.');
        }

        $id = $task instanceof Task ? $task->getKey() : $task;

        $entry = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $id): TaskTimeEntry {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $running = TaskTimeEntry::query()->where('task_id', $current->id)
                ->where('performer_type', TaskPrincipalGuard::type($actor))
                ->where('performer_id', (string) TaskPrincipalGuard::id($actor))
                ->whereNull('stopped_at')->exists();

            if ($running) {
                throw ValidationException::withMessages(['timer' => 'This performer already has a running timer for the task.']);
            }

            $entry = $this->records->timeEntry([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'performer_type' => TaskPrincipalGuard::type($actor),
                'performer_id' => (string) TaskPrincipalGuard::id($actor),
                'started_at' => CarbonImmutable::now(),
            ]);
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->timerStarted($current, $entry->id, $actor);

            return $entry->refresh();
        });

        return $entry;
    }

    /** Stop one running timer and advance the exact task revision. */
    public function stopTimer(Task|string $task, TaskTimeEntry|string $entry, int $expectedRevision, Authenticatable|SystemActor $actor): TaskTimeEntry
    {
        $actor = $this->principals->actor($actor);
        $taskId = $task instanceof Task ? $task->getKey() : $task;
        $entryId = $entry instanceof TaskTimeEntry ? $entry->getKey() : $entry;

        $stopped = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $entryId, $expectedRevision, $taskId): TaskTimeEntry {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $timeEntry = TaskTimeEntry::query()->whereKey($entryId)
                ->where('task_id', $current->id)
                ->where('performer_type', TaskPrincipalGuard::type($actor))
                ->where('performer_id', (string) TaskPrincipalGuard::id($actor))
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

    /** Validate a complete manual interval and compute exact elapsed seconds.
     * @param  array<string, mixed>  $attributes
     * @return array{started_at: CarbonImmutable, stopped_at: CarbonImmutable, duration_seconds: int, description: string|null}
     */
    private function interval(array $attributes): array
    {
        TaskMutationValues::keys($attributes, ['started_at', 'ended_at', 'description']);
        $started = TaskMutationValues::date($attributes['started_at'] ?? null, 'started_at');
        $ended = TaskMutationValues::date($attributes['ended_at'] ?? null, 'ended_at');
        if ($started === null || $ended === null) {
            throw ValidationException::withMessages(['started_at' => 'Both interval endpoints are required.']);
        }
        $description = $attributes['description'] ?? null;
        if ($description !== null && (! is_string($description) || mb_strlen($description) > 2000)) {
            throw ValidationException::withMessages(['description' => 'Expected a string of at most 2000 characters.']);
        }
        $seconds = $ended->getTimestamp() - $started->getTimestamp();
        if ($seconds < 1 || $seconds > 604800) {
            throw ValidationException::withMessages(['ended_at' => 'The interval must last between one second and seven days.']);
        }

        return ['started_at' => $started, 'stopped_at' => $ended, 'duration_seconds' => $seconds, 'description' => $description];
    }
}
