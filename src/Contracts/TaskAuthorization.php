<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;

/** Consumer-owned policy boundary for task and assignee operations. */
interface TaskAuthorization
{
    /** Authorize a capability, including any assignment target. */
    public function authorize(
        TaskAbility $ability,
        TaskActorData $actor,
        ?Task $task = null,
        ?Model $subject = null,
    ): void;
}
