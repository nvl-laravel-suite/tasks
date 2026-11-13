<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskPrincipalGuard;
use Nvl\Tasks\Services\TaskReader;

it('aggregates statuses, firm deadlines, assignments, estimates and logged time', function (): void {
    config()->set('nvl-tasks.dashboard.due_soon_days', 3);
    $actor = SystemActor::Background;
    $overdue = new Task;
    $overdue->forceFill(['title' => 'Overdue', 'status' => TaskStatus::Open->value, 'due_at' => now()->subDay(), 'estimated_seconds' => 3600])->save();
    $soon = new Task;
    $soon->forceFill(['title' => 'Soon', 'status' => TaskStatus::InProgress->value, 'due_at' => now()->addDays(2), 'estimated_seconds' => 1800])->save();
    $blocked = new Task;
    $blocked->forceFill(['title' => 'Blocked', 'status' => TaskStatus::Blocked->value, 'due_at' => now()->addDays(10)])->save();
    $completed = new Task;
    $completed->forceFill(['title' => 'Completed', 'status' => TaskStatus::Completed->value, 'due_at' => now()->subDay(), 'estimated_seconds' => 600])->save();
    $cancelled = new Task;
    $cancelled->forceFill(['title' => 'Cancelled', 'status' => TaskStatus::Cancelled->value, 'due_at' => now()->addDay(), 'estimated_seconds' => 300])->save();

    $assignment = new TaskAssignment;
    $assignment->forceFill(['task_id' => $soon->id, 'assignee_type' => 'test-user', 'assignee_id' => '1'])->save();

    foreach ([[$overdue, 900], [$soon, 300], [$completed, 200], [$soon, null]] as [$task, $seconds]) {
        $entry = new TaskTimeEntry;
        $entry->forceFill([
            'task_id' => $task->id,
            'performer_type' => 'test-user',
            'performer_id' => (string) ($seconds ?? 999),
            'started_at' => now()->subHour(),
            'stopped_at' => $seconds === null ? null : now(),
            'duration_seconds' => $seconds,
        ])->save();
    }

    $dashboard = app(TaskReader::class)->dashboard($actor);

    expect($dashboard['total'])->toBe(5)
        ->and($dashboard['open'])->toBe(1)
        ->and($dashboard['in_progress'])->toBe(1)
        ->and($dashboard['blocked'])->toBe(1)
        ->and($dashboard['completed'])->toBe(1)
        ->and($dashboard['overdue'])->toBe(1)
        ->and($dashboard['due_soon'])->toBe(1)
        ->and($dashboard['due_soon_days'])->toBe(3)
        ->and($dashboard['unassigned'])->toBe(4)
        ->and($dashboard['estimated_seconds'])->toBe(6300)
        ->and($dashboard['logged_seconds'])->toBe(1400)
        ->and(app(TaskReader::class)->dashboard($actor, 1)['due_soon'])->toBe(0);
});

it('uses the host list policy and scoped task visibility for every aggregate', function (): void {
    $owner = nativeTaskUser('Owner');
    $other = nativeTaskUser('Other');
    $own = new Task;
    $own->forceFill(['title' => 'Own', 'creator_type' => TaskPrincipalGuard::type($owner), 'creator_id' => $owner->id, 'estimated_seconds' => 100])->save();
    $foreign = new Task;
    $foreign->forceFill(['title' => 'Foreign', 'creator_type' => TaskPrincipalGuard::type($other), 'creator_id' => $other->id, 'estimated_seconds' => 900])->save();
    $entry = new TaskTimeEntry;
    $entry->forceFill(['task_id' => $foreign->id, 'performer_type' => 'test-user', 'performer_id' => 'other', 'started_at' => now()->subMinute(), 'stopped_at' => now(), 'duration_seconds' => 450])->save();

    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    expect(fn () => app(TaskReader::class)->dashboard($owner))->toThrow(BindingRequiredException::class);

    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if ($ability !== TaskAbility::List) {
                throw new AuthorizationException;
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
        {
            $query->where('creator_type', TaskPrincipalGuard::type($actor))->where('creator_id', (string) $actor->id);
        }
    });

    $dashboard = app(TaskReader::class)->dashboard($owner);

    expect($dashboard['total'])->toBe(1)
        ->and($dashboard['open'])->toBe(1)
        ->and($dashboard['estimated_seconds'])->toBe(100)
        ->and($dashboard['logged_seconds'])->toBe(0)
        ->and($dashboard['unassigned'])->toBe(1);
});

