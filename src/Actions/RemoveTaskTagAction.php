<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/** Detaches one normalized tag from a canonical task. */
final readonly class RemoveTaskTagAction
{
    /** Construct the task-tag removal workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Remove a tag and advance the task revision only when a row existed. */
    public function execute(Task|string $task, TaskTagMutationData $data, TaskActorData $actor): bool
    {
        $id = $task instanceof Task ? $task->getKey() : $task;

        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $id): ?Task {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $data->expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $tag = $data->normalizedTag();
            $removed = TaskTag::query()->where('task_id', $current->id)
                ->where('tag', $tag)->delete() > 0;

            if ($removed) {
                $current->forceFill(['revision' => $current->revision + 1])->save();
                $this->activity->tagRemoved($current, $tag, $actor);
            }

            return $removed ? $current : null;
        });

        return $changedTask instanceof Task;
    }
}
