<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tenancy\Services\TenantBoundary;

/** Resolves a canonical tenant task through the host's View policy. */
final readonly class TaskReadGuard
{
    /** Construct the task read boundary. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Return one task after tenant and View authorization. */
    public function resolve(Task|string $task, TaskActorData $actor): Task
    {
        $id = $task instanceof Task ? $task->getKey() : $task;
        $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
            ->withCount('assignments')
            ->whereKey($id)->firstOrFail();
        $this->authorization->authorize(TaskAbility::View, $actor, $current);

        return $current;
    }
}
