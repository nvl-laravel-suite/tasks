<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

/**
 * Allows a host to supply an observable scheduler health signal.
 *
 * @api
 */
interface TaskSchedulerReadiness
{
    /** Confirm recent execution of the correctness schedule. */
    public function running(): bool;
}
