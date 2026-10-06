<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported update task workflow.
 *
 * @api
 */
interface UpdateTaskContract
{
    /** Replace editable fields while rejecting stale client state. */
    public function execute(Task|string $task, UpdateTaskData $data, TaskActorData $actor): Task;
}
