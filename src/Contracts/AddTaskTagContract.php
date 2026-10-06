<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;

/**
 * Defines the supported add task tag workflow.
 *
 * @api
 */
interface AddTaskTagContract
{
    /** Add a tag once and advance the task revision only for a new row. */
    public function execute(Task|string $task, TaskTagMutationData $data, TaskActorData $actor): TaskTag;
}
