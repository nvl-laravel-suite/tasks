<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\TimeEntryData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Defines the supported update task time entry workflow.
 *
 * @api
 */
interface UpdateTaskTimeEntryContract
{
    /** Update one manual entry and advance the exact task revision. */
    public function execute(Task|string $task, TaskTimeEntry|string $entry, TimeEntryData $data, TaskActorData $actor): TaskTimeEntry;
}
