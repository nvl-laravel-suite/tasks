<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Defines the supported list task time entries workflow.
 *
 * @api
 */
interface ListTaskTimeEntriesContract
{
    /** Return one stable page of manual intervals and timers.
     *
     * @return LengthAwarePaginator<int, TaskTimeEntry>
     */
    public function execute(Task|string $task, TaskActorData $actor, ?int $perPage = null, int $page = 1): LengthAwarePaginator;
}
