<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Defines the supported stop task timer workflow.
 *
 * @api
 */
interface StopTaskTimerContract
{
    /** Stop one running timer and advance the exact task revision. */
    public function execute(Task|string $task, TaskTimeEntry|string $entry, int $expectedRevision, TaskActorData $actor): TaskTimeEntry;
}
