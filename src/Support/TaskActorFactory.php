<?php

declare(strict_types=1);

namespace Nvl\Tasks\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Nvl\Tasks\Data\TaskActorData;

/** Adapts the authenticated host principal for the opt-in HTTP surface. */
final class TaskActorFactory
{
    /** Resolve one persisted caller identity without trusting request body fields. */
    public function fromRequest(Request $request): TaskActorData
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        return TaskActorData::fromAuthenticatable($user);
    }
}
