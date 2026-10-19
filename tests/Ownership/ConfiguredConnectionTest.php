<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Services\TaskModelRegistry;
use Nvl\Tasks\Services\TasksDoctor;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tasks\Tests\ConfiguredStorageTestCase;
use Nvl\Tasks\Tests\Fixtures\RemoteTask;

uses(ConfiguredStorageTestCase::class);

it('uses the concrete consumer source for transactions children factories and committed events', function (): void {
    expect(TasksConfiguration::connection())->toBe('ownership_remote');
    $checks = app(TasksDoctor::class)->inspect()['checks'];
    expect($checks['ownership.resource'])->toBeTrue()->and($checks['tasks.table'])->toBeTrue();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Consumer source'), TaskActorData::system());
    expect($task)->toBeInstanceOf(RemoteTask::class)->and($task->getTable())->toBe('consumer_tasks');
    expect((new TaskTag)->getConnection())->toBe($task->getConnection());
    $tag = TaskTag::factory()->create(['task_id' => $task->id]);
    expect($tag->task)->toBeInstanceOf(RemoteTask::class)->and($tag->task->id)->toBe($task->id);
    Event::fake([TaskChanged::class]);
    expect(fn () => DB::connection('ownership_remote')->transaction(function (): void {
        app(CreateTaskAction::class)->execute(new CreateTaskData('Rollback'), TaskActorData::system());
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');
    Event::assertNothingDispatched();
    expect(TaskModelRegistry::query()->count())->toBe(1)
        ->and(Task::factory()->create())->toBeInstanceOf(RemoteTask::class);
});
