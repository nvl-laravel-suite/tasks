<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskMutationValues;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Services\TenantBoundary;

/** Replaces a task after checking its canonical tenant and exact revision. */
final readonly class UpdateTaskAction
{
    /** Construct the task replacement workflow. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
        private TaskMutationValues $values,
    ) {}

    /** Replace editable fields while rejecting stale client state. */
    public function execute(Task|string $task, UpdateTaskData $data, TaskActorData $actor): Task
    {
        $id = $task instanceof Task ? $task->getKey() : $task;

        return DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $id): Task {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $data->expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $current->forceFill([
                ...$this->values->replace($data, $current->completed_at),
                'revision' => $current->revision + 1,
            ]);
            $current->save();

            return $current->refresh();
        });
    }
}
