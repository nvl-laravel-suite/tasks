<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;

/** Resolves canonical task ownership and revision for checklist writes. */
final readonly class TaskChecklistMutationGuard
{
    /** Construct the checklist write boundary. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
    ) {}

    /** Lock the active tenant's task and require update permission and revision. */
    public function lock(Task|string $task, int $expectedRevision, TaskActorData $actor): Task
    {
        $id = $task instanceof Task ? $task->getKey() : $task;
        $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
            ->whereKey($id)->lockForUpdate()->firstOrFail();

        $this->authorization->authorize(TaskAbility::Update, $actor, $current);

        if ($current->revision !== $expectedRevision) {
            throw new TaskRevisionConflict;
        }

        return $current;
    }

    /** Resolve one item owned by the locked task and its canonical tenant. */
    public function item(Task $task, TaskChecklistItem|string $item): TaskChecklistItem
    {
        $id = $item instanceof TaskChecklistItem ? $item->getKey() : $item;

        return TaskChecklistItem::query()
            ->where('task_id', $task->id)
            ->where('tenant_id', $task->tenant_id)
            ->whereKey($id)
            ->firstOrFail();
    }

    /** Advance the task's revision after a checklist mutation. */
    public function increment(Task $task): Task
    {
        $task->forceFill(['revision' => $task->revision + 1])->save();

        return $task->refresh();
    }
}
