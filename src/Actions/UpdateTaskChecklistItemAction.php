<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nvl\Tasks\Data\Mutations\UpdateTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\TaskChecklistMutationGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Replaces the title of one checklist item.
 *
 * @api
 */
final readonly class UpdateTaskChecklistItemAction
{
    /** Construct the checklist title workflow. */
    public function __construct(private TaskChecklistMutationGuard $guard, private TasksActivity $activity) {}

    /** Replace a validated item title and advance the task revision. */
    public function execute(
        Task|string $task,
        TaskChecklistItem|string $item,
        UpdateTaskChecklistItemData $data,
        TaskActorData $actor,
    ): TaskChecklistItem {
        $title = trim($data->title);
        Validator::make(
            ['title' => $title, 'expectedRevision' => $data->expectedRevision],
            UpdateTaskChecklistItemData::rules(),
        )->validate();

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $item, $task, $title): TaskChecklistItem {
            $current = $this->guard->lock($task, $data->expectedRevision, $actor);
            $checklistItem = $this->guard->item($current, $item);
            $checklistItem->forceFill(['title' => $title])->save();
            $this->guard->increment($current);

            if ($checklistItem->wasChanged('title')) {
                $this->activity->checklistItemUpdated($current, $checklistItem->id, $actor);
            }

            return $checklistItem;
        });

        return $updated->refresh();
    }
}
