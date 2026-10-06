<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\ToggleTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

/**
 * Defines the supported toggle task checklist item workflow.
 *
 * @api
 */
interface ToggleTaskChecklistItemContract
{
    /** Set item completion and advance the task revision. */
    public function execute(
        Task|string $task,
        TaskChecklistItem|string $item,
        ToggleTaskChecklistItemData $data,
        TaskActorData $actor,
    ): TaskChecklistItem;
}
