<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskRelationships;

function relationshipTask(string $title, ?string $tenantId = null): Task
{
    $task = app(TaskLifecycle::class)->create(['title' => $title], SystemActor::Background);

    if ($tenantId !== null) {
        $task->forceFill(['tenant_id' => $tenantId])->save();
    }

    return $task->refresh();
}

it('links and unlinks one parent while advancing both exact task revisions', function (): void {
    $child = relationshipTask('Child');
    $parent = relationshipTask('Parent');
    $actor = SystemActor::Background;

    $relation = app(TaskRelationships::class)->linkParent($child, $parent, 1, 1, $actor);

    expect($relation)->toBeInstanceOf(TaskRelationship::class)
        ->and($relation->child_task_id)->toBe($child->id)
        ->and($relation->parent_task_id)->toBe($parent->id)
        ->and($child->fresh()?->revision)->toBe(2)
        ->and($parent->fresh()?->revision)->toBe(2);

    $same = app(TaskRelationships::class)->linkParent($child, $parent, 2, 2, $actor);

    expect($same->id)->toBe($relation->id)
        ->and($child->fresh()?->revision)->toBe(2)
        ->and(app(TaskRelationships::class)->unlinkParent($child, $parent, 2, 2, $actor))->toBeTrue()
        ->and(TaskRelationship::query()->count())->toBe(0)
        ->and($child->fresh()?->revision)->toBe(3)
        ->and($parent->fresh()?->revision)->toBe(3)
        ->and(app(TaskRelationships::class)->unlinkParent($child, $parent, 3, 3, $actor))->toBeFalse();
});

it('requires unlinking before a child can acquire another parent', function (): void {
    $child = relationshipTask('Child');
    $first = relationshipTask('First');
    $second = relationshipTask('Second');
    $actor = SystemActor::Background;
    app(TaskRelationships::class)->linkParent($child, $first, 1, 1, $actor);

    expect(fn () => app(TaskRelationships::class)->linkParent($child, $second, 2, 1, $actor))
        ->toThrow(InvalidArgumentException::class);
    expect(TaskRelationship::query()->count())->toBe(1)
        ->and($child->fresh()?->revision)->toBe(2)
        ->and($second->fresh()?->revision)->toBe(1);
});

it('rejects self parent links and parent cycles', function (): void {
    $first = relationshipTask('First');
    $second = relationshipTask('Second');
    $third = relationshipTask('Third');
    $actor = SystemActor::Background;

    expect(fn () => app(TaskRelationships::class)->linkParent($first, $first, 1, 1, $actor))
        ->toThrow(InvalidArgumentException::class);

    app(TaskRelationships::class)->linkParent($first, $second, 1, 1, $actor);
    app(TaskRelationships::class)->linkParent($second, $third, 2, 1, $actor);

    expect(fn () => app(TaskRelationships::class)->linkParent($third, $first, 2, 2, $actor))
        ->toThrow(InvalidArgumentException::class);
    expect(TaskRelationship::query()->count())->toBe(2);
});

it('adds and removes blockers while rejecting dependency cycles', function (): void {
    $first = relationshipTask('First');
    $second = relationshipTask('Second');
    $third = relationshipTask('Third');
    $actor = SystemActor::Background;

    expect(fn () => app(TaskRelationships::class)->addDependency($first, $first, 1, 1, $actor))
        ->toThrow(InvalidArgumentException::class);

    $dependency = app(TaskRelationships::class)->addDependency($first, $second, 1, 1, $actor);
    $same = app(TaskRelationships::class)->addDependency($first, $second, 2, 2, $actor);
    app(TaskRelationships::class)->addDependency($second, $third, 2, 1, $actor);

    expect($dependency)->toBeInstanceOf(TaskDependency::class)
        ->and($dependency->task_id)->toBe($first->id)
        ->and($dependency->blocker_task_id)->toBe($second->id)
        ->and($same->id)->toBe($dependency->id)
        ->and(fn () => app(TaskRelationships::class)->addDependency($third, $first, 2, 2, $actor))
        ->toThrow(InvalidArgumentException::class);

    expect(app(TaskRelationships::class)->removeDependency($first, $second, 2, 3, $actor))->toBeTrue()
        ->and(app(TaskRelationships::class)->removeDependency($first, $second, 3, 4, $actor))->toBeFalse()
        ->and(TaskDependency::query()->count())->toBe(1);
});

it('rejects stale revisions independently of retained legacy tenant fields', function (): void {
    $tenantA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $tenantB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $child = relationshipTask('Tenant A', $tenantA);
    $parent = relationshipTask('Tenant B', $tenantB);
    $actor = SystemActor::Background;

    expect(fn () => app(TaskRelationships::class)->linkParent($child, $parent, 0, 1, $actor))
        ->toThrow(TaskRevisionConflict::class)
        ->and(fn () => app(TaskRelationships::class)->addDependency($child, $parent, 1, 0, $actor))
        ->toThrow(TaskRevisionConflict::class);
    expect(TaskRelationship::query()->count())->toBe(0)
        ->and(TaskDependency::query()->count())->toBe(0);
});

it('authorizes updates to both canonical endpoints and ignores forged model attributes', function (): void {
    $child = relationshipTask('Child');
    $parent = relationshipTask('Parent');
    $actor = SystemActor::Background;
    $authorized = [];
    app()->instance(TaskAuthorization::class, new class($authorized) implements TaskAuthorization
    {
        /** @param list<string> $authorized */
        public function __construct(private array &$authorized) {}

        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            $this->authorized[] = $task?->id;

            if ($ability !== TaskAbility::Update) {
                throw new AuthorizationException;
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    $forged = clone $parent;
    $forged->forceFill(['revision' => 99, 'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);

    app(TaskRelationships::class)->linkParent($child, $forged, 1, 1, $actor);

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

        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if ($ability !== TaskAbility::Update || $task?->id === $this->deniedTaskId) {
                throw new AuthorizationException;
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    expect(fn () => app(TaskRelationships::class)->linkParent($child, $parent, 1, 1, SystemActor::Background))
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

it('retains cycle protection through soft-deleted intermediate tasks and restore', function (): void {
    $actor = nativeTaskUser();
    $lifecycle = app(TaskLifecycle::class);
    $graphs = app(TaskRelationships::class);
    $a = $lifecycle->create(['title' => 'A'], $actor);
    $b = $lifecycle->create(['title' => 'B'], $actor);
    $c = $lifecycle->create(['title' => 'C'], $actor);
    $graphs->linkParent($a, $b, 1, 1, $actor);
    $graphs->linkParent($b, $c, 2, 1, $actor);
    $lifecycle->delete($b, $actor);
    expect(fn () => $graphs->linkParent($c, $a, 2, 2, $actor))->toThrow(InvalidArgumentException::class);
    $restored = $lifecycle->restore($b, 3, $actor);
    expect($restored->trashed())->toBeFalse();
    expect(fn () => $graphs->linkParent($c, $a, 2, 2, $actor))->toThrow(InvalidArgumentException::class);
});
