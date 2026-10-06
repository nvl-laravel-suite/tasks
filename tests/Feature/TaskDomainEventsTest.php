<?php

declare(strict_types=1);

use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Services\DisabledTenantContext;
use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskChangeOperation;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskEvents;
use Nvl\Tasks\Services\TasksActivity;

beforeEach(function (): void {
    /** Semantic publisher checks use a source connection outside the package test harness transaction. */
    config()->set('database.connections.c4-task-events', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
});

it('publishes all 23 semantic task operations before the optional activity adapter without private values', function (): void {
    $published = [];
    $this->app['events']->listen(TaskChanged::class, function (TaskChanged $event) use (&$published): void {
        $published[] = $event;
    });
    $publisher = Mockery::mock(TaskActivityPublisher::class);
    $publisher->shouldReceive('record')->times(23)->andReturnUsing(function () use (&$published): void {
        expect($published)->not->toBeEmpty();
    });
    $activity = new TasksActivity($publisher, new TaskEvents($this->app->make(DomainEventDispatcher::class), new DisabledTenantContext));
    $task = (new Task)->setConnection('c4-task-events');
    $task->setRawAttributes(['id' => 'task-id', 'revision' => 7, 'description' => 'private-description'], true);
    $actor = TaskActorData::system();
    $assignee = new TaskActorData('host', 42);
    $activity->created($task, $actor);
    $task->setRawAttributes(['id' => 'task-id', 'revision' => 8, 'description' => 'private-updated-description']);
    $task->syncChanges();
    $activity->updated($task, $actor);
    $activity->deleted($task, $actor);
    $activity->restored($task, $actor);
    $activity->assigned($task, $actor, $assignee);
    $activity->unassigned($task, $actor, $assignee);
    $activity->checklistItemAdded($task, 'item', $actor);
    $activity->checklistItemUpdated($task, 'item', $actor);
    $activity->checklistItemCompletionChanged($task, 'item', true, $actor);
    $activity->checklistItemCompletionChanged($task, 'item', false, $actor);
    $activity->checklistReordered($task, $actor);
    $activity->checklistItemRemoved($task, 'item', $actor);
    $activity->tagAdded($task, 'private-tag', $actor);
    $activity->tagRemoved($task, 'private-tag', $actor);
    $activity->timeEntryAdded($task, 'entry', 30, $actor);
    $activity->timeEntryUpdated($task, 'entry', 40, $actor);
    $activity->timeEntryRemoved($task, 'entry', $actor);
    $activity->timerStarted($task, 'entry', $actor);
    $activity->timerStopped($task, 'entry', 50, $actor);
    $activity->parentLinked($task, 'parent', $actor);
    $activity->parentUnlinked($task, 'parent', $actor);
    $activity->blockerAdded($task, 'blocker', $actor);
    $activity->blockerRemoved($task, 'blocker', $actor);
    expect(array_map(fn (TaskChanged $event) => $event->operation, $published))->toBe(TaskChangeOperation::cases())
        ->and($published[4]->context)->toBe(['assignee_type' => 'host', 'assignee_id' => 42]);
    foreach ($published as $event) {
        expect(serialize($event))->not->toContain('private-description', 'private-updated-description', 'private-tag')
            ->and($event->tenantJobEnvelope()->context->mode)->toBe(TenantContextMode::Disabled);
    }
});

it('omits domain publication for no changes and technical-only task updates', function (): void {
    $count = 0;
    $this->app['events']->listen(TaskChanged::class, function () use (&$count): void {
        $count++;
    });
    $publisher = Mockery::mock(TaskActivityPublisher::class);
    $publisher->shouldReceive('record')->once();
    $activity = new TasksActivity($publisher, new TaskEvents($this->app->make(DomainEventDispatcher::class), new DisabledTenantContext));
    $task = (new Task)->setConnection('c4-task-events');
    $task->setRawAttributes(['id' => 'task-id', 'revision' => 1], true);
    $activity->updated($task, TaskActorData::system());
    $task->setRawAttributes(['id' => 'task-id', 'revision' => 2, 'updated_at' => '2026-10-07 00:00:00']);
    $task->syncChanges();
    $activity->updated($task, TaskActorData::system());
    expect($count)->toBe(0);
});
