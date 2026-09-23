<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;

/** Fails closed until an app supplies a principal lookup for HTTP assignment. */
final class ConfiguredTaskPrincipalResolver implements TaskPrincipalResolver
{
    /** Reject an unconfigured assignee lookup.
     *
     */
    public function resolve(string $identifier): Model&Authenticatable
    {
        throw new AuthorizationException('Task assignment requires a consumer principal resolver.');
    }
}
