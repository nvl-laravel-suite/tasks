<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Defines the supported start task timer workflow.
 *
 * @api
 */
interface StartTaskTimerContract
{
    /** Start a timer at server time if the performer has none running. */
    public function execute(Task|string $task, int $expectedRevision, TaskActorData $actor): TaskTimeEntry;
}
