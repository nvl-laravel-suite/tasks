<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;

/**
 * Consumer-owned policy boundary for task and assignee operations.
 *
 * @api
 */
interface TaskAuthorization extends TaskQueryScope
{
    /** Authorize a capability, including any assignment target. */
    public function authorize(
        TaskAbility $ability,
        Authenticatable|SystemActor $actor,
        ?Task $task = null,
        ?Model $subject = null,
    ): void;
}
