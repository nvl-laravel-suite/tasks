<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

/** Attaches one normalized task tag within a bounded task label set. */
final readonly class AddTaskTagAction
{
    /** Construct the task-tag addition workflow. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary, private TasksActivity $activity) {}

    /** Add a tag once and advance the task revision only for a new row. */
    public function execute(Task|string $task, TaskTagMutationData $data, TaskActorData $actor): TaskTag
    {
        $id = $task instanceof Task ? $task->getKey() : $task;

        $record = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $id): TaskTag {
            $current = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $data->expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $tag = $data->normalizedTag();
            $existing = TaskTag::query()->where('task_id', $current->id)->where('tag', $tag)->first();

            if ($existing instanceof TaskTag) {
                return $existing;
            }

            if (TaskTag::query()->where('task_id', $current->id)->count() >= 20) {
                throw ValidationException::withMessages(['tag' => 'A task may have at most twenty tags.']);
            }

            $record = new TaskTag;
            $record->forceFill([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'tag' => $tag,
            ])->save();
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->tagAdded($current, $tag, $actor);

            return $record->refresh();
        });

        return $record;
    }
}
