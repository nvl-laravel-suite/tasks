<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\CreateTaskContract;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskMutationValues;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Creates one task within the active tenant and caller authorization boundary.
 *
 * @api
 */
final readonly class CreateTaskAction implements CreateTaskContract
{
    /** Construct the task creation workflow. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
        private TaskMutationValues $values,
        private TasksActivity $activity,
    ) {}

    /** Persist a validated task and return its post-write state. */
    public function execute(CreateTaskData $data, TaskActorData $actor): Task
    {
        $this->authorization->authorize(TaskAbility::Create, $actor);
        $values = $this->values->create($data);

        $task = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $values): Task {
            $task = new Task;
            $task->forceFill([
                ...$values,
                ...$this->boundary->attributes(Task::TENANT_RESOURCE),
                'creator_type' => $actor->type,
                'creator_id' => $actor->id === null ? null : (string) $actor->id,
            ]);
            $task->save();
            $this->activity->created($task, $actor);

            return $task;
        });

        return $task->refresh();
    }
}
