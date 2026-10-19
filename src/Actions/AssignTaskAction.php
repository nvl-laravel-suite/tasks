<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\AssignTaskContract;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Services\TaskModelRegistry;
use Nvl\Tasks\Services\TaskPrincipalGuard;
use Nvl\Tasks\Services\TaskRecordWriter;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Idempotently assigns a canonical task to a persisted host principal.
 *
 * @api
 */
final readonly class AssignTaskAction implements AssignTaskContract
{
    /** Construct the assignment workflow. */
    public function __construct(private TaskAuthorization $authorization, private OwnershipBoundary $boundary, private TasksActivity $activity, private TaskRecordWriter $records, private TaskPrincipalGuard $principals) {}

    /** Add one assignee without duplicating an existing assignment. */
    public function execute(Task|string $task, Model&Authenticatable $assignee, TaskActorData $actor): TaskAssignment
    {
        $id = $task instanceof Task ? $task->getKey() : $task;

        $assignment = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $assignee, $id): TaskAssignment {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $assignee = $this->principals->resolve($assignee);
            $identity = TaskActorData::fromAuthenticatable($assignee);
            $this->authorization->authorize(TaskAbility::Assign, $actor, $current, $assignee);
            $existing = $current->assignments()
                ->where('assignee_type', $identity->type)
                ->where('assignee_id', (string) $identity->id)
                ->first();

            if ($existing instanceof TaskAssignment) {
                return $existing;
            }

            $assignment = $this->records->assignment([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'assignee_type' => $identity->type,
                'assignee_id' => (string) $identity->id,
                'assigned_by_type' => $actor->type,
                'assigned_by_id' => $actor->id === null ? null : (string) $actor->id,
            ]);
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->assigned($current, $actor, $identity);

            return $assignment;
        });

        return $assignment->refresh();
    }
}
