<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported list tasks workflow.
 *
 * @api
 */
interface ListTasksContract
{
    /** Return a bounded task page with optional classification, actor, tag and date filters.
     *
     * @return LengthAwarePaginator<int, Task>
     */
    public function execute(
        TaskActorData $actor,
        BackedEnum|string|null $status = null,
        BackedEnum|string|null $priority = null,
        ?TaskActorData $assignee = null,
        ?int $perPage = null,
        BackedEnum|string|null $type = null,
        BackedEnum|string|null $category = null,
        BackedEnum|string|null $importance = null,
        ?string $tag = null,
        ?string $targetFrom = null,
        ?string $targetTo = null,
        ?string $dueFrom = null,
        ?string $dueTo = null,
        ?bool $overdue = null,
    ): LengthAwarePaginator;
}
