<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Tasks\Contracts\LinkTaskParentContract;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Services\TaskGraphGuard;
use Nvl\Tasks\Services\TasksActivity;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Links one child task to its single parent within the active tenant.
 *
 * @api
 */
final readonly class LinkTaskParentAction implements LinkTaskParentContract
{
    /** Construct the parent-link workflow. */
    public function __construct(private TaskGraphGuard $guard, private TasksActivity $activity) {}

    /** Link canonical tasks after checking both expected revisions. */
    public function execute(
        Task|string $child,
        Task|string $parent,
        int $expectedChildRevision,
        int $expectedParentRevision,
        TaskActorData $actor,
    ): TaskRelationship {
        $relationship = DB::connection(TasksConfiguration::connection())->transaction(function () use (
            $child, $parent, $expectedChildRevision, $expectedParentRevision, $actor,
        ): TaskRelationship {
            [$canonicalChild, $canonicalParent] = $this->guard->lockPair(
                $child, $parent, $expectedChildRevision, $expectedParentRevision, $actor,
            );
            $existing = TaskRelationship::query()->where('child_task_id', $canonicalChild->id)->first();

            if ($existing !== null) {
                if ($existing->parent_task_id !== $canonicalParent->id
                    || $existing->tenant_id !== $canonicalChild->tenant_id) {
                    throw new InvalidArgumentException('The child already has another parent.');
                }

                return $existing;
            }

            $this->guard->assertAcyclicParent($canonicalChild, $canonicalParent);
            $relationship = new TaskRelationship;
            $relationship->forceFill([
                'tenant_id' => $canonicalChild->tenant_id,
                'child_task_id' => $canonicalChild->id,
                'parent_task_id' => $canonicalParent->id,
            ]);
            $relationship->save();
            $this->guard->advanceRevisions($canonicalChild, $canonicalParent);
            $this->activity->parentLinked($canonicalChild, $canonicalParent->id, $actor);

            return $relationship->refresh();
        });

        return $relationship;
    }
}
