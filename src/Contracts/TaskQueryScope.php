<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Models\Task;

/**
 * Optionally constrains task catalog queries through a host authorization adapter.
 *
 * @api
 */
interface TaskQueryScope
{
    /** Apply actor-owned visibility inside ownership scoping, before filters and pagination.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void;
}
