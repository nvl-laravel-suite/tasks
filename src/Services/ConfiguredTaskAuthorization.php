<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;

/** Fails closed until a consuming app binds its own task policy. */
final class ConfiguredTaskAuthorization implements TaskAuthorization
{
    /** Admit trusted system work while rejecting unconfigured user access. */
    public function authorize(
        TaskAbility $ability,
        TaskActorData $actor,
        ?Task $task = null,
        ?Model $subject = null,
    ): void {
        if ($actor->system) {
            return;
        }

        throw new AuthorizationException(
            "Task ability [{$ability->value}] requires a consumer authorization binding.",
        );
    }
}
