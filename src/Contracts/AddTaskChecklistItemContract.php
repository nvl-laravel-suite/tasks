<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\AddTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

/**
 * Defines the supported add task checklist item workflow.
 *
 * @api
 */
interface AddTaskChecklistItemContract
{
    /** Append a validated item and advance the task revision. */
    public function execute(Task|string $task, AddTaskChecklistItemData $data, TaskActorData $actor): TaskChecklistItem;
}
