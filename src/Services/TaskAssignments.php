<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Support\TaskIdentifier;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Native TaskAssignments operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskAssignments
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskAuthorization $authorization, private OwnershipBoundary $boundary, private TasksActivity $activity, private TaskRecordWriter $records, private TaskPrincipalGuard $principals) {}

    /** Add one assignee without duplicating an existing assignment. */
    public function assign(Task|string $task, Model&Authenticatable $assignee, Authenticatable|SystemActor $actor): TaskAssignment
    {
        $actor = $this->principals->actor($actor);
        $id = TaskIdentifier::of($task, Task::class);

        $assignment = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $assignee, $id): TaskAssignment {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $assignee = $this->principals->resolve($assignee);
            $identity = $assignee;
            $this->authorization->authorize(TaskAbility::Assign, $actor, $current, $assignee);
            $existing = $current->assignments()
                ->where('assignee_type', TaskPrincipalGuard::type($identity))
                ->where('assignee_id', (string) TaskPrincipalGuard::id($identity))
                ->first();

            if ($existing instanceof TaskAssignment) {
                return $existing;
            }

            $assignment = $this->records->assignment([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'assignee_type' => TaskPrincipalGuard::type($identity),
                'assignee_id' => (string) TaskPrincipalGuard::id($identity),
                'assigned_by_type' => TaskPrincipalGuard::type($actor),
                'assigned_by_id' => TaskPrincipalGuard::id($actor) === null ? null : (string) TaskPrincipalGuard::id($actor),
            ]);
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->assigned($current, $actor, $identity);

            return $assignment;
        });

        return $assignment->refresh();
    }

    /** Remove an assignee and report whether an assignment changed. */
    public function unassign(Task|string $task, Model&Authenticatable $assignee, Authenticatable|SystemActor $actor): bool
    {
        $actor = $this->principals->actor($actor);
        $id = TaskIdentifier::of($task, Task::class);

        $changed = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $assignee, $id): ?Task {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $identity = $this->principals->resolve($assignee);
            $this->authorization->authorize(TaskAbility::Assign, $actor, $current, $identity);
            $assignment = $current->assignments()
                ->where('assignee_type', TaskPrincipalGuard::type($identity))
                ->where('assignee_id', (string) TaskPrincipalGuard::id($identity))
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
