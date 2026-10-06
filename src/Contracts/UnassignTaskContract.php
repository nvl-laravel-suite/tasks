<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported unassign task workflow.
 *
 * @api
 */
interface UnassignTaskContract
{
    /** Remove an assignee and report whether an assignment changed. */
    public function execute(Task|string $task, Model&Authenticatable $assignee, TaskActorData $actor): bool;
}