it('uses configured lifecycle values and a fixed number of aggregate queries', function (): void {
    config()->set([
        'nvl-tasks.defaults.status' => TaskStatus::Blocked->value,
        'nvl-tasks.dashboard.statuses.in_progress' => TaskStatus::Open->value,
        'nvl-tasks.dashboard.statuses.blocked' => TaskStatus::InProgress->value,
        'nvl-tasks.lifecycle.completed_status' => TaskStatus::Cancelled->value,
    ]);
    foreach ([TaskStatus::Blocked->value, TaskStatus::Open->value, TaskStatus::Cancelled->value] as $status) {
        $task = new Task;
        $task->forceFill(['title' => $status, 'status' => $status])->save();
    }

    $actor = SystemActor::Background;
    app(TaskReader::class)->dashboard($actor);
    $connection = DB::connection();
    $connection->enableQueryLog();

    try {
        $connection->flushQueryLog();
        $small = app(TaskReader::class)->dashboard($actor);
        $smallQueries = count(array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'COALESCE(') || str_contains($query['query'], 'sum(')));

        foreach (range(1, 20) as $number) {
            $task = new Task;
            $task->forceFill(['title' => "Extra {$number}", 'status' => TaskStatus::Blocked->value])->save();
        }

        $connection->flushQueryLog();
        $large = app(TaskReader::class)->dashboard($actor);
        $largeQueries = count(array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'COALESCE(') || str_contains($query['query'], 'sum(')));
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($small['total'])->toBe(3)
        ->and($small['open'])->toBe(1)
        ->and($small['in_progress'])->toBe(1)
        ->and($small['completed'])->toBe(1)
        ->and($large['total'])->toBe(23)
        ->and($large['open'])->toBe(21)
        ->and($smallQueries)->toBe($largeQueries)
        ->toBeLessThanOrEqual(2)
        ->and(fn () => app(TaskReader::class)->dashboard($actor, 91))->toThrow(InvalidArgumentException::class);
});

it('returns zero aggregates when no tasks are visible', function (): void {
    $dashboard = app(TaskReader::class)->dashboard(SystemActor::Background);

    expect($dashboard['total'])->toBe(0)
        ->and($dashboard['open'])->toBe(0)
        ->and($dashboard['overdue'])->toBe(0)
        ->and($dashboard['unassigned'])->toBe(0)
        ->and($dashboard['estimated_seconds'])->toBe(0)
        ->and($dashboard['logged_seconds'])->toBe(0);
});

it('qualifies dashboard columns when the host task scope joins another table containing overlapping columns', function (): void {
    $owner = nativeTaskUser('JoinedOwner');
    $task = new Task;
    $task->forceFill(['title' => 'Scoped Join', 'creator_type' => TaskPrincipalGuard::type($owner), 'creator_id' => $owner->id, 'estimated_seconds' => 300])->save();

    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
        {
            $taskTable = $query->getModel()->getTable();
            $subquery = DB::query()->selectRaw('? as linked_task_id, ? as status, 0 as estimated_seconds', ['keep', 'shadow']);
            $query->leftJoinSub($subquery, 'joined_scope_meta', function ($join) use ($taskTable): void {
                $join->on($taskTable.'.status', '!=', 'joined_scope_meta.status');
            });
        }
    });

    $dashboard = app(TaskReader::class)->dashboard($owner);

    expect($dashboard['total'])->toBe(1)
        ->and($dashboard['open'])->toBe(1)
        ->and($dashboard['unassigned'])->toBe(1)
        ->and($dashboard['estimated_seconds'])->toBe(300);
});
