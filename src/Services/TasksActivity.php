<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/** Composes semantic task mutation events through the selected package-owned publisher. */
final readonly class TasksActivity
{
    /** Create the task event composition boundary. */
    public function __construct(private TaskActivityPublisher $publisher) {}

    /** Record a newly created task. */
    public function created(Task $task, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'created', $actor);
    }

    /** Record a task field replacement with inferred saved-model changes. */
    public function updated(Task $task, TaskActorData $actor): void
    {
        $changes = [];

        foreach ($task->getChanges() as $field => $value) {
            if ($field !== 'revision') {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->publisher->record($task, 'updated', $actor, attributes: $changes, old: array_intersect_key($task->getPrevious(), $changes));
    }

    /** Record a task's soft deletion. */
    public function deleted(Task $task, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'deleted', $actor);
    }

    /** Record restoration of a soft-deleted task. */
    public function restored(Task $task, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'restored', $actor);
    }

    /** Record a newly assigned principal. */
    public function assigned(Task $task, TaskActorData $actor, TaskActorData $assignee): void
    {
        $this->publisher->record($task, 'assigned', $actor, $this->assignmentContext($assignee));
    }

    /** Record removal of an assigned principal. */
    public function unassigned(Task $task, TaskActorData $actor, TaskActorData $assignee): void
    {
        $this->publisher->record($task, 'unassigned', $actor, $this->assignmentContext($assignee));
    }

    /** Record addition of a checklist row. */
    public function checklistItemAdded(Task $task, string $itemId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'checklist_item_added', $actor, ['item_id' => $itemId]);
    }

    /** Record a changed checklist title. */
    public function checklistItemUpdated(Task $task, string $itemId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'checklist_item_updated', $actor, ['item_id' => $itemId]);
    }

    /** Record a changed checklist completion state. */
    public function checklistItemCompletionChanged(Task $task, string $itemId, bool $completed, TaskActorData $actor): void
    {
        $this->publisher->record($task, $completed ? 'checklist_item_completed' : 'checklist_item_reopened', $actor, ['item_id' => $itemId]);
    }

    /** Record a changed checklist order. */
    public function checklistReordered(Task $task, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'checklist_reordered', $actor);
    }

    /** Record removal of a checklist row. */
    public function checklistItemRemoved(Task $task, string $itemId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'checklist_item_removed', $actor, ['item_id' => $itemId]);
    }

    /** Record addition of a normalized task tag. */
    public function tagAdded(Task $task, string $tag, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'tag_added', $actor, ['tag' => $tag]);
    }

    /** Record removal of a normalized task tag. */
    public function tagRemoved(Task $task, string $tag, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'tag_removed', $actor, ['tag' => $tag]);
    }

    /** Record addition of a manual time interval. */
    public function timeEntryAdded(Task $task, string $entryId, int $durationSeconds, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'time_entry_added', $actor, ['entry_id' => $entryId, 'duration_seconds' => $durationSeconds]);
    }

    /** Record correction of a manual time interval. */
    public function timeEntryUpdated(Task $task, string $entryId, int $durationSeconds, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'time_entry_updated', $actor, ['entry_id' => $entryId, 'duration_seconds' => $durationSeconds]);
    }

    /** Record removal of a time interval or timer. */
    public function timeEntryRemoved(Task $task, string $entryId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'time_entry_removed', $actor, ['entry_id' => $entryId]);
    }

    /** Record the start of a task timer. */
    public function timerStarted(Task $task, string $entryId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'timer_started', $actor, ['entry_id' => $entryId]);
    }

    /** Record a stopped timer and its server-calculated duration. */
    public function timerStopped(Task $task, string $entryId, int $durationSeconds, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'timer_stopped', $actor, ['entry_id' => $entryId, 'duration_seconds' => $durationSeconds]);
    }

    /** Record a new parent edge on the child task. */
    public function parentLinked(Task $child, string $parentId, TaskActorData $actor): void
    {
        $this->publisher->record($child, 'parent_linked', $actor, ['parent_task_id' => $parentId]);
    }

    /** Record removal of a parent edge on the child task. */
    public function parentUnlinked(Task $child, string $parentId, TaskActorData $actor): void
    {
        $this->publisher->record($child, 'parent_unlinked', $actor, ['parent_task_id' => $parentId]);
    }

    /** Record a new blocker edge on the blocked task. */
    public function blockerAdded(Task $task, string $blockerId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'blocker_added', $actor, ['blocker_task_id' => $blockerId]);
    }

    /** Record removal of a blocker edge on the blocked task. */
    public function blockerRemoved(Task $task, string $blockerId, TaskActorData $actor): void
    {
        $this->publisher->record($task, 'blocker_removed', $actor, ['blocker_task_id' => $blockerId]);
    }

    /** Return the safe identity context of an assignment target.
     *
     * @return array{assignee_type: string|null, assignee_id: string|null}
     */
    private function assignmentContext(TaskActorData $assignee): array
    {
        return [
            'assignee_type' => $assignee->type,
            'assignee_id' => $assignee->id === null ? null : (string) $assignee->id,
        ];
    }
}
