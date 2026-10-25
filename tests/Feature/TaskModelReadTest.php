<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;

it('applies actor visibility before model hydration pagination and aggregates', function (): void {
    $actor = nativeTaskUser();
    $service = app(TaskLifecycle::class);
    $visible = $service->create(['title' => 'Visible', 'estimated_seconds' => 10], $actor);
    $hidden = $service->create(['title' => 'Hidden', 'estimated_seconds' => 900], $actor);
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
        {
            $query->where('title', 'Visible');
        }
    });
    $reader = app(TaskReader::class);
    expect($reader->get($visible, $actor)->title)->toBe('Visible')
        ->and($reader->paginate([], $actor, 1)->total())->toBe(1)
        ->and($reader->dashboard($actor)['estimated_seconds'])->toBe(10)
        ->and(fn () => $reader->get($hidden, $actor))->toThrow(ModelNotFoundException::class);
});

it('validates native reader boundaries without request state', function (): void {
    $actor = nativeTaskUser();
    $reader = app(TaskReader::class);
    expect(fn () => $reader->paginate([], $actor, page: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reader->paginate([], $actor, perPage: 101))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reader->paginate(['owner_id' => 99], $actor))->toThrow(ValidationException::class);
});
