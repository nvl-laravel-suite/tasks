<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** Host-owned lookup of an assignee named by an opt-in HTTP request. */
interface TaskPrincipalResolver
{
    /** Resolve only a persisted principal visible to the current caller.
     *
     */
    public function resolve(string $identifier): Model&Authenticatable;
}
