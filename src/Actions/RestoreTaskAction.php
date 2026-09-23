<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Restores a soft-deleted task without losing Content or Media ownership. */
final readonly class RestoreTaskAction
{
    /** Construct the task-restoration workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Restore a tenant-visible task only at its exact deleted revision. */
    public function execute(Task|string $task, int $expectedRevision, TaskActorData $actor): Task
    {
        $id = $task instanceof Task ? $task->getKey() : $task;

        return DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $id): Task {
            $current = $this->boundary->query(Task::withTrashed(), Task::TENANT_RESOURCE)
                ->whereKey($id)->whereNotNull('deleted_at')->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Restore, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $current->forceFill(['revision' => $current->revision + 1]);
            $current->restore();

            return $current->refresh();
        });
    }
}
