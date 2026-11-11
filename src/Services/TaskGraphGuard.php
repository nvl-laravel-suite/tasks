<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Support\TaskIdentifier;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Protects task graph mutations with canonical endpoint, ownership, revision and cycle checks.
 *
 * @internal
 */
final readonly class TaskGraphGuard
{
    /** The single serialization row seeded by the graph-lock migration. */
    public const string LOCK_ID = '00000000-0000-4000-8000-000000000001';

    /** Construct the task graph boundary. */
    public function __construct(
        private TaskAuthorization $authorization,
        private OwnershipBoundary $boundary,
    ) {}

    /** Lock and authorize canonical endpoints in stable order after versioning the mutex.
     * Earlier PostgreSQL repeatable-read snapshots must retry their outer transaction.
     *
     * @return array{Task, Task}
     */
    public function lockPair(
        Task|string $first,
        Task|string $second,
        int $expectedFirstRevision,
        int $expectedSecondRevision,
        Authenticatable|SystemActor $actor,
    ): array {
        $firstId = TaskIdentifier::of($first, Task::class);
        $secondId = TaskIdentifier::of($second, Task::class);

        if ($firstId === $secondId) {
            throw new InvalidArgumentException('A task cannot link to itself.');
        }

        $connection = DB::connection(TasksConfiguration::connection());
        $lock = $connection
            ->table(TasksTables::get(TasksTables::GraphLock))
            ->where('id', self::LOCK_ID)
            ->lockForUpdate()->first();
        if ($lock === null) {
            throw new \LogicException('Tasks graph lock is missing; apply the Tasks graph-lock migration before graph mutations.');
        }
        $connection->table(TasksTables::get(TasksTables::GraphLock))
            ->where('id', self::LOCK_ID)
            ->update(['id' => self::LOCK_ID]);

        $ids = [$firstId, $secondId];
        sort($ids, SORT_STRING);
        $locked = [];

        foreach ($ids as $id) {
            $locked[$id] = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
        }

        $canonicalFirst = $locked[$firstId];
        $canonicalSecond = $locked[$secondId];

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
        [$maximumVertices, $maximumEdges] = $this->graphBudgets();
        $ancestorId = $parent->id;
        $seen = [$child->id => true];
        $edges = 1;

        while (true) {
            if ($ancestorId === $child->id || isset($seen[$ancestorId])) {
                throw new InvalidArgumentException('The parent link would create a task cycle.');
            }

            if (count($seen) >= $maximumVertices) {
                throw new InvalidArgumentException('The task graph exceeds its configured vertex budget.');
            }
            $seen[$ancestorId] = true;
            $relationship = TaskRelationship::query()
                ->where('child_task_id', $ancestorId)
                ->orderBy('id')->lockForUpdate()
                ->first();

            if ($relationship === null) {
                return;
            }
            if (++$edges > $maximumEdges) {
                throw new InvalidArgumentException('The task graph exceeds its configured edge budget.');
            }

            $ancestorId = $relationship->parent_task_id;
        }
    }

    /** Reject a proposed blocker edge when its blocker already waits on the task. */
    public function assertAcyclicDependency(Task $task, Task $blocker): void
    {
        [$maximumVertices, $maximumEdges] = $this->graphBudgets();
        $pending = [$blocker->id];
        $seen = [$task->id => true];
        $edges = 1;

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

            if (count($seen) >= $maximumVertices) {
                throw new InvalidArgumentException('The task graph exceeds its configured vertex budget.');
            }
            $seen[$candidate] = true;
            $dependencies = TaskDependency::query()
                ->where('task_id', $candidate)
                ->orderBy('blocker_task_id')->lockForUpdate()
                ->limit($maximumEdges - $edges + 1)
                ->pluck('blocker_task_id');
            $edges += $dependencies->count();
            if ($edges > $maximumEdges) {
                throw new InvalidArgumentException('The task graph exceeds its configured edge budget.');
            }

            foreach ($dependencies as $blockerId) {
                $pending[] = $blockerId;
            }
        }
    }

    /** @return array{int, int} */
    private function graphBudgets(): array
    {
        $vertices = TasksConfiguration::limit('maximum_graph_vertices', 1000);
        $edges = TasksConfiguration::limit('maximum_graph_edges', 5000);
        if ($vertices > 100000 || $edges > 1000000) {
            throw new InvalidArgumentException('Task graph budgets cannot exceed 100,000 vertices or 1,000,000 edges.');
        }

        return [$vertices, $edges];
    }

    /** Advance both endpoint revisions after a graph edge changes. */
    public function advanceRevisions(Task $first, Task $second): void
    {
        foreach ([$first, $second] as $task) {
            $task->forceFill(['revision' => $task->revision + 1])->save();
        }
    }
}
