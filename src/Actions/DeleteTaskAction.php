<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Soft-deletes a task while retaining recoverable details and attachments. */
final readonly class DeleteTaskAction
{
    /** Construct the task deletion workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Delete one authorized task in the active tenant. */
    public function execute(Task|string $task, TaskActorData $actor): bool
    {
        $id = $task instanceof Task ? $task->getKey() : $task;

        $deleted = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $id): ?Task {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Delete, $actor, $current);

            if ($current->delete() !== true) {
                return null;
            }

            $this->activity->deleted($current, $actor);

            return $current;
        });

        return $deleted instanceof Task;
    }
}
