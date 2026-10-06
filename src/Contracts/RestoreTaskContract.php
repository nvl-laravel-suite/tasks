<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported restore task workflow.
 *
 * @api
 */
interface RestoreTaskContract
{
    /** Restore a tenant-visible task only at its exact deleted revision. */
    public function execute(Task|string $task, int $expectedRevision, TaskActorData $actor): Task;
}
