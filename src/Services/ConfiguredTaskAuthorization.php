<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;

/** Fails closed until a consuming app binds its own task policy. */
final class ConfiguredTaskAuthorization implements TaskAuthorization
{
    /** Reject every actor until a host policy is explicitly bound. */
    public function authorize(
        TaskAbility $ability,
        Authenticatable|SystemActor $actor,
        ?Task $task = null,
        ?Model $subject = null,
    ): void {
        throw BindingRequiredException::for('tasks', TaskAuthorization::class, 'user_task_mutation');
    }

    /** Deny unconfigured visibility before any task rows are read.
     * @param  Builder<Task>  $query
     */
    public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
    {
        throw BindingRequiredException::for('tasks', TaskAuthorization::class, 'task_visibility');
    }
}
