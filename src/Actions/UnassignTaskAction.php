<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Removes one assignment only from a task in the active tenant. */
final readonly class UnassignTaskAction
{
    /** Construct the assignment-removal workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Remove an assignee and report whether an assignment changed. */
    public function execute(Task|string $task, Model&Authenticatable $assignee, TaskActorData $actor): bool
    {
        $identity = TaskActorData::fromAuthenticatable($assignee);
        $id = $task instanceof Task ? $task->getKey() : $task;

        return DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $assignee, $id, $identity): bool {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Assign, $actor, $current, $assignee);
            $assignment = $current->assignments()
                ->where('assignee_type', $identity->type)
                ->where('assignee_id', (string) $identity->id)
                ->first();

            if ($assignment === null) {
                return false;
            }

            $assignment->delete();
            $current->forceFill(['revision' => $current->revision + 1])->save();

            return true;
        });
    }
}
