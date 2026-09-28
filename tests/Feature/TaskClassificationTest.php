<?php

declare(strict_types=1);

use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskData;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskType;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Tests\Fixtures\ConsumerTaskStatus;
use Nvl\Tasks\Tests\Fixtures\ConsumerTaskType;
use Nvl\Tasks\Tests\Fixtures\EmptyNumericTaskType;

it('casts the built-in task classification and scheduling fields', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Prepare release',
        type: TaskType::Review,
        category: TaskCategory::Project,
        importance: TaskImportance::High,
        targetAt: '2026-10-01T09:00:00+00:00',
        dueAt: '2026-10-03T09:00:00+00:00',
        estimatedSeconds: 3600,
    ), TaskActorData::system());

    expect($task->fresh()->type)->toBe(TaskType::Review)
        ->and($task->category)->toBe(TaskCategory::Project)
        ->and($task->importance)->toBe(TaskImportance::High)
        ->and($task->target_at?->toISOString())->toBe('2026-10-01T09:00:00.000000Z')
        ->and($task->due_at?->toISOString())->toBe('2026-10-03T09:00:00.000000Z')
        ->and($task->estimated_seconds)->toBe(3600);

    $data = TaskData::fromModel($task)->toArray();

    expect($data['type'])->toBe('review')
        ->and($data['category'])->toBe('project')
        ->and($data['importance'])->toBe('high')
        ->and($data['estimatedSeconds'])->toBe(3600);
});

it('uses configured enum classes for model casts and completion semantics', function (): void {
    config()->set([
        'tasks.enums.status' => ConsumerTaskStatus::class,
        'tasks.enums.type' => ConsumerTaskType::class,
        'tasks.defaults.status' => ConsumerTaskStatus::InQueue->value,
        'tasks.lifecycle.completed_status' => ConsumerTaskStatus::Done->value,
    ]);

    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Team meeting',
        type: ConsumerTaskType::Meeting,
        status: ConsumerTaskStatus::InQueue,
    ), $actor);

    expect($task->fresh()->type)->toBe(ConsumerTaskType::Meeting)
        ->and($task->status)->toBe(ConsumerTaskStatus::InQueue);

    $updated = app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Team meeting',
        priority: TaskPriority::Normal,
        status: ConsumerTaskStatus::Done,
        expectedRevision: 1,
        type: ConsumerTaskType::Meeting,
    ), $actor);

    expect($updated->status)->toBe(ConsumerTaskStatus::Done)
        ->and($updated->completed_at)->not->toBeNull()
        ->and(TaskData::fromModel($updated)->toArray()['status'])->toBe('done');
});

it('rejects an empty numeric enum in task configuration', function (): void {
    config()->set('tasks.enums.type', EmptyNumericTaskType::class);

    expect(fn () => TaskEnumConfiguration::enumClass('type'))
        ->toThrow(InvalidArgumentException::class);
});

it('uses configured enum defaults when adding fields to existing tasks', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Existing task'), TaskActorData::system());
    $migration = require __DIR__.'/../../database/migrations/2026_09_28_080128_add_task_management_fields_to_nvl_tasks_table.php';
    $migration->down();
    config()->set([
        'tasks.enums.type' => ConsumerTaskType::class,
        'tasks.enums.category' => ConsumerTaskType::class,
        'tasks.enums.importance' => ConsumerTaskType::class,
        'tasks.defaults.type' => ConsumerTaskType::Meeting->value,
        'tasks.defaults.category' => ConsumerTaskType::Meeting->value,
        'tasks.defaults.importance' => ConsumerTaskType::Meeting->value,
    ]);
    $migration->up();

    $migrated = Task::query()->findOrFail($task->id);

    expect($migrated->type)->toBe(ConsumerTaskType::Meeting)
        ->and($migrated->category)->toBe(ConsumerTaskType::Meeting)
        ->and($migrated->importance)->toBe(ConsumerTaskType::Meeting);
});
