<?php

declare(strict_types=1);

use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskType;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Tests\Fixtures\ConsumerTaskStatus;
use Nvl\Tasks\Tests\Fixtures\ConsumerTaskType;
use Nvl\Tasks\Tests\Fixtures\EmptyNumericTaskType;

it('casts the built-in task classification and scheduling fields', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Prepare release', 'type' => TaskType::Review, 'category' => TaskCategory::Project, 'importance' => TaskImportance::High, 'target_at' => '2026-10-01T09:00:00+00:00', 'due_at' => '2026-10-03T09:00:00+00:00', 'estimated_seconds' => 3600], SystemActor::Background);

    expect($task->fresh()->type)->toBe(TaskType::Review)
        ->and($task->category)->toBe(TaskCategory::Project)
        ->and($task->importance)->toBe(TaskImportance::High)
        ->and($task->target_at?->toISOString())->toBe('2026-10-01T09:00:00.000000Z')
        ->and($task->due_at?->toISOString())->toBe('2026-10-03T09:00:00.000000Z')
        ->and($task->estimated_seconds)->toBe(3600);

    $data = $task->toArray();

    expect($data['type'])->toBe('review')
        ->and($data['category'])->toBe('project')
        ->and($data['importance'])->toBe('high')
        ->and($data['estimated_seconds'])->toBe(3600);
});

it('uses configured enum classes for model casts and completion semantics', function (): void {
    config()->set([
        'nvl-tasks.enums.status' => ConsumerTaskStatus::class,
        'nvl-tasks.enums.type' => ConsumerTaskType::class,
        'nvl-tasks.defaults.status' => ConsumerTaskStatus::InQueue->value,
        'nvl-tasks.lifecycle.completed_status' => ConsumerTaskStatus::Done->value,
    ]);

    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Team meeting', 'type' => ConsumerTaskType::Meeting, 'status' => ConsumerTaskStatus::InQueue], $actor);

    expect($task->fresh()->type)->toBe(ConsumerTaskType::Meeting)
        ->and($task->status)->toBe(ConsumerTaskStatus::InQueue);

    $updated = app(TaskLifecycle::class)->update($task, ['title' => 'Team meeting', 'priority' => TaskPriority::Normal, 'status' => ConsumerTaskStatus::Done, 'type' => ConsumerTaskType::Meeting], 1, $actor);

    expect($updated->status)->toBe(ConsumerTaskStatus::Done)
        ->and($updated->completed_at)->not->toBeNull()
        ->and($updated->toArray()['status'])->toBe('done');
});

it('rejects an empty numeric enum in task configuration', function (): void {
    config()->set('nvl-tasks.enums.type', EmptyNumericTaskType::class);

    expect(fn () => TaskEnumConfiguration::enumClass('type'))
        ->toThrow(InvalidArgumentException::class);
});

it('uses configured enum defaults when adding fields to existing tasks', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Existing task'], SystemActor::Background);
    $migration = require __DIR__.'/../../database/migrations/2026_09_28_080128_nvl_tasks_add_task_management_fields_to_nvl_tasks_table.php';
    $migration->down();
    config()->set([
        'nvl-tasks.enums.type' => ConsumerTaskType::class,
        'nvl-tasks.enums.category' => ConsumerTaskType::class,
        'nvl-tasks.enums.importance' => ConsumerTaskType::class,
        'nvl-tasks.defaults.type' => ConsumerTaskType::Meeting->value,
        'nvl-tasks.defaults.category' => ConsumerTaskType::Meeting->value,
        'nvl-tasks.defaults.importance' => ConsumerTaskType::Meeting->value,
    ]);
    $migration->up();

    $migrated = Task::query()->findOrFail($task->id);

    expect($migrated->type)->toBe(ConsumerTaskType::Meeting)
        ->and($migrated->category)->toBe(ConsumerTaskType::Meeting)
        ->and($migrated->importance)->toBe(ConsumerTaskType::Meeting);
});
