<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\GetTaskDetailAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;

it('projects a task with its bounded management details', function (): void {
    $actor = TaskActorData::system();
    $action = app(CreateTaskAction::class);
    $task = $action->execute(new CreateTaskData(title: 'Publish release'), $actor);
    $parent = $action->execute(new CreateTaskData(title: 'Release'), $actor);
    $blocker = $action->execute(new CreateTaskData(title: 'Review release'), $actor);

    TaskChecklistItem::query()->forceCreate(['task_id' => $task->id, 'title' => 'Proofread', 'position' => 1]);
    TaskTag::query()->forceCreate(['task_id' => $task->id, 'tag' => 'release']);
    TaskTimeEntry::query()->forceCreate([
        'task_id' => $task->id,
        'performer_type' => 'system',
        'performer_id' => 'system',
        'started_at' => '2026-09-28 10:00:00',
        'stopped_at' => '2026-09-28 10:30:00',
        'duration_seconds' => 1800,
    ]);
    TaskRelationship::query()->forceCreate(['child_task_id' => $task->id, 'parent_task_id' => $parent->id]);
    TaskDependency::query()->forceCreate(['task_id' => $task->id, 'blocker_task_id' => $blocker->id]);

    $detail = app(GetTaskDetailAction::class)->execute($task, $actor)->toArray();

    expect($detail['task']['id'])->toBe($task->id)
        ->and($detail['checklist'][0]['title'])->toBe('Proofread')
        ->and($detail['tags'])->toBe(['release'])
        ->and($detail['timeEntries'][0]['durationSeconds'])->toBe(1800)
        ->and($detail['loggedSeconds'])->toBe(1800)
        ->and($detail['parentId'])->toBe($parent->id)
        ->and($detail['blockerIds'])->toBe([$blocker->id]);
});

it('does not expose links to tasks hidden by host visibility or view policy', function (): void {
    $actor = TaskActorData::system();
    $action = app(CreateTaskAction::class);
    $task = $action->execute(new CreateTaskData('Visible'), $actor);
    $hiddenByScope = $action->execute(new CreateTaskData('Hidden by scope'), $actor);
    $hiddenByPolicy = $action->execute(new CreateTaskData('Hidden by policy'), $actor);
    TaskRelationship::query()->forceCreate(['child_task_id' => $task->id, 'parent_task_id' => $hiddenByScope->id]);
    TaskDependency::query()->forceCreate(['task_id' => $task->id, 'blocker_task_id' => $hiddenByPolicy->id]);

    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if ($ability === TaskAbility::View && $task?->title === 'Hidden by policy') {
                throw new AuthorizationException;
            }
        }

        public function scopeTasks(Builder $query, TaskActorData $actor): void
        {
            $query->where('title', '!=', 'Hidden by scope');
        }
    });

    $detail = app(GetTaskDetailAction::class)->execute($task, $actor)->toArray();

    expect($detail['parentId'])->toBeNull()
        ->and($detail['blockerIds'])->toBe([]);
});

it('diagnoses every package-owned task table', function (): void {
    expect(Artisan::call('nvl:tasks:doctor', ['--format' => 'json']))->toBe(0);
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    foreach (['checklist', 'time_entries', 'relationships', 'dependencies', 'tags'] as $name) {
        expect($result['checks']["{$name}.table"])->toBeTrue();
        expect($result['checks']["{$name}.columns"])->toBeTrue();
    }
});
