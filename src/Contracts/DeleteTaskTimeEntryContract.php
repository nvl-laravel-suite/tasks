<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Defines the supported delete task time entry workflow.
 *
 * @api
 */
interface DeleteTaskTimeEntryContract
{
    /** Remove one entry and advance the exact task revision. */
    public function execute(Task|string $task, TaskTimeEntry|string $entry, int $expectedRevision, TaskActorData $actor): bool;
}
