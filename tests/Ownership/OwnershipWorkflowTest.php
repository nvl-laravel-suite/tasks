<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Tasks\Enums\TaskChangeOperation;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskEvents;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Tests\Fixtures\OwnedTask;
use Nvl\Tasks\Tests\OwnerScopeTestCase;

uses(OwnerScopeTestCase::class);

it('creates and resolves the configured task only in its admitted native owner', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $task = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($actor) {
        $task = app(TaskLifecycle::class)->create(['title' => 'Owned task'], $actor);
        expect($task)->toBeInstanceOf(OwnedTask::class)->and($task->getAttribute('company_id'))->toBe(7);
        expect(app(TaskReader::class)->get($task, $actor)->id)->toBe($task->id);

        return $task;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($actor, $task): void {
        expect(fn () => app(TaskReader::class)->get($task, $actor))->toThrow(ModelNotFoundException::class);
    });
});

it('keeps task graph endpoints and assignments inside the canonical owner', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $identity = $actor;
    $runner = app(OwnershipRunner::class);
    $tasks = [];
    foreach ([7, 8] as $key) {
        $tasks[$key] = $runner->run(Company::query()->findOrFail($key), $actor, fn () => app(TaskLifecycle::class)->create(['title' => 'Task '.$key], $identity));
    }
    $runner->run(Company::query()->findOrFail(7), $actor, function () use ($actor, $identity, $tasks): void {
        expect(fn () => app(TaskRelationships::class)->linkParent($tasks[7], $tasks[8], 1, 1, $identity))->toThrow(ModelNotFoundException::class);
        $assignment = app(TaskAssignments::class)->assign($tasks[7], $actor, $identity);
        expect($assignment->task->id)->toBe($tasks[7]->id);
        expect(fn () => app(TaskAssignments::class)->assign($tasks[8], $actor, $identity))->toThrow(ModelNotFoundException::class);
    });
});

it('links historical and newly created tasks by native ownership rather than retained tenant fields', function (): void {
    $actor = Actor::query()->findOrFail(1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($actor): void {
        $identity = $actor;
        $historical = app(TaskLifecycle::class)->create(['title' => 'Historical'], $identity);
        $new = app(TaskLifecycle::class)->create(['title' => 'New'], $identity);
        $historical->getConnection()->table($historical->getTable())->where('id', $historical->id)->update(['tenant_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        $edge = app(TaskRelationships::class)->linkParent($historical, $new, 1, 1, $identity);
        expect($edge->child_task_id)->toBe($historical->id)->and($edge->parent_task_id)->toBe($new->id);
    });
});

it('rejects a forged or deleted assignee instead of persisting an unchecked principal reference', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $deleted = Actor::query()->create(['id' => 2, 'name' => 'Deleted']);
    Actor::query()->whereKey(2)->delete();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($actor, $deleted): void {
        $identity = $actor;
        $task = app(TaskLifecycle::class)->create(['title' => 'Canonical references'], $identity);
        $forged = $actor->fresh()->forceFill(['id' => 999]);
        expect(fn () => app(TaskAssignments::class)->assign($task, $forged, $identity))->toThrow(InvalidArgumentException::class);
        expect(fn () => app(TaskAssignments::class)->assign($task, $deleted, $identity))->toThrow(ModelNotFoundException::class);
    });
});

it('rejects foreign task parent and blocker edges at the raw database boundary', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $identity = $actor;
    $tasks = [];
    foreach ([7, 8] as $key) {
        $tasks[$key] = app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), $actor, fn () => app(TaskLifecycle::class)->create(['title' => 'Database '.$key], $identity));
    }
    $connection = $tasks[7]->getConnection();
    foreach ([
        'nvl_tasks_relationships' => ['child_task_id' => $tasks[7]->id, 'parent_task_id' => $tasks[8]->id],
        'nvl_tasks_dependencies' => ['task_id' => $tasks[7]->id, 'blocker_task_id' => $tasks[8]->id],
    ] as $table => $edge) {
        expect(fn () => $connection->table($table)->insert([
            'id' => (string) Str::uuid(), ...$edge, 'owner_scope_key' => $tasks[7]->getRawOriginal('owner_scope_key'),
        ]))->toThrow(QueryException::class);
    }
    expect(fn () => $connection->table($tasks[7]->getTable())->where('id', $tasks[7]->id)->update(['company_id' => 8]))
        ->toThrow(QueryException::class);
});

it('does not expose adopted tasks when their runtime is disabled', function (): void {
    $actor = Actor::query()->findOrFail(1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, fn () => app(TaskLifecycle::class)->create(['title' => 'Protected'], $actor));
    config(['nvl-ownership.enabled' => false]);
    expect(fn () => OwnedTask::query()->get())->toThrow(OwnershipException::class);
});

it('captures the current owner when a task event publisher survives scoped-container cleanup', function (): void {
    $events = app(TaskEvents::class);
    $this->app->forgetScopedInstances();
    Event::fake([TaskChanged::class]);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($events): void {
        $task = app(TaskLifecycle::class)->create(['title' => 'Current lifecycle'], SystemActor::Background);
        $events->record($task, TaskChangeOperation::Created, SystemActor::Background);
        Event::assertDispatched(TaskChanged::class, function ($event): bool {
            expect($event->ownershipJobEnvelope()->context()->owner?->key)->toBe('7');

            return true;
        });
    });
});
