<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskLifecycle;

it('requires a native lifecycle capability rather than a transport action', function (): void {
    expect(class_exists(TaskLifecycle::class))->toBeTrue();
});

it('denies background authority unless the host explicitly authorizes it', function (): void {
    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Denied'], SystemActor::Background))
        ->toThrow(BindingRequiredException::class);
    expect(Task::query()->count())->toBe(0);
});

it('preserves omitted values and clears explicit nullable values through native mutations', function (): void {
    nativeTaskPolicy();
    $actor = nativeTaskUser();
    $service = app(TaskLifecycle::class);
    $task = $service->create(['title' => '  Native  ', 'due_at' => new DateTimeImmutable('2026-10-25T12:00:00+03:00'), 'metadata' => ['ExactCase' => true]], $actor);
    $updated = $service->update($task, ['description' => 'Changed'], 1, $actor);
    expect($updated->title)->toBe('Native')->and($updated->due_at->getTimestamp())->toBe((new DateTimeImmutable('2026-10-25T09:00:00Z'))->getTimestamp())
        ->and($updated->metadata)->toBe(['ExactCase' => true]);
    $cleared = $service->update($updated, ['due_at' => null], 2, $actor);
    expect($cleared->due_at)->toBeNull()->and($cleared->description)->toBe('Changed');
    expect(fn () => $service->update($task, ['title' => 'Stale'], 1, $actor))->toThrow(TaskRevisionConflict::class);
});

it('rejects unknown and protected native mutation fields', function (string $key): void {
    nativeTaskPolicy();
    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Unsafe', $key => 1], nativeTaskUser()))->toThrow(ValidationException::class);
    expect(Task::query()->count())->toBe(0);
})->with(['id', 'tenant_id', 'company_id', 'owner_scope_key', 'creator_id', 'actor', 'revision', 'expected_revision', 'unknown']);

it('rejects nonpersisted principals even when a host policy permits them', function (): void {
    nativeTaskPolicy();
    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Unsafe'], new User))->toThrow(InvalidArgumentException::class);
    expect(Task::query()->count())->toBe(0);
});

it('validates final schedules and exact scalar types on direct calls', function (): void {
    nativeTaskPolicy();
    $actor = nativeTaskUser();
    $service = app(TaskLifecycle::class);
    $task = $service->create(['title' => 'Schedule', 'target_at' => '2026-10-25T10:00:00Z', 'due_at' => '2026-10-26T10:00:00Z'], $actor);
    foreach ([['due_at' => '2026-10-24'], ['estimated_seconds' => '10'], ['title' => null], ['metadata' => null], ['due_at' => '2026-02-30']] as $changes) {
        expect(fn () => $service->update($task, $changes, 1, $actor))->toThrow(ValidationException::class);
    }
    expect($task->fresh()->revision)->toBe(1);
});

it('preserves date instants when the host timezone differs from storage UTC', function (): void {
    $prior = date_default_timezone_get();
    date_default_timezone_set('Europe/Sofia');
    config(['app.timezone' => 'Europe/Sofia']);
    try {
        $actor = nativeTaskUser('Timezone');
        $task = app(TaskLifecycle::class)->create(['title' => 'Instant', 'due_at' => new DateTimeImmutable('2026-10-25T12:00:00+03:00')], $actor);
        expect($task->due_at->getTimestamp())->toBe((new DateTimeImmutable('2026-10-25T09:00:00Z'))->getTimestamp());
    } finally {
        date_default_timezone_set($prior);
    }
});

it('authorizes assignment removal against the persisted target rather than dirty caller attributes', function (): void {
    $actor = nativeTaskUser('Operator');
    $assignee = nativeTaskUser('Restricted');
    $task = app(TaskLifecycle::class)->create(['title' => 'Canonical target'], $actor);
    app(TaskAssignments::class)->assign($task, $assignee, $actor);
    $assignee->forceFill(['name' => 'Allowed']);
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if ($subject?->getAttribute('name') !== 'Allowed') {
                throw new AuthorizationException;
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });
    expect(fn () => app(TaskAssignments::class)->unassign($task, $assignee, $actor))->toThrow(AuthorizationException::class);
    expect($task->assignments()->count())->toBe(1)->and($task->fresh()->revision)->toBe(2);
});
