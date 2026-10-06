<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported delete task workflow.
 *
 * @api
 */
interface DeleteTaskContract
{
    /** Delete one authorized task in the active tenant. */
    public function execute(Task|string $task, TaskActorData $actor): bool;
}
