<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TaskReadGuard;
use Nvl\Tasks\Support\TasksConfiguration;

/** Pages recorded effort for one authorized task. */
final readonly class ListTaskTimeEntriesAction
{
    /** Construct the time-entry reader. */
    public function __construct(private TaskReadGuard $guard) {}

    /** Return one stable page of manual intervals and timers.
     *
     * @return LengthAwarePaginator<int, TaskTimeEntry>
     */
    public function execute(Task|string $task, TaskActorData $actor, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $current = $this->guard->resolve($task, $actor);
        $pageSize = $perPage ?? TasksConfiguration::limit('default_page_size', 25);

        if ($page < 1 || $pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task time-entry page is outside the configured bounds.');
        }

        return $current->timeEntries()->where('tenant_id', $current->tenant_id)
            ->orderByDesc('started_at')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }
}
