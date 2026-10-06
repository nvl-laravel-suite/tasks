<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported remove task tag workflow.
 *
 * @api
 */
interface RemoveTaskTagContract
{
    /** Remove a tag and advance the task revision only when a row existed. */
    public function execute(Task|string $task, TaskTagMutationData $data, TaskActorData $actor): bool;
}
