<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

/** Semantic committed task operations.
 *
 * @api
 */
enum TaskChangeOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case ChecklistItemAdded = 'checklist_item_added';
    case ChecklistItemUpdated = 'checklist_item_updated';
    case ChecklistItemCompleted = 'checklist_item_completed';
    case ChecklistItemReopened = 'checklist_item_reopened';
    case ChecklistReordered = 'checklist_reordered';
    case ChecklistItemRemoved = 'checklist_item_removed';
    case TagAdded = 'tag_added';
    case TagRemoved = 'tag_removed';
    case TimeEntryAdded = 'time_entry_added';
    case TimeEntryUpdated = 'time_entry_updated';
    case TimeEntryRemoved = 'time_entry_removed';
    case TimerStarted = 'timer_started';
    case TimerStopped = 'timer_stopped';
    case ParentLinked = 'parent_linked';
    case ParentUnlinked = 'parent_unlinked';
    case BlockerAdded = 'blocker_added';
    case BlockerRemoved = 'blocker_removed';
}
