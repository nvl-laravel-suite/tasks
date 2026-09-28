<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Actions\AddTaskTimeEntryAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskTimeEntryAction;
use Nvl\Tasks\Actions\StartTaskTimerAction;
use Nvl\Tasks\Actions\StopTaskTimerAction;
use Nvl\Tasks\Actions\UpdateTaskTimeEntryAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\TimeEntryData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Support\TasksConfiguration;

function timeTrackingActor(string $name = 'Time keeper'): TaskActorData
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
        'password' => 'unused',
    ])->save();

    return TaskActorData::fromAuthenticatable($user);
}

function allowTaskTimeUpdates(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });
}

it('adds edits and removes manual time with server calculated durations and task revisions', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor);

    $entry = app(AddTaskTimeEntryAction::class)->execute($task, new TimeEntryData(
        startedAt: '2026-09-28T09:00:00Z',
        endedAt: '2026-09-28T10:00:00Z',
        expectedRevision: 1,
        description: 'Review',
    ), $actor);

    expect($entry->duration_seconds)->toBe(3600)
        ->and($entry->performer_type)->toBe($actor->type)
        ->and($entry->performer_id)->toBe((string) $actor->id)
        ->and($task->fresh()?->revision)->toBe(2);

    $updated = app(UpdateTaskTimeEntryAction::class)->execute($task, $entry, new TimeEntryData(
        startedAt: '2026-09-28T09:00:00Z',
        endedAt: '2026-09-28T09:30:00Z',
        expectedRevision: 2,
        description: 'Short review',
    ), $actor);

    expect($updated->duration_seconds)->toBe(1800)
        ->and($updated->description)->toBe('Short review')
        ->and($task->fresh()?->revision)->toBe(3);

    expect(app(DeleteTaskTimeEntryAction::class)->execute($task, $entry, 3, $actor))->toBeTrue()
        ->and(TaskTimeEntry::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(4);
});

it('runs only one timer per task performer and calculates elapsed time on stop', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor);
    Carbon::setTestNow('2026-09-28 09:00:00 UTC');

    try {
        $entry = app(StartTaskTimerAction::class)->execute($task, 1, $actor);

        expect($entry->stopped_at)->toBeNull()
            ->and($entry->duration_seconds)->toBeNull()
            ->and($task->fresh()?->revision)->toBe(2);

        expect(fn () => app(StartTaskTimerAction::class)->execute($task, 2, $actor))
            ->toThrow(ValidationException::class);

        Carbon::setTestNow('2026-09-28 09:45:00 UTC');
        $stopped = app(StopTaskTimerAction::class)->execute($task, $entry, 2, $actor);

        expect($stopped->duration_seconds)->toBe(2700)
            ->and($stopped->stopped_at)->not->toBeNull()
            ->and($task->fresh()?->revision)->toBe(3);
    } finally {
        Carbon::setTestNow();
    }
});

it('enforces one running timer per task performer in storage', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Track storage uniqueness'), $actor);
    $running = app(StartTaskTimerAction::class)->execute($task, 1, $actor);
    $duplicate = new TaskTimeEntry;
    $duplicate->forceFill([
        'task_id' => $task->id,
        'performer_type' => $actor->type,
        'performer_id' => (string) $actor->id,
        'started_at' => now(),
    ]);

    expect(fn () => DB::connection(TasksConfiguration::connection())
        ->transaction(fn () => $duplicate->save()))->toThrow(QueryException::class);

    $running->forceFill(['stopped_at' => now(), 'duration_seconds' => 0])->save();
    $next = app(StartTaskTimerAction::class)->execute($task, 2, $actor);

    expect($next->id)->not->toBe($running->id)
        ->and(TaskTimeEntry::query()->whereNull('stopped_at')->count())->toBe(1);
});

it('rejects invalid manual durations and stale revisions without writes', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor);

    expect(fn () => app(AddTaskTimeEntryAction::class)->execute($task, new TimeEntryData(
        startedAt: '2026-09-28T10:00:00Z',
        endedAt: '2026-09-28T09:00:00Z',
        expectedRevision: 1,
    ), $actor))->toThrow(ValidationException::class);

    expect(fn () => app(AddTaskTimeEntryAction::class)->execute($task, new TimeEntryData(
        startedAt: '2026-09-28T09:00:00Z',
        endedAt: '2026-10-28T09:00:00Z',
        expectedRevision: 1,
    ), $actor))->toThrow(ValidationException::class);

    expect(fn () => app(AddTaskTimeEntryAction::class)->execute($task, new TimeEntryData(
        startedAt: '2026-09-28T09:00:00Z',
        endedAt: '2026-09-28T10:00:00Z',
        expectedRevision: 2,
    ), $actor))->toThrow(TaskRevisionConflict::class);

    expect(TaskTimeEntry::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(1);
});

it('requires host update authorization before creating time', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), TaskActorData::system());
    $actor = timeTrackingActor();

    expect(fn () => app(AddTaskTimeEntryAction::class)->execute($task, new TimeEntryData(
        startedAt: '2026-09-28T09:00:00Z',
        endedAt: '2026-09-28T10:00:00Z',
        expectedRevision: 1,
    ), $actor))->toThrow(AuthorizationException::class);
});

it('keeps time entries bound to their canonical task and performer', function (): void {
    allowTaskTimeUpdates();
    $owner = timeTrackingActor('Owner');
    $other = timeTrackingActor('Other');
    $first = app(CreateTaskAction::class)->execute(new CreateTaskData('First'), $owner);
    $second = app(CreateTaskAction::class)->execute(new CreateTaskData('Second'), $owner);
    $entry = app(AddTaskTimeEntryAction::class)->execute($first, new TimeEntryData(
        startedAt: '2026-09-28T09:00:00Z',
        endedAt: '2026-09-28T10:00:00Z',
        expectedRevision: 1,
    ), $owner);

    expect(fn () => app(DeleteTaskTimeEntryAction::class)->execute($second, $entry, 1, $owner))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => app(DeleteTaskTimeEntryAction::class)->execute($first, $entry, 2, $other))
        ->toThrow(ModelNotFoundException::class);

    expect(TaskTimeEntry::query()->count())->toBe(1)
        ->and($first->fresh()?->revision)->toBe(2)
        ->and($second->fresh()?->revision)->toBe(1);
});
