<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\RemoveTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

/**
 * Defines the supported remove task checklist item workflow.
 *
 * @api
 */
interface RemoveTaskChecklistItemContract
{
    /** Remove one owned item, compact positions, and advance the task revision. */
    public function execute(
        Task|string $task,
        TaskChecklistItem|string $item,
        RemoveTaskChecklistItemData $data,
        TaskActorData $actor,
    ): Task;
}
