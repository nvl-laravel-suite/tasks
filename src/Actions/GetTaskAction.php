<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskReadGuard;

/**
 * Resolves one tenant-visible task for a permitted caller.
 *
 * @api
 */
final readonly class GetTaskAction
{
    /** Construct the task read boundary. */
    public function __construct(private TaskReadGuard $guard) {}

    /** Return one authorized task without loading unbounded associations. */
    public function execute(Task|string $task, TaskActorData $actor): Task
    {
        return $this->guard->resolve($task, $actor);
    }
}
