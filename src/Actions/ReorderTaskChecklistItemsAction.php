<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Data\Mutations\ReorderTaskChecklistItemsData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\TaskChecklistMutationGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Applies a complete, unambiguous order to one task checklist.
 *
 * @api
 */
final readonly class ReorderTaskChecklistItemsAction
{
    /** Construct the checklist ordering workflow. */
    public function __construct(private TaskChecklistMutationGuard $guard, private TasksActivity $activity) {}

    /** Reorder exactly the task's existing items and advance its revision. */
    public function execute(Task|string $task, ReorderTaskChecklistItemsData $data, TaskActorData $actor): Task
    {
        Validator::make(
            ['itemIds' => $data->itemIds, 'expectedRevision' => $data->expectedRevision],
            ReorderTaskChecklistItemsData::rules(),
        )->validate();

        $reorderedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $task): Task {
            $current = $this->guard->lock($task, $data->expectedRevision, $actor);
            $items = TaskChecklistItem::query()
                ->where('task_id', $current->id)
                ->where('tenant_id', $current->tenant_id)
                ->get()
                ->keyBy('id');
            $existingIds = $items->keys()->all();
            $requestedIds = $data->itemIds;
            sort($existingIds);
            sort($requestedIds);

            if ($existingIds !== $requestedIds) {
                throw ValidationException::withMessages(['itemIds' => 'The order must include every checklist item exactly once.']);
            }

            $changed = false;

            foreach ($data->itemIds as $index => $id) {
                $item = $items->get($id);

                if (! $item instanceof TaskChecklistItem) {
                    throw ValidationException::withMessages(['itemIds' => 'The order contains a missing checklist item.']);
                }

                $changed = $changed || $item->position !== $index + 1;
                $item->forceFill(['position' => $index + 1])->save();
            }

            $this->guard->increment($current);

            if ($changed) {
                $this->activity->checklistReordered($current, $actor);
            }

            return $current;
        });

        return $reorderedTask;
    }
}
