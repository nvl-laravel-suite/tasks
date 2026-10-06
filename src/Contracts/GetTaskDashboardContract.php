<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskDashboardData;

/**
 * Defines the supported get task dashboard workflow.
 *
 * @api
 */
interface GetTaskDashboardContract
{
    /** Return fixed-query task and effort aggregates for a bounded deadline window. */
    public function execute(TaskActorData $actor, ?int $dueSoonDays = null): TaskDashboardData;
}
