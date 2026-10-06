<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskDetailData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported get task detail workflow.
 *
 * @api
 */
interface GetTaskDetailContract
{
    /** Return a bounded, authorized task detail projection. */
    public function execute(Task|string $task, TaskActorData $actor): TaskDetailData;
}
