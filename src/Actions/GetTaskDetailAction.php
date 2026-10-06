<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskDetailData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskReadGuard;
use Nvl\Tasks\Support\TasksConfiguration;
use UnexpectedValueException;

/**
 * Reads one task detail with only related IDs the caller may view.
 *
 * @api
 */
final readonly class GetTaskDetailAction
{
    /** Construct the task detail read boundary. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
        private TaskReadGuard $guard,
    ) {}

    /** Return a bounded, authorized task detail projection. */
    public function execute(Task|string $task, TaskActorData $actor): TaskDetailData
    {
        $current = $this->guard->resolve($task, $actor);
        $limit = TasksConfiguration::limit('detail.maximum_task_links', 100);
        $parentIds = $this->ids(array_values($current->parentLink()->where('tenant_id', $current->tenant_id)
            ->limit(1)->pluck('parent_task_id')->all()));
        $childIds = $this->ids(array_values($current->childLinks()->where('tenant_id', $current->tenant_id)
            ->limit($limit)->pluck('child_task_id')->all()));
        $blockerIds = $this->ids(array_values($current->blockerLinks()->where('tenant_id', $current->tenant_id)
            ->limit($limit)->pluck('blocker_task_id')->all()));
        $blockedTaskIds = $this->ids(array_values($current->blockedTaskLinks()->where('tenant_id', $current->tenant_id)
            ->limit($limit)->pluck('task_id')->all()));
        $visible = $this->visibleIds(array_values(array_unique([
            ...$parentIds,
            ...$childIds,
            ...$blockerIds,
            ...$blockedTaskIds,
        ])), $actor);

        return TaskDetailData::fromModel(
            task: $current,
            parentId: isset($parentIds[0]) && isset($visible[$parentIds[0]]) ? $parentIds[0] : null,
            childIds: $this->filterVisible($childIds, $visible),
            blockerIds: $this->filterVisible($blockerIds, $visible),
            blockedTaskIds: $this->filterVisible($blockedTaskIds, $visible),
        );
    }

    /** Validate link identifiers obtained from task-owned edge tables.
     *
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException('A task link identifier must be a string.');
            }

            $ids[] = $value;
        }

        return $ids;
    }

    /** Find related IDs admitted by the host query scope and View policy.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function visibleIds(array $ids, TaskActorData $actor): array
    {
        if ($ids === []) {
            return [];
        }

        $query = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)->whereKey($ids);

        if ($this->authorization instanceof TaskQueryScope) {
            $scope = Task::query();
            $this->authorization->scopeTasks($scope, $actor);
            $query->whereIn('id', $this->boundary->query($scope, Task::TENANT_RESOURCE)->select('id'));
        }

        $visible = [];

        foreach ($query->get() as $related) {
            try {
                $this->authorization->authorize(TaskAbility::View, $actor, $related);
                $visible[$related->id] = true;
            } catch (AuthorizationException) {
                // A hidden related task is omitted from the primary task's detail.
            }
        }

        return $visible;
    }

    /** Retain only related IDs admitted by both visibility boundaries.
     *
     * @param  list<string>  $ids
     * @param  array<string, true>  $visible
     * @return list<string>
     */
    private function filterVisible(array $ids, array $visible): array
    {
        return array_values(array_filter($ids, static fn (string $id): bool => isset($visible[$id])));
    }
}
