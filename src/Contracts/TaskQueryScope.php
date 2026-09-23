<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/** Optionally constrains task catalog queries through a host authorization adapter. */
interface TaskQueryScope
{
    /** Apply actor-owned visibility before tenant scoping, filters, and pagination.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeTasks(Builder $query, TaskActorData $actor): void;
}
