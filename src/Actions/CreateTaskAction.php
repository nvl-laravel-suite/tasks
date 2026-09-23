<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskMutationValues;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Creates one task within the active tenant and caller authorization boundary. */
final readonly class CreateTaskAction
{
    /** Construct the task creation workflow. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
        private TaskMutationValues $values,
    ) {}

    /** Persist a validated task and return its post-write state. */
    public function execute(CreateTaskData $data, TaskActorData $actor): Task
    {
        $this->authorization->authorize(TaskAbility::Create, $actor);
        $values = $this->values->create($data);

        return DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $values): Task {
            $task = new Task;
            $task->forceFill([
                ...$values,
                ...$this->boundary->attributes(Task::TENANT_RESOURCE),
                'creator_type' => $actor->type,
                'creator_id' => $actor->id === null ? null : (string) $actor->id,
            ]);
            $task->save();

            return $task->refresh();
        });
    }
}
