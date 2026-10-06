<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported get task workflow.
 *
 * @api
 */
interface GetTaskContract
{
    /** Return one authorized task without loading unbounded associations. */
    public function execute(Task|string $task, TaskActorData $actor): Task;
}
