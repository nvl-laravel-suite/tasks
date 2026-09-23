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
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Idempotently assigns a canonical task to a persisted host principal. */
final readonly class AssignTaskAction
{
    /** Construct the assignment workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Add one assignee without duplicating an existing assignment. */
    public function execute(Task|string $task, Model&Authenticatable $assignee, TaskActorData $actor): TaskAssignment
    {
        $identity = TaskActorData::fromAuthenticatable($assignee);
        $id = $task instanceof Task ? $task->getKey() : $task;

        return DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $assignee, $id, $identity): TaskAssignment {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Assign, $actor, $current, $assignee);
            $existing = $current->assignments()
                ->where('assignee_type', $identity->type)
                ->where('assignee_id', (string) $identity->id)
                ->first();

            if ($existing instanceof TaskAssignment) {
                return $existing;
            }

            $assignment = new TaskAssignment;
            $assignment->forceFill([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'assignee_type' => $identity->type,
                'assignee_id' => (string) $identity->id,
                'assigned_by_type' => $actor->type,
                'assigned_by_id' => $actor->id === null ? null : (string) $actor->id,
            ]);
            $assignment->save();
            $current->forceFill(['revision' => $current->revision + 1])->save();

            return $assignment->refresh();
        });
    }
}
