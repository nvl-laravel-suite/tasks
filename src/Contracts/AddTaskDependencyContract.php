<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;

/**
 * Defines the supported add task dependency workflow.
 *
 * @api
 */
interface AddTaskDependencyContract
{
    /** Add one dependency after checking both canonical task revisions. */
    public function execute(
        Task|string $task,
        Task|string $blocker,
        int $expectedTaskRevision,
        int $expectedBlockerRevision,
        TaskActorData $actor,
    ): TaskDependency;
}
