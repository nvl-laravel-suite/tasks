<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Actions\AddTaskChecklistItemAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\RemoveTaskChecklistItemAction;
use Nvl\Tasks\Actions\ReorderTaskChecklistItemsAction;
use Nvl\Tasks\Actions\ToggleTaskChecklistItemAction;
use Nvl\Tasks\Actions\UpdateTaskChecklistItemAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\AddTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\RemoveTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\ReorderTaskChecklistItemsData;
use Nvl\Tasks\Data\Mutations\ToggleTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\UpdateTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

it('adds and edits ordered checklist rows with exact task revisions', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review release'), $actor);

    $first = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('  Check notes  ', 1), $actor);
    $second = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Check links', 2), $actor);
    $edited = app(UpdateTaskChecklistItemAction::class)->execute($task, $first, new UpdateTaskChecklistItemData('Check final notes', 3), $actor);

    expect($first->title)->toBe('Check notes')
        ->and($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and($edited->title)->toBe('Check final notes')
        ->and($task->fresh()?->revision)->toBe(4)
        ->and(TaskChecklistItem::query()->where('task_id', $task->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['Check final notes', 'Check links']);

    expect(fn () => app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Stale', 2), $actor))
        ->toThrow(TaskRevisionConflict::class);
    expect(fn () => app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('   ', 4), $actor))
        ->toThrow(ValidationException::class);
});

it('tracks completion actor and clears it when reopening an item', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Ship release'), $actor);
    $item = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Deploy', 1), $actor);

    $user = new User;
    $user->setTable('users');
    $user->forceFill(['name' => 'Reviewer', 'email' => 'reviewer@example.test', 'password' => 'unused'])->save();
    $reviewer = TaskActorData::fromAuthenticatable($user);
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });

    $completed = app(ToggleTaskChecklistItemAction::class)->execute($task, $item, new ToggleTaskChecklistItemData(true, 2), $reviewer);

    expect($completed->completed_at)->not->toBeNull()
        ->and($completed->completed_by_type)->toBe($reviewer->type)
        ->and($completed->completed_by_id)->toBe((string) $reviewer->id)
        ->and($task->fresh()?->revision)->toBe(3);

    $repeated = app(ToggleTaskChecklistItemAction::class)->execute($task, $item, new ToggleTaskChecklistItemData(true, 3), $reviewer);

    expect($repeated->completed_at?->toISOString())->toBe($completed->completed_at?->toISOString())
        ->and($repeated->completed_by_id)->toBe((string) $reviewer->id)
        ->and($task->fresh()?->revision)->toBe(3);

    $reopened = app(ToggleTaskChecklistItemAction::class)->execute($task, $item, new ToggleTaskChecklistItemData(false, 3), $reviewer);

    expect($reopened->completed_at)->toBeNull()
        ->and($reopened->completed_by_type)->toBeNull()
        ->and($reopened->completed_by_id)->toBeNull()
        ->and($task->fresh()?->revision)->toBe(4);
});

it('reorders only an exact checklist and compacts positions after removal', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Prepare launch'), $actor);
    $first = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('One', 1), $actor);
    $second = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Two', 2), $actor);
    $third = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Three', 3), $actor);

    expect(fn () => app(ReorderTaskChecklistItemsAction::class)->execute($task, new ReorderTaskChecklistItemsData([$third->id, $first->id], 4), $actor))
        ->toThrow(ValidationException::class);

    app(ReorderTaskChecklistItemsAction::class)->execute($task, new ReorderTaskChecklistItemsData([$third->id, $first->id, $second->id], 4), $actor);
    app(RemoveTaskChecklistItemAction::class)->execute($task, $first, new RemoveTaskChecklistItemData(5), $actor);

    expect(TaskChecklistItem::query()->where('task_id', $task->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['Three', 'Two'])
        ->and(TaskChecklistItem::query()->where('task_id', $task->id)->orderBy('position')->pluck('position')->all())
        ->toBe([1, 2])
        ->and($task->fresh()?->revision)->toBe(6);
});

it('requires update authorization and refuses items from another task', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Own task'), $actor);
    $other = app(CreateTaskAction::class)->execute(new CreateTaskData('Other task'), $actor);
    $item = app(AddTaskChecklistItemAction::class)->execute($other, new AddTaskChecklistItemData('Private item', 1), $actor);

    expect(fn () => app(UpdateTaskChecklistItemAction::class)->execute($task, $item, new UpdateTaskChecklistItemData('Invalid', 1), $actor))
        ->toThrow(ModelNotFoundException::class);

    $user = new User;
    $user->setTable('users');
    $user->forceFill(['name' => 'Visitor', 'email' => 'visitor@example.test', 'password' => 'unused'])->save();
    $visitor = TaskActorData::fromAuthenticatable($user);

    expect(fn () => app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Forbidden', 1), $visitor))
        ->toThrow(BindingRequiredException::class)
        ->and(TaskChecklistItem::query()->where('task_id', $task->id)->count())->toBe(0);
});
