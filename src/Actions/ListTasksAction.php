<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Lists tenant-scoped tasks through an explicitly authorized query. */
final readonly class ListTasksAction
{
    /** Construct the task listing boundary. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Return a bounded task page with optional status, priority, and assignee filters.
     *
     * @return LengthAwarePaginator<int, Task>
     */
    public function execute(
        TaskActorData $actor,
        ?TaskStatus $status = null,
        ?TaskPriority $priority = null,
        ?TaskActorData $assignee = null,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $this->authorization->authorize(TaskAbility::List, $actor);
        $pageSize = $perPage ?? TasksConfiguration::limit('default_page_size', 25);

        if ($pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task page size is outside the configured bounds.');
        }

        $query = Task::query()->withCount('assignments');

        if ($this->authorization instanceof TaskQueryScope) {
            $this->authorization->scopeTasks($query, $actor);
        }

        $this->boundary->query($query, Task::TENANT_RESOURCE);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($priority !== null) {
            $query->where('priority', $priority->value);
        }

        if ($assignee !== null) {
            if ($assignee->system) {
                throw new InvalidArgumentException('System context cannot be a task assignee.');
            }

            $query->whereIn('id', TaskAssignment::query()
                ->select('task_id')
                ->where('assignee_type', $assignee->type)
                ->where('assignee_id', (string) $assignee->id));
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($pageSize);
    }
}
