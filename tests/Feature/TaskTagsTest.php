<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Actions\AddTaskTagAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\RemoveTaskTagAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;

function tagTestActor(): TaskActorData
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill([
        'name' => 'Tagger',
        'email' => 'tagger@example.test',
        'password' => 'unused',
    ])->save();

    return TaskActorData::fromAuthenticatable($user);
}

function allowTaskTagUpdates(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });
}

it('normalizes tags and treats a repeated add as an unchanged task', function (): void {
    allowTaskTagUpdates();
    $actor = tagTestActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor);

    $tag = app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('  Needs   Review  ', 1), $actor);
    $again = app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('needs review', 2), $actor);

    expect($tag->tag)->toBe('needs review')
        ->and($again->id)->toBe($tag->id)
        ->and(TaskTag::query()->count())->toBe(1)
        ->and($task->fresh()?->revision)->toBe(2);
});

it('removes an existing tag and keeps a missing removal idempotent', function (): void {
    allowTaskTagUpdates();
    $actor = tagTestActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor);
    app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('Review', 1), $actor);

    expect(app(RemoveTaskTagAction::class)->execute($task, new TaskTagMutationData(' REVIEW ', 2), $actor))->toBeTrue()
        ->and(app(RemoveTaskTagAction::class)->execute($task, new TaskTagMutationData('review', 3), $actor))->toBeFalse()
        ->and(TaskTag::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(3);
});

it('rejects empty oversized and over-count tags without changing the task', function (): void {
    allowTaskTagUpdates();
    $actor = tagTestActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor);

    expect(fn () => app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('   ', 1), $actor))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData(str_repeat('x', 65), 1), $actor))
        ->toThrow(ValidationException::class);

    for ($number = 1; $number <= 20; $number++) {
        app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData("tag {$number}", $number), $actor);
    }

    expect(fn () => app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('tag 21', 21), $actor))
        ->toThrow(ValidationException::class);
    expect(TaskTag::query()->count())->toBe(20)
        ->and($task->fresh()?->revision)->toBe(21);
});

it('checks update authorization and exact revision before mutating tags', function (): void {
    $actor = tagTestActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), TaskActorData::system());

    expect(fn () => app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('review', 1), $actor))
        ->toThrow(AuthorizationException::class);

    allowTaskTagUpdates();

    expect(fn () => app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('review', 2), $actor))
        ->toThrow(TaskRevisionConflict::class);
    expect(TaskTag::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(1);
});
