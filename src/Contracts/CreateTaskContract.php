<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported create task workflow.
 *
 * @api
 */
interface CreateTaskContract
{
    /** Persist a validated task and return its post-write state. */
    public function execute(CreateTaskData $data, TaskActorData $actor): Task;
}
