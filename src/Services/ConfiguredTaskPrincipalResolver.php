<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;

/** Fails closed until an app supplies a principal lookup for HTTP assignment. */
final class ConfiguredTaskPrincipalResolver implements TaskPrincipalResolver
{
    /** Reject an unconfigured assignee lookup.
     *
     */
    public function resolve(string $identifier): Model&Authenticatable
    {
        throw BindingRequiredException::for('tasks', TaskPrincipalResolver::class, 'http_assignment');
    }
}
