<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nvl\Tasks\Data\Mutations\RemoveTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\TaskChecklistMutationGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Removes one item and closes its checklist position gap.
 *
 * @api
 */
final readonly class RemoveTaskChecklistItemAction
{
    /** Construct the checklist removal workflow. */
    public function __construct(private TaskChecklistMutationGuard $guard, private TasksActivity $activity) {}

    /** Remove one owned item, compact positions, and advance the task revision. */
    public function execute(
        Task|string $task,
        TaskChecklistItem|string $item,
        RemoveTaskChecklistItemData $data,
        TaskActorData $actor,
    ): Task {
        Validator::make(
            ['expectedRevision' => $data->expectedRevision],
            RemoveTaskChecklistItemData::rules(),
        )->validate();

        $itemId = $item instanceof TaskChecklistItem ? $item->id : $item;
        $updatedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $item, $itemId, $task): Task {
            $current = $this->guard->lock($task, $data->expectedRevision, $actor);
            $this->guard->item($current, $item)->delete();
            $remaining = TaskChecklistItem::query()
                ->where('task_id', $current->id)
                ->where('tenant_id', $current->tenant_id)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($remaining as $index => $checklistItem) {
                if ($checklistItem->position !== $index + 1) {
                    $checklistItem->forceFill(['position' => $index + 1])->save();
                }
            }

            $this->guard->increment($current);
            $this->activity->checklistItemRemoved($current, $itemId, $actor);

            return $current;
        });

        return $updatedTask;
    }
}
