<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskTags;

function tagTestActor(): Authenticatable
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill([
        'name' => 'Tagger',
        'email' => 'tagger@example.test',
        'password' => 'unused',
    ])->save();

    return $user;
}

function allowTaskTagUpdates(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });
}

it('normalizes tags and treats a repeated add as an unchanged task', function (): void {
    allowTaskTagUpdates();
    $actor = tagTestActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);

    $tag = app(TaskTags::class)->add($task, '  Needs   Review  ', 1, $actor);
    $again = app(TaskTags::class)->add($task, 'needs review', 2, $actor);

    expect($tag->tag)->toBe('needs review')
        ->and($again->id)->toBe($tag->id)
        ->and(TaskTag::query()->count())->toBe(1)
        ->and($task->fresh()?->revision)->toBe(2);
});

it('removes an existing tag and keeps a missing removal idempotent', function (): void {
    allowTaskTagUpdates();
    $actor = tagTestActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);
    app(TaskTags::class)->add($task, 'Review', 1, $actor);

    expect(app(TaskTags::class)->remove($task, ' REVIEW ', 2, $actor))->toBeTrue()
        ->and(app(TaskTags::class)->remove($task, 'review', 3, $actor))->toBeFalse()
        ->and(TaskTag::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(3);
});

it('rejects empty oversized and over-count tags without changing the task', function (): void {
    allowTaskTagUpdates();
    $actor = tagTestActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);

    expect(fn () => app(TaskTags::class)->add($task, '   ', 1, $actor))
        ->toThrow(ValidationException::class);
    expect(fn () => app(TaskTags::class)->add($task, str_repeat('x', 65), 1, $actor))
        ->toThrow(ValidationException::class);

    for ($number = 1; $number <= 20; $number++) {
        app(TaskTags::class)->add($task, "tag {$number}", $number, $actor);
    }

    expect(fn () => app(TaskTags::class)->add($task, 'tag 21', 21, $actor))
        ->toThrow(ValidationException::class);
    expect(TaskTag::query()->count())->toBe(20)
        ->and($task->fresh()?->revision)->toBe(21);
});

it('checks update authorization and exact revision before mutating tags', function (): void {
    $actor = tagTestActor();
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], SystemActor::Background);

    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    expect(fn () => app(TaskTags::class)->add($task, 'review', 1, $actor))
        ->toThrow(BindingRequiredException::class);

    allowTaskTagUpdates();

    expect(fn () => app(TaskTags::class)->add($task, 'review', 2, $actor))
        ->toThrow(TaskRevisionConflict::class);
    expect(TaskTag::query()->count())->toBe(0)
        ->and($task->fresh()?->revision)->toBe(1);
});
