<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Enums\TaskType;
use Nvl\Tasks\Support\TaskEnumConfiguration;

it('preserves optional details when a direct update constructor omits them', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Original',
        description: 'Keep notes',
        dueAt: '2026-09-29 17:00:00',
        metadata: ['source' => 'draft'],
        type: TaskType::Review,
        category: TaskCategory::Project,
        importance: TaskImportance::Critical,
        targetAt: '2026-09-28 09:00:00',
        estimatedSeconds: 3600,
    ), $actor);

    $updated = app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        'Renamed', TaskPriority::High, TaskStatus::InProgress, 1,
    ), $actor);

    expect($updated->title)->toBe('Renamed')
        ->and($updated->description)->toBe('Keep notes')
        ->and($updated->due_at?->format('Y-m-d H:i:s'))->toBe('2026-09-29 17:00:00')
        ->and($updated->target_at?->format('Y-m-d H:i:s'))->toBe('2026-09-28 09:00:00')
        ->and($updated->estimated_seconds)->toBe(3600)
        ->and($updated->metadata)->toBe(['source' => 'draft'])
        ->and($updated->type)->toBe(TaskType::Review)
        ->and($updated->category)->toBe(TaskCategory::Project)
        ->and($updated->importance)->toBe(TaskImportance::Critical);
});

it('clears planning fields and resets explicit null classifications to configured defaults', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Original',
        description: 'Remove notes',
        dueAt: '2026-09-29 17:00:00',
        metadata: ['source' => 'draft'],
        type: TaskType::Review,
        category: TaskCategory::Project,
        importance: TaskImportance::Critical,
        targetAt: '2026-09-28 09:00:00',
        estimatedSeconds: 3600,
    ), $actor);

    $updated = app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Cleared',
        priority: TaskPriority::Normal,
        status: TaskStatus::Open,
        expectedRevision: 1,
        description: null,
        dueAt: null,
        metadata: [],
        type: null,
        category: null,
        importance: null,
        targetAt: null,
        estimatedSeconds: null,
    ), $actor);

    expect($updated->description)->toBeNull()
        ->and($updated->due_at)->toBeNull()
        ->and($updated->target_at)->toBeNull()
        ->and($updated->estimated_seconds)->toBeNull()
        ->and($updated->metadata)->toBe([])
        ->and($updated->type->value)->toBe(TaskEnumConfiguration::defaultValue('type'))
        ->and($updated->category->value)->toBe(TaskEnumConfiguration::defaultValue('category'))
        ->and($updated->importance->value)->toBe(TaskEnumConfiguration::defaultValue('importance'));
});

it('validates the final due and target dates when only one date changes', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Scheduled',
        dueAt: '2026-09-29 17:00:00',
        targetAt: '2026-09-28 09:00:00',
    ), $actor);

    expect(fn () => app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Scheduled',
        priority: TaskPriority::Normal,
        status: TaskStatus::Open,
        expectedRevision: 1,
        targetAt: '2026-09-30 09:00:00',
    ), $actor))->toThrow(ValidationException::class);

    expect($task->fresh()?->revision)->toBe(1)
        ->and($task->fresh()?->target_at?->format('Y-m-d'))->toBe('2026-09-28');
});
