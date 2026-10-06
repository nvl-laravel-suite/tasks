<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TasksActivityDelivery;
use Nvl\Tasks\Tests\StandaloneTasksTestCase;

uses(StandaloneTasksTestCase::class);

it('creates ordinary tasks without optional Activity or Media providers', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Standalone work'), TaskActorData::system());

    expect($task->title)->toBe('Standalone work')
        ->and(TaskActivityOutbox::query()->count())->toBe(0)
        ->and(class_uses_recursive(Task::class))->not->toContain('Nvl\\Media\\Traits\\InteractsWithMedia')
        ->and(Artisan::all())->not->toHaveKey('nvl:tasks:activity:drain');
});

it('retains legacy committed outbox rows when Activity delivery is unavailable', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Pending history'), TaskActorData::system());
    $event = new TaskActivityOutbox;
    $event->forceFill(['id' => (string) Str::uuid(), 'task_id' => $task->id, 'tenant_id' => null,
        'payload' => ['legacy' => 'immutable'], 'available_at' => now()])->save();
    $before = $event->fresh()->getAttributes();

    expect(app(TasksActivityDelivery::class)->deliver($event->id))->toBeFalse()
        ->and(app(TasksActivityDelivery::class)->drain(100))->toBe(0)
        ->and($event->fresh()->getAttributes())->toBe($before);
});

it('fails clearly when an attachment is requested without a loaded Media adapter', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Unavailable files'), TaskActorData::system());
    expect(fn () => app(TaskAttachments::class)->attach($task, (string) Str::uuid(), TaskActorData::system()))
        ->toThrow(LogicException::class, 'Media adapter is unavailable');
});
