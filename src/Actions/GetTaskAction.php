<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tenancy\Services\TenantBoundary;

/** Resolves one tenant-visible task for a permitted caller. */
final readonly class GetTaskAction
{
    /** Construct the task read boundary. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Return one authorized task without loading unbounded associations. */
    public function execute(Task|string $task, TaskActorData $actor): Task
    {
        $id = $task instanceof Task ? $task->getKey() : $task;
        $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
            ->withCount('assignments')
            ->whereKey($id)->firstOrFail();
        $this->authorization->authorize(TaskAbility::View, $actor, $current);

        return $current;
    }
}
