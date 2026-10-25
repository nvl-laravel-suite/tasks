<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskPrincipalGuard;
use Nvl\Tasks\Services\TaskTimeTracking;
use Nvl\Tasks\Support\TasksConfiguration;

function timeTrackingActor(string $name = 'Time keeper'): Authenticatable
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
        'password' => 'unused',
    ])->save();

    return $user;
}

function allowTaskTimeUpdates(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });
}

it('adds edits and removes manual time with server calculated durations and task revisions', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);

    $entry = app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z', 'description' => 'Review'], 1, $actor);

    expect($entry->duration_seconds)->toBe(3600)
        ->and($entry->performer_type)->toBe(TaskPrincipalGuard::type($actor))
        ->and($entry->performer_id)->toBe((string) $actor->id)
        ->and($task->fresh()?->revision)->toBe(2);

    $updated = app(TaskTimeTracking::class)->update($task, $entry, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T09:30:00Z', 'description' => 'Short review'], 2, $actor);

    expect($updated->duration_seconds)->toBe(1800)
        ->and($updated->description)->toBe('Short review')
        ->and($task->fresh()?->revision)->toBe(3);

    expect(app(TaskTimeTracking::class)->delete($task, $entry, 3, $actor))->toBeTrue()
        ->and(TaskTimeEntry::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(4);
});

it('runs only one timer per task performer and calculates elapsed time on stop', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);
    Carbon::setTestNow('2026-09-28 09:00:00 UTC');

    try {
        $entry = app(TaskTimeTracking::class)->startTimer($task, 1, $actor);

        expect($entry->stopped_at)->toBeNull()
            ->and($entry->duration_seconds)->toBeNull()
            ->and($task->fresh()?->revision)->toBe(2);

        expect(fn () => app(TaskTimeTracking::class)->startTimer($task, 2, $actor))
            ->toThrow(ValidationException::class);

        Carbon::setTestNow('2026-09-28 09:45:00 UTC');
        $stopped = app(TaskTimeTracking::class)->stopTimer($task, $entry, 2, $actor);

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
    $task = app(TaskLifecycle::class)->create(['title' => 'Track storage uniqueness'], $actor);
    $running = app(TaskTimeTracking::class)->startTimer($task, 1, $actor);
    $duplicate = new TaskTimeEntry;
    $duplicate->forceFill([
        'task_id' => $task->id,
        'performer_type' => TaskPrincipalGuard::type($actor),
        'performer_id' => (string) $actor->id,
        'started_at' => now(),
    ]);

    expect(fn () => DB::connection(TasksConfiguration::connection())
        ->transaction(fn () => $duplicate->save()))->toThrow(QueryException::class);

    $running->forceFill(['stopped_at' => now(), 'duration_seconds' => 0])->save();
    $next = app(TaskTimeTracking::class)->startTimer($task, 2, $actor);

    expect($next->id)->not->toBe($running->id)
        ->and(TaskTimeEntry::query()->whereNull('stopped_at')->count())->toBe(1);
});

it('rejects invalid manual durations and stale revisions without writes', function (): void {
    allowTaskTimeUpdates();
    $actor = timeTrackingActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);

    expect(fn () => app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T10:00:00Z', 'ended_at' => '2026-09-28T09:00:00Z'], 1, $actor))->toThrow(ValidationException::class);

    expect(fn () => app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-10-28T09:00:00Z'], 1, $actor))->toThrow(ValidationException::class);

    expect(fn () => app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z'], 2, $actor))->toThrow(TaskRevisionConflict::class);

    expect(TaskTimeEntry::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(1);
});

it('requires host update authorization before creating time', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], SystemActor::Background);
    $actor = timeTrackingActor();

    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    expect(fn () => app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z'], 1, $actor))->toThrow(BindingRequiredException::class);
});

it('keeps time entries bound to their canonical task and performer', function (): void {
    allowTaskTimeUpdates();
    $owner = timeTrackingActor('Owner');
    $other = timeTrackingActor('Other');
    $first = app(TaskLifecycle::class)->create(['title' => 'First'], $owner);
    $second = app(TaskLifecycle::class)->create(['title' => 'Second'], $owner);
    $entry = app(TaskTimeTracking::class)->add($first, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z'], 1, $owner);

    expect(fn () => app(TaskTimeTracking::class)->delete($second, $entry, 1, $owner))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => app(TaskTimeTracking::class)->delete($first, $entry, 2, $other))
        ->toThrow(ModelNotFoundException::class);

    expect(TaskTimeEntry::query()->count())->toBe(1)
        ->and($first->fresh()?->revision)->toBe(2)
        ->and($second->fresh()?->revision)->toBe(1);
});
