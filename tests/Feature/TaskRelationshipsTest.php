<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Actions\AddTaskDependencyAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\LinkTaskParentAction;
use Nvl\Tasks\Actions\RemoveTaskDependencyAction;
use Nvl\Tasks\Actions\UnlinkTaskParentAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;

function relationshipTask(string $title, ?string $tenantId = null): Task
{
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData($title), TaskActorData::system());

    if ($tenantId !== null) {
        $task->forceFill(['tenant_id' => $tenantId])->save();
    }

    return $task->refresh();
}

it('links and unlinks one parent while advancing both exact task revisions', function (): void {
    $child = relationshipTask('Child');
    $parent = relationshipTask('Parent');
    $actor = TaskActorData::system();

    $relation = app(LinkTaskParentAction::class)->execute($child, $parent, 1, 1, $actor);

    expect($relation)->toBeInstanceOf(TaskRelationship::class)
        ->and($relation->child_task_id)->toBe($child->id)
        ->and($relation->parent_task_id)->toBe($parent->id)
        ->and($child->fresh()?->revision)->toBe(2)
        ->and($parent->fresh()?->revision)->toBe(2);

    $same = app(LinkTaskParentAction::class)->execute($child, $parent, 2, 2, $actor);

    expect($same->id)->toBe($relation->id)
        ->and($child->fresh()?->revision)->toBe(2)
        ->and(app(UnlinkTaskParentAction::class)->execute($child, $parent, 2, 2, $actor))->toBeTrue()
        ->and(TaskRelationship::query()->count())->toBe(0)
        ->and($child->fresh()?->revision)->toBe(3)
        ->and($parent->fresh()?->revision)->toBe(3)
        ->and(app(UnlinkTaskParentAction::class)->execute($child, $parent, 3, 3, $actor))->toBeFalse();
});

it('requires unlinking before a child can acquire another parent', function (): void {
    $child = relationshipTask('Child');
    $first = relationshipTask('First');
    $second = relationshipTask('Second');
    $actor = TaskActorData::system();
    app(LinkTaskParentAction::class)->execute($child, $first, 1, 1, $actor);

    expect(fn () => app(LinkTaskParentAction::class)->execute($child, $second, 2, 1, $actor))
        ->toThrow(InvalidArgumentException::class);
    expect(TaskRelationship::query()->count())->toBe(1)
        ->and($child->fresh()?->revision)->toBe(2)
        ->and($second->fresh()?->revision)->toBe(1);
});

it('rejects self parent links and parent cycles', function (): void {
    $first = relationshipTask('First');
    $second = relationshipTask('Second');
    $third = relationshipTask('Third');
    $actor = TaskActorData::system();

    expect(fn () => app(LinkTaskParentAction::class)->execute($first, $first, 1, 1, $actor))
        ->toThrow(InvalidArgumentException::class);

    app(LinkTaskParentAction::class)->execute($first, $second, 1, 1, $actor);
    app(LinkTaskParentAction::class)->execute($second, $third, 2, 1, $actor);

    expect(fn () => app(LinkTaskParentAction::class)->execute($third, $first, 2, 2, $actor))
        ->toThrow(InvalidArgumentException::class);
    expect(TaskRelationship::query()->count())->toBe(2);
});

it('adds and removes blockers while rejecting dependency cycles', function (): void {
    $first = relationshipTask('First');
    $second = relationshipTask('Second');
    $third = relationshipTask('Third');
    $actor = TaskActorData::system();

    expect(fn () => app(AddTaskDependencyAction::class)->execute($first, $first, 1, 1, $actor))
        ->toThrow(InvalidArgumentException::class);

    $dependency = app(AddTaskDependencyAction::class)->execute($first, $second, 1, 1, $actor);
    $same = app(AddTaskDependencyAction::class)->execute($first, $second, 2, 2, $actor);
    app(AddTaskDependencyAction::class)->execute($second, $third, 2, 1, $actor);

    expect($dependency)->toBeInstanceOf(TaskDependency::class)
        ->and($dependency->task_id)->toBe($first->id)
        ->and($dependency->blocker_task_id)->toBe($second->id)
        ->and($same->id)->toBe($dependency->id)
        ->and(fn () => app(AddTaskDependencyAction::class)->execute($third, $first, 2, 2, $actor))
        ->toThrow(InvalidArgumentException::class);

    expect(app(RemoveTaskDependencyAction::class)->execute($first, $second, 2, 3, $actor))->toBeTrue()
        ->and(app(RemoveTaskDependencyAction::class)->execute($first, $second, 3, 4, $actor))->toBeFalse()
        ->and(TaskDependency::query()->count())->toBe(1);
});

