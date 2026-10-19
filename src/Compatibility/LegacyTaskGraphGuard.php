<?php

declare(strict_types=1);

namespace Nvl\Tasks\Compatibility;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;

/** Retains legacy field consistency without imposing it on adopted native ownership. @internal */
final readonly class LegacyTaskGraphGuard
{
    /** Retain immutable resource classifications only. */
    public function __construct(private OwnershipResourceRegistry $resources) {}

    /** Preserve the original standalone and legacy graph endpoint invariant. */
    public function assertPair(Task $first, Task $second): void
    {
        if ($this->legacy($first) && $first->tenant_id !== $second->tenant_id) {
            throw new InvalidArgumentException('Linked tasks must belong to the same tenant.');
        }
    }

    /**
     * @template T of TaskDependency|TaskRelationship
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function restrict(Builder $query, Task $task): Builder
    {
        return $this->legacy($task) ? $query->where('tenant_id', $task->tenant_id) : $query;
    }

    /** Distinguish the frozen deployment classification from mutable request values. */
    private function legacy(Task $task): bool
    {
        return $this->resources->forModel($task)->ownership->strategy === 'unscoped';
    }
}
