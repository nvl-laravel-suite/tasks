<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nvl\Tasks\Data\Mutations\AddTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\TaskChecklistMutationGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/** Appends one checklist item to a canonical task. */
final readonly class AddTaskChecklistItemAction
{
    /** Construct the checklist addition workflow. */
    public function __construct(private TaskChecklistMutationGuard $guard, private TasksActivity $activity) {}

    /** Append a validated item and advance the task revision. */
    public function execute(Task|string $task, AddTaskChecklistItemData $data, TaskActorData $actor): TaskChecklistItem
    {
        $title = trim($data->title);
        Validator::make(
            ['title' => $title, 'expectedRevision' => $data->expectedRevision],
            AddTaskChecklistItemData::rules(),
        )->validate();

        $item = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $task, $title): TaskChecklistItem {
            $current = $this->guard->lock($task, $data->expectedRevision, $actor);
            $count = TaskChecklistItem::query()
                ->where('task_id', $current->id)
                ->where('tenant_id', $current->tenant_id)
                ->count();

            $item = new TaskChecklistItem;
            $item->forceFill([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'title' => $title,
                'position' => $count + 1,
            ])->save();
            $this->guard->increment($current);

            $this->activity->checklistItemAdded($current, $item->id, $actor);

            return $item->refresh();
        });

        return $item;
    }
}
