<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\TimeEntryData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Defines the supported add task time entry workflow.
 *
 * @api
 */
interface AddTaskTimeEntryContract
{
    /** Store a server-calculated interval against an exact task revision. */
    public function execute(Task|string $task, TimeEntryData $data, TaskActorData $actor): TaskTimeEntry;
}
