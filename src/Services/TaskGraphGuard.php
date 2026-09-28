<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use InvalidArgumentException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tenancy\Services\TenantBoundary;

/** Protects task graph mutations with canonical endpoint, tenant, revision and cycle checks. */
final readonly class TaskGraphGuard
{
    /** Construct the task graph boundary. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
    ) {}

    /** Lock and authorize two canonical task endpoints in stable key order.
     *
     * @return array{Task, Task}
     */
    public function lockPair(
        Task|string $first,
        Task|string $second,
        int $expectedFirstRevision,
        int $expectedSecondRevision,
        TaskActorData $actor,
    ): array {
        $firstId = $first instanceof Task ? $first->getKey() : $first;
        $secondId = $second instanceof Task ? $second->getKey() : $second;

        if (! is_string($firstId) || $firstId === ''
            || ! is_string($secondId) || $secondId === '') {
            throw new InvalidArgumentException('Task links require string task IDs.');
        }

        if ($firstId === $secondId) {
            throw new InvalidArgumentException('A task cannot link to itself.');
        }

        $ids = [$firstId, $secondId];
        sort($ids, SORT_STRING);
        $locked = [];

        foreach ($ids as $id) {
            $locked[$id] = $this->boundary->query(Task::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
        }

        $canonicalFirst = $locked[$firstId];
        $canonicalSecond = $locked[$secondId];

        if ($canonicalFirst->tenant_id !== $canonicalSecond->tenant_id) {
            throw new InvalidArgumentException('Linked tasks must belong to the same tenant.');
        }

        $this->authorization->authorize(TaskAbility::Update, $actor, $canonicalFirst);
        $this->authorization->authorize(TaskAbility::Update, $actor, $canonicalSecond);

        if ($canonicalFirst->revision !== $expectedFirstRevision
            || $canonicalSecond->revision !== $expectedSecondRevision) {
            throw new TaskRevisionConflict;
        }

        return [$canonicalFirst, $canonicalSecond];
    }

    /** Reject a proposed parent edge when it reaches its own child. */
    public function assertAcyclicParent(Task $child, Task $parent): void
    {
        $ancestorId = $parent->id;
        $seen = [];

        while (true) {
            if ($ancestorId === $child->id || isset($seen[$ancestorId])) {
                throw new InvalidArgumentException('The parent link would create a task cycle.');
            }

            $seen[$ancestorId] = true;
            $relationship = TaskRelationship::query()
                ->where('child_task_id', $ancestorId)
                ->where('tenant_id', $child->tenant_id)
                ->first();

            if ($relationship === null) {
                return;
            }

            $ancestorId = $relationship->parent_task_id;
        }
    }

    /** Reject a proposed blocker edge when its blocker already waits on the task. */
    public function assertAcyclicDependency(Task $task, Task $blocker): void
    {
        $pending = [$blocker->id];
        $seen = [];

        while ($pending !== []) {
            $candidate = array_pop($pending);

            if (! is_string($candidate) || $candidate === '') {
                throw new InvalidArgumentException('Task dependencies require string task IDs.');
            }

            if ($candidate === $task->id) {
                throw new InvalidArgumentException('The dependency would create a task cycle.');
            }

            if (isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $dependencies = TaskDependency::query()
                ->where('task_id', $candidate)
                ->where('tenant_id', $task->tenant_id)
                ->pluck('blocker_task_id');

            foreach ($dependencies as $blockerId) {
                $pending[] = $blockerId;
            }
        }
    }

    /** Advance both endpoint revisions after a graph edge changes. */
    public function advanceRevisions(Task $first, Task $second): void
    {
        foreach ([$first, $second] as $task) {
            $task->forceFill(['revision' => $task->revision + 1])->save();
        }
    }
}
