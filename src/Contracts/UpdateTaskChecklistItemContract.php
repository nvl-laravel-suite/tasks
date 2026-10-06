<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\UpdateTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

/**
 * Defines the supported update task checklist item workflow.
 *
 * @api
 */
interface UpdateTaskChecklistItemContract
{
    /** Replace a validated item title and advance the task revision. */
    public function execute(
        Task|string $task,
        TaskChecklistItem|string $item,
        UpdateTaskChecklistItemData $data,
        TaskActorData $actor,
    ): TaskChecklistItem;
}