it('rejects stale revisions and tasks from different tenants', function (): void {
    $tenantA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $tenantB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $child = relationshipTask('Tenant A', $tenantA);
    $parent = relationshipTask('Tenant B', $tenantB);
    $actor = TaskActorData::system();

    expect(fn () => app(LinkTaskParentAction::class)->execute($child, $parent, 1, 1, $actor))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(AddTaskDependencyAction::class)->execute($child, $parent, 1, 1, $actor))
        ->toThrow(InvalidArgumentException::class);

    $parent->forceFill(['tenant_id' => $tenantA])->save();
    $parent->refresh();

    expect(fn () => app(LinkTaskParentAction::class)->execute($child, $parent, 0, 1, $actor))
        ->toThrow(TaskRevisionConflict::class)
        ->and(fn () => app(AddTaskDependencyAction::class)->execute($child, $parent, 1, 0, $actor))
        ->toThrow(TaskRevisionConflict::class);
    expect(TaskRelationship::query()->count())->toBe(0)
        ->and(TaskDependency::query()->count())->toBe(0);
});

it('authorizes updates to both canonical endpoints and ignores forged model attributes', function (): void {
    $child = relationshipTask('Child');
    $parent = relationshipTask('Parent');
    $actor = TaskActorData::system();
    $authorized = [];
    app()->instance(TaskAuthorization::class, new class($authorized) implements TaskAuthorization
    {
        /** @param list<string> $authorized */
        public function __construct(private array &$authorized) {}

        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void
        {
            $this->authorized[] = $task?->id;

            if ($ability !== TaskAbility::Update) {
                throw new AuthorizationException;
            }
        }
    });

    $forged = clone $parent;
    $forged->forceFill(['revision' => 99, 'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);

    app(LinkTaskParentAction::class)->execute($child, $forged, 1, 1, $actor);

    expect($authorized)->toContain($child->id, $parent->id)
        ->and(TaskRelationship::query()->first()?->tenant_id)->toBeNull()
        ->and($parent->fresh()?->revision)->toBe(2);
});

it('rejects a graph change when either endpoint update is unauthorized', function (): void {
    $child = relationshipTask('Child');
    $parent = relationshipTask('Parent');
    app()->instance(TaskAuthorization::class, new class($parent->id) implements TaskAuthorization
    {
        public function __construct(private string $deniedTaskId) {}

        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if ($ability !== TaskAbility::Update || $task?->id === $this->deniedTaskId) {
                throw new AuthorizationException;
            }
        }
    });

    expect(fn () => app(LinkTaskParentAction::class)->execute($child, $parent, 1, 1, TaskActorData::system()))
        ->toThrow(AuthorizationException::class);
    expect(TaskRelationship::query()->count())->toBe(0)
        ->and($child->fresh()?->revision)->toBe(1)
        ->and($parent->fresh()?->revision)->toBe(1);
});

it('declares cascading task foreign keys for both graph tables', function (): void {
    $relationships = Schema::getForeignKeys((new TaskRelationship)->getTable());
    $dependencies = Schema::getForeignKeys((new TaskDependency)->getTable());

    expect($relationships)->toHaveCount(2)
        ->and($dependencies)->toHaveCount(2)
        ->and(collect($relationships)->pluck('columns')->all())->toContain(['child_task_id'], ['parent_task_id'])
        ->and(collect($dependencies)->pluck('columns')->all())->toContain(['task_id'], ['blocker_task_id'])
        ->and(collect([...$relationships, ...$dependencies])->every(
            static fn (array $foreignKey): bool => strtolower($foreignKey['on_delete']) === 'cascade',
        ))->toBeTrue();
});
