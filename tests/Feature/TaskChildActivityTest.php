<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Services\TaskTimeTracking;

/** @return list<string> */
function childActivityEvents(Task $task): array
{
    return ActivityLog::query()->where('subject_id', $task->id)->pluck('event')->all();
}

function childActivityActor(): Authenticatable
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill(['name' => 'Worker', 'email' => 'worker@example.test', 'password' => 'unused'])->save();

    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    return $user;
}

it('records meaningful checklist changes on the task', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Launch'], $actor);
    $first = app(TaskChecklist::class)->add($task, 'First', 1, $actor);
    $second = app(TaskChecklist::class)->add($task, 'Second', 2, $actor);
    app(TaskChecklist::class)->update($task, $first, 'Revised', 3, $actor);
    app(TaskChecklist::class)->toggle($task, $first, true, 4, $actor);
    app(TaskChecklist::class)->reorder($task, [$second->id, $first->id], 5, $actor);
    app(TaskChecklist::class)->remove($task, $first, 6, $actor);

    expect(childActivityEvents($task))->toEqualCanonicalizing([
        'created', 'checklist_item_added', 'checklist_item_added', 'checklist_item_updated',
        'checklist_item_completed', 'checklist_reordered', 'checklist_item_removed',
    ]);
});

it('records tag changes without duplicate events for idempotent calls', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Launch'], $actor);
    app(TaskTags::class)->add($task, 'Urgent', 1, $actor);
    app(TaskTags::class)->add($task, 'urgent', 2, $actor);
    app(TaskTags::class)->remove($task, 'URGENT', 2, $actor);
    app(TaskTags::class)->remove($task, 'urgent', 3, $actor);

    expect(childActivityEvents($task))->toEqualCanonicalizing(['created', 'tag_added', 'tag_removed']);
});

it('records manual time and timer transitions on the task', function (): void {
    $actor = childActivityActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Launch'], $actor);
    $entry = app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z'], 1, $actor);
    app(TaskTimeTracking::class)->update($task, $entry, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T09:30:00Z'], 2, $actor);
    app(TaskTimeTracking::class)->delete($task, $entry, 3, $actor);

    Carbon::setTestNow('2026-09-28 11:00:00 UTC');

    try {
        $timer = app(TaskTimeTracking::class)->startTimer($task, 4, $actor);
        Carbon::setTestNow('2026-09-28 11:15:00 UTC');
        app(TaskTimeTracking::class)->stopTimer($task, $timer, 5, $actor);
    } finally {
        Carbon::setTestNow();
    }

    expect(childActivityEvents($task))->toEqualCanonicalizing([
        'created', 'time_entry_added', 'time_entry_updated', 'time_entry_removed', 'timer_started', 'timer_stopped',
    ]);
});

it('records graph edges once on the primary task', function (): void {
    $actor = SystemActor::Background;
    $child = app(TaskLifecycle::class)->create(['title' => 'Child'], $actor);
    $parent = app(TaskLifecycle::class)->create(['title' => 'Parent'], $actor);
    $blocker = app(TaskLifecycle::class)->create(['title' => 'Blocker'], $actor);
    app(TaskRelationships::class)->linkParent($child, $parent, 1, 1, $actor);
    app(TaskRelationships::class)->linkParent($child, $parent, 2, 2, $actor);
    app(TaskRelationships::class)->unlinkParent($child, $parent, 2, 2, $actor);
    app(TaskRelationships::class)->unlinkParent($child, $parent, 3, 3, $actor);
    app(TaskRelationships::class)->addDependency($child, $blocker, 3, 1, $actor);
    app(TaskRelationships::class)->addDependency($child, $blocker, 4, 2, $actor);
    app(TaskRelationships::class)->removeDependency($child, $blocker, 4, 2, $actor);
    app(TaskRelationships::class)->removeDependency($child, $blocker, 5, 3, $actor);

    expect(childActivityEvents($child))->toEqualCanonicalizing([
        'created', 'parent_linked', 'parent_unlinked', 'blocker_added', 'blocker_removed',
    ])
        ->and(childActivityEvents($parent))->toBe(['created'])
        ->and(childActivityEvents($blocker))->toBe(['created']);
});
