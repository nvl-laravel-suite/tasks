<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Native TaskChecklist operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskChecklist
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskChecklistMutationGuard $guard, private TasksActivity $activity, private TaskRecordWriter $records, private TaskPrincipalGuard $principals) {}

    /** Append a validated item and advance the task revision. */
    public function add(Task|string $task, string $title, int $expectedRevision, Authenticatable|SystemActor $actor): TaskChecklistItem
    {
        $actor = $this->principals->actor($actor);
        $title = trim($title);
        TaskMutationValues::text($title, 'title');
        Validator::make(
            ['title' => $title, 'expected_revision' => $expectedRevision],
            ['title' => ['required', 'string', 'max:255'], 'expected_revision' => ['required', 'integer', 'min:1']],
        )->validate();

        $item = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $task, $title): TaskChecklistItem {
            $current = $this->guard->lock($task, $expectedRevision, $actor);
            $count = TaskChecklistItem::query()
                ->where('task_id', $current->id)
                ->count();

            $item = $this->records->checklistItem([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'title' => $title,
                'position' => $count + 1,
            ]);
            $this->guard->increment($current);

            $this->activity->checklistItemAdded($current, $item->id, $actor);

            return $item->refresh();
        });

        return $item;
    }

    /** Replace a validated item title and advance the task revision when it changes. */
    public function update(
        Task|string $task,
        TaskChecklistItem|string $item,
        string $title, int $expectedRevision,
        Authenticatable|SystemActor $actor,
    ): TaskChecklistItem {
        $actor = $this->principals->actor($actor);
        $title = trim($title);
        TaskMutationValues::text($title, 'title');
        Validator::make(
            ['title' => $title, 'expected_revision' => $expectedRevision],
            ['title' => ['required', 'string', 'max:255'], 'expected_revision' => ['required', 'integer', 'min:1']],
        )->validate();

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $item, $task, $title): TaskChecklistItem {
            $current = $this->guard->lock($task, $expectedRevision, $actor);
            $checklistItem = $this->guard->item($current, $item);
            $checklistItem->forceFill(['title' => $title])->save();

            if ($checklistItem->wasChanged('title')) {
                $this->guard->increment($current);
                $this->activity->checklistItemUpdated($current, $checklistItem->id, $actor);
            }

            return $checklistItem;
        });

        return $updated->refresh();
    }

    /** Set item completion and advance the task revision. */
    public function toggle(
        Task|string $task,
        TaskChecklistItem|string $item,
        bool $completed, int $expectedRevision,
        Authenticatable|SystemActor $actor,
    ): TaskChecklistItem {
        $actor = $this->principals->actor($actor);
        Validator::make(
            ['completed' => $completed, 'expected_revision' => $expectedRevision],
            ['expected_revision' => ['required', 'integer', 'min:1']],
        )->validate();

        $updated = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $completed, $item, $task): TaskChecklistItem {
            $current = $this->guard->lock($task, $expectedRevision, $actor);
            $checklistItem = $this->guard->item($current, $item);
            $changed = ($checklistItem->completed_at !== null) !== $completed;

            if ($changed) {
                $checklistItem->forceFill([
                    'completed_at' => $completed ? CarbonImmutable::now() : null,
                    'completed_by_type' => $completed ? TaskPrincipalGuard::type($actor) : null,
                    'completed_by_id' => $completed && TaskPrincipalGuard::id($actor) !== null ? (string) TaskPrincipalGuard::id($actor) : null,
                ])->save();
                $this->guard->increment($current);
                $this->activity->checklistItemCompletionChanged($current, $checklistItem->id, $completed, $actor);
            }

            return $checklistItem;
        });

        return $updated->refresh();
    }

    /** Reorder exactly the task's existing items and advance its revision when the order changes.
     * @param  list<string>  $itemIds
     */
    public function reorder(Task|string $task, array $itemIds, int $expectedRevision, Authenticatable|SystemActor $actor): Task
    {
        $actor = $this->principals->actor($actor);
        Validator::make(
            ['itemIds' => $itemIds, 'expected_revision' => $expectedRevision],
            ['itemIds' => ['required', 'array', 'list', 'min:1'], 'itemIds.*' => ['required', 'uuid', 'distinct:strict'], 'expected_revision' => ['required', 'integer', 'min:1']],
        )->validate();

        $reorderedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $itemIds, $task): Task {
            $current = $this->guard->lock($task, $expectedRevision, $actor);
            $items = TaskChecklistItem::query()
                ->where('task_id', $current->id)
                ->get()
                ->keyBy('id');
            $existingIds = $items->keys()->all();
            $requestedIds = $itemIds;
            sort($existingIds);
            sort($requestedIds);

            if ($existingIds !== $requestedIds) {
                throw ValidationException::withMessages(['itemIds' => 'The order must include every checklist item exactly once.']);
            }

            $changed = false;

            foreach ($itemIds as $index => $id) {
                $item = $items->get($id);

                if (! $item instanceof TaskChecklistItem) {
                    throw ValidationException::withMessages(['itemIds' => 'The order contains a missing checklist item.']);
                }

                $changed = $changed || $item->position !== $index + 1;
                $item->forceFill(['position' => $index + 1])->save();
            }

            if ($changed) {
                $this->guard->increment($current);
                $this->activity->checklistReordered($current, $actor);
            }

            return $current;
        });

        return $reorderedTask;
    }

    /** Remove one owned item, compact positions, and advance the task revision. */
    public function remove(
        Task|string $task,
        TaskChecklistItem|string $item,
        int $expectedRevision,
        Authenticatable|SystemActor $actor,
    ): Task {
        $actor = $this->principals->actor($actor);
        Validator::make(
            ['expected_revision' => $expectedRevision],
            ['expected_revision' => ['required', 'integer', 'min:1']],
        )->validate();

        $itemId = $item instanceof TaskChecklistItem ? $item->id : $item;
        $updatedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $expectedRevision, $item, $itemId, $task): Task {
            $current = $this->guard->lock($task, $expectedRevision, $actor);
            $this->guard->item($current, $item)->delete();
            $remaining = TaskChecklistItem::query()
                ->where('task_id', $current->id)
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
