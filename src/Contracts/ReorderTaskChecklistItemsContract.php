<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\Mutations\ReorderTaskChecklistItemsData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported reorder task checklist items workflow.
 *
 * @api
 */
interface ReorderTaskChecklistItemsContract
{
    /** Reorder exactly the task's existing items and advance its revision. */
    public function execute(Task|string $task, ReorderTaskChecklistItemsData $data, TaskActorData $actor): Task;
}
