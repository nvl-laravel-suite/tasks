<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Removes one assignment only from a task in the active tenant.
 *
 * @api
 */
final readonly class UnassignTaskAction
{
    /** Construct the assignment-removal workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Remove an assignee and report whether an assignment changed. */
    public function execute(Task|string $task, Model&Authenticatable $assignee, TaskActorData $actor): bool
    {
        $identity = TaskActorData::fromAuthenticatable($assignee);
        $id = $task instanceof Task ? $task->getKey() : $task;

        $changed = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $assignee, $id, $identity): ?Task {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Assign, $actor, $current, $assignee);
            $assignment = $current->assignments()
                ->where('assignee_type', $identity->type)
                ->where('assignee_id', (string) $identity->id)
                ->first();

            if ($assignment === null) {
                return null;
            }

            $assignment->delete();
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->unassigned($current, $actor, $identity);

            return $current;
        });

        return $changed instanceof Task;
    }
}
