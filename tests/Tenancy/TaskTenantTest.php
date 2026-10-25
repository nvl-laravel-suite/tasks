<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Services\TasksActivityDelivery;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Services\TaskTimeTracking;

function tasksOwner(int $key, Closure $callback): mixed
{
    return app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), Actor::query()->findOrFail(1), $callback);
}

it('isolates task creation and reads after tenant adoption', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $task = tasksOwner(7, fn () => app(TaskLifecycle::class)->create(['title' => 'Owner task'], $actor));
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
        {
            $query->where('title', 'Missing')->orWhere('title', 'Owner task');
        }
    });
    tasksOwner(8, function () use ($actor, $task): void {
        expect(app(TaskReader::class)->paginate([], $actor)->total())->toBe(0)
            ->and(app(TaskReader::class)->dashboard($actor)['total'])->toBe(0)
            ->and(fn () => app(TaskReader::class)->get($task, $actor))->toThrow(ModelNotFoundException::class);
    });
    $outboxId = tasksOwner(7, function () use ($task): string {
        $event = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
        $event->forceFill(['delivered_at' => null])->save();

        return $event->id;
    });
    expect(tasksOwner(8, fn () => app(TasksActivityDelivery::class)->deliver($outboxId)))->toBeFalse();
    expect(tasksOwner(7, fn () => app(TasksActivityDelivery::class)->deliver($outboxId)))->toBeTrue();
    tasksOwner(7, function () use ($actor): void {
        $forged = $actor->fresh()->forceFill(['id' => 999]);
        expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Forged'], $forged))->toThrow(InvalidArgumentException::class);
    });
});

it('attributes new task children and keeps inherited reads inside the active owner', function (): void {
    tasksOwner(7, function (): void {
        $actor = Actor::query()->findOrFail(1);
        $task = app(TaskLifecycle::class)->create(['title' => 'Owner'], $actor);
        $related = app(TaskLifecycle::class)->create(['title' => 'Related'], $actor);
        app(TaskAssignments::class)->assign($task, $actor, $actor);
        app(TaskChecklist::class)->add($task, 'Review', 2, $actor);
        app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-10-25T10:00:00Z', 'ended_at' => '2026-10-25T11:00:00Z'], 3, $actor);
        app(TaskTags::class)->add($task, 'review', 4, $actor);
        app(TaskRelationships::class)->linkParent($task, $related, 5, 1, $actor);
        app(TaskRelationships::class)->addDependency($task, $related, 6, 2, $actor);
    });
    foreach ([7 => 1, 8 => 0] as $owner => $count) {
        tasksOwner($owner, function () use ($count): void {
            foreach ([TaskAssignment::class, TaskChecklistItem::class, TaskTimeEntry::class, TaskTag::class, TaskRelationship::class, TaskDependency::class] as $model) {
                expect($model::query()->count())->toBe($count);
                if ($count === 1) {
                    $child = $model::query()->firstOrFail();
                    expect(fn () => $child->getConnection()->table($child->getTable())->where('id', $child->id)->update(['owner_scope_key' => 'forged']))->toThrow(QueryException::class);
                }
            }
        });
    }
});

it('captures ownership for new outbox rows and rejects immutable fact changes', function (): void {
    tasksOwner(7, function (): void {
        $task = app(TaskLifecycle::class)->create(['title' => 'Captured audit'], Actor::query()->findOrFail(1));
        $event = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
        expect($event->getAttribute('owner_scope_key'))->toBe($task->getAttribute('owner_scope_key'));
        expect(fn () => $event->getConnection()->table($event->getTable())->where('id', $event->id)->update(['owner_scope_key' => 'forged']))->toThrow(QueryException::class);
        expect(fn () => $event->getConnection()->table($event->getTable())->where('id', $event->id)->update(['payload' => '{}']))->toThrow(QueryException::class);
    });
    expect(tasksOwner(8, fn () => TaskActivityOutbox::query()->count()))->toBe(0);
});

it('requires explicit captured ownership for retained outbox rows whose task is gone', function (): void {
    $eventId = tasksOwner(7, function (): string {
        $task = app(TaskLifecycle::class)->create(['title' => 'Historical task'], Actor::query()->findOrFail(1));
        $event = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
        $task->forceDelete();
        $event->forceFill(['delivered_at' => null])->save();
        expect(app(TasksActivityDelivery::class)->deliver($event->id))->toBeTrue();

        return $event->id;
    });
    expect(tasksOwner(8, fn () => app(TasksActivityDelivery::class)->deliver($eventId)))->toBeFalse();
});
