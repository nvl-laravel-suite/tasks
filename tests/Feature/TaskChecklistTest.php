<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;

it('adds and edits ordered checklist rows with exact task revisions', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Review release'], $actor);

    $first = app(TaskChecklist::class)->add($task, '  Check notes  ', 1, $actor);
    $second = app(TaskChecklist::class)->add($task, 'Check links', 2, $actor);
    $edited = app(TaskChecklist::class)->update($task, $first, 'Check final notes', 3, $actor);

    expect($first->title)->toBe('Check notes')
        ->and($first->position)->toBe(1)
        ->and($second->position)->toBe(2)
        ->and($edited->title)->toBe('Check final notes')
        ->and($task->fresh()?->revision)->toBe(4)
        ->and(TaskChecklistItem::query()->where('task_id', $task->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['Check final notes', 'Check links']);

    expect(fn () => app(TaskChecklist::class)->add($task, 'Stale', 2, $actor))
        ->toThrow(TaskRevisionConflict::class);
    expect(fn () => app(TaskChecklist::class)->add($task, '   ', 4, $actor))
        ->toThrow(ValidationException::class);
});

it('tracks completion actor and clears it when reopening an item', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Ship release'], $actor);
    $item = app(TaskChecklist::class)->add($task, 'Deploy', 1, $actor);

    $user = new User;
    $user->setTable('users');
    $user->forceFill(['name' => 'Reviewer', 'email' => 'reviewer@example.test', 'password' => 'unused'])->save();
    $reviewer = $user;
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    $completed = app(TaskChecklist::class)->toggle($task, $item, true, 2, $reviewer);

    expect($completed->completed_at)->not->toBeNull()
        ->and($completed->completed_by_type)->toBe($reviewer->getMorphClass())
        ->and($completed->completed_by_id)->toBe((string) $reviewer->id)
        ->and($task->fresh()?->revision)->toBe(3);

    $repeated = app(TaskChecklist::class)->toggle($task, $item, true, 3, $reviewer);

    expect($repeated->completed_at?->toISOString())->toBe($completed->completed_at?->toISOString())
        ->and($repeated->completed_by_id)->toBe((string) $reviewer->id)
        ->and($task->fresh()?->revision)->toBe(3);

    $reopened = app(TaskChecklist::class)->toggle($task, $item, false, 3, $reviewer);

    expect($reopened->completed_at)->toBeNull()
        ->and($reopened->completed_by_type)->toBeNull()
        ->and($reopened->completed_by_id)->toBeNull()
        ->and($task->fresh()?->revision)->toBe(4);
});

it('reorders only an exact checklist and compacts positions after removal', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Prepare launch'], $actor);
    $first = app(TaskChecklist::class)->add($task, 'One', 1, $actor);
    $second = app(TaskChecklist::class)->add($task, 'Two', 2, $actor);
    $third = app(TaskChecklist::class)->add($task, 'Three', 3, $actor);

    expect(fn () => app(TaskChecklist::class)->reorder($task, [$third->id, $first->id], 4, $actor))
        ->toThrow(ValidationException::class);

    app(TaskChecklist::class)->reorder($task, [$third->id, $first->id, $second->id], 4, $actor);
    app(TaskChecklist::class)->remove($task, $first, 5, $actor);

    expect(TaskChecklistItem::query()->where('task_id', $task->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['Three', 'Two'])
        ->and(TaskChecklistItem::query()->where('task_id', $task->id)->orderBy('position')->pluck('position')->all())
        ->toBe([1, 2])
        ->and($task->fresh()?->revision)->toBe(6);
});

it('requires update authorization and refuses items from another task', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Own task'], $actor);
    $other = app(TaskLifecycle::class)->create(['title' => 'Other task'], $actor);
    $item = app(TaskChecklist::class)->add($other, 'Private item', 1, $actor);

    expect(fn () => app(TaskChecklist::class)->update($task, $item, 'Invalid', 1, $actor))
        ->toThrow(ModelNotFoundException::class);

    $user = new User;
    $user->setTable('users');
    $user->forceFill(['name' => 'Visitor', 'email' => 'visitor@example.test', 'password' => 'unused'])->save();
    $visitor = $user;

    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    expect(fn () => app(TaskChecklist::class)->add($task, 'Forbidden', 1, $visitor))
        ->toThrow(BindingRequiredException::class)
        ->and(TaskChecklistItem::query()->where('task_id', $task->id)->count())->toBe(0);
});
