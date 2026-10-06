<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\TaskReadGuard;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Pages checklist history for one authorized task.
 *
 * @api
 */
final readonly class ListTaskChecklistItemsAction
{
    /** Construct the checklist reader. */
    public function __construct(private TaskReadGuard $guard) {}

    /** Return one stable page of checklist items.
     *
     * @return LengthAwarePaginator<int, TaskChecklistItem>
     */
    public function execute(Task|string $task, TaskActorData $actor, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $current = $this->guard->resolve($task, $actor);
        $pageSize = $perPage ?? TasksConfiguration::limit('default_page_size', 25);

        if ($page < 1 || $pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task checklist page is outside the configured bounds.');
        }

        return $current->checklistItems()->where('tenant_id', $current->tenant_id)
            ->orderBy('position')->orderBy('id')->paginate($pageSize, ['*'], 'page', $page);
    }
}
