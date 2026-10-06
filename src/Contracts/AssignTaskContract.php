<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;

/**
 * Defines the supported assign task workflow.
 *
 * @api
 */
interface AssignTaskContract
{
    /** Add one assignee without duplicating an existing assignment. */
    public function execute(Task|string $task, Model&Authenticatable $assignee, TaskActorData $actor): TaskAssignment;
}
