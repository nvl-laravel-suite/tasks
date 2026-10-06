<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

/**
 * Defines the supported list task checklist items workflow.
 *
 * @api
 */
interface ListTaskChecklistItemsContract
{
    /** Return one stable page of checklist items.
     *
     * @return LengthAwarePaginator<int, TaskChecklistItem>
     */
    public function execute(Task|string $task, TaskActorData $actor, ?int $perPage = null, int $page = 1): LengthAwarePaginator;
}
