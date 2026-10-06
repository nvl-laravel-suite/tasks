<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nvl\Tasks\Contracts\ToggleTaskChecklistItemContract;
use Nvl\Tasks\Data\Mutations\ToggleTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Services\TaskChecklistMutationGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Sets completion state and records the completing principal.
 *
 * @api
 */
final readonly class ToggleTaskChecklistItemAction implements ToggleTaskChecklistItemContract
{
    /** Construct the checklist completion workflow. */
    public function __construct(private TaskChecklistMutationGuard $guard, private TasksActivity $activity) {}

    /** Set item completion and advance the task revision. */
    public function execute(
        Task|string $task,
        TaskChecklistItem|string $item,
        ToggleTaskChecklistItemData $data,
        TaskActorData $actor,
    ): TaskChecklistItem {
        Validator::make(
            ['completed' => $data->completed, 'expectedRevision' => $data->expectedRevision],
            ToggleTaskChecklistItemData::rules(),
        )->validate();

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $data, $item, $task): TaskChecklistItem {
            $current = $this->guard->lock($task, $data->expectedRevision, $actor);
            $checklistItem = $this->guard->item($current, $item);
            $changed = ($checklistItem->completed_at !== null) !== $data->completed;

            if ($changed) {
                $checklistItem->forceFill([
                    'completed_at' => $data->completed ? CarbonImmutable::now() : null,
                    'completed_by_type' => $data->completed ? $actor->type : null,
                    'completed_by_id' => $data->completed && $actor->id !== null ? (string) $actor->id : null,
                ])->save();
                $this->guard->increment($current);
                $this->activity->checklistItemCompletionChanged($current, $checklistItem->id, $data->completed, $actor);
            }

            return $checklistItem;
        });

        return $updated->refresh();
    }
}
