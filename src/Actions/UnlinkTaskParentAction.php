<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Services\TaskGraphGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Removes a specified child-to-parent edge from the task hierarchy.
 *
 * @api
 */
final readonly class UnlinkTaskParentAction
{
    /** Construct the parent-unlink workflow. */
    public function __construct(private TaskGraphGuard $guard, private TasksActivity $activity) {}

    /** Remove a canonical parent edge after checking both expected revisions. */
    public function execute(
        Task|string $child,
        Task|string $parent,
        int $expectedChildRevision,
        int $expectedParentRevision,
        TaskActorData $actor,
    ): bool {
        $parentId = $parent instanceof Task ? $parent->id : $parent;
        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use (
            $child, $parent, $parentId, $expectedChildRevision, $expectedParentRevision, $actor,
        ): ?Task {
            [$canonicalChild, $canonicalParent] = $this->guard->lockPair(
                $child, $parent, $expectedChildRevision, $expectedParentRevision, $actor,
            );
            $relationship = TaskRelationship::query()
                ->where('child_task_id', $canonicalChild->id)
                ->where('parent_task_id', $canonicalParent->id)
                ->where('tenant_id', $canonicalChild->tenant_id)
                ->first();

            if ($relationship === null) {
                return null;
            }

            $relationship->delete();
            $this->guard->advanceRevisions($canonicalChild, $canonicalParent);
            $this->activity->parentUnlinked($canonicalChild, $parentId, $actor);

            return $canonicalChild;
        });

        return $changedTask instanceof Task;
    }
}
