<?php

declare(strict_types=1);

namespace Nvl\Tasks\Exceptions;

use RuntimeException;

/** Signals that a task changed after a consumer read its revision. */
final class TaskRevisionConflict extends RuntimeException
{
    /** Describe the rejected stale task update. */
    public function __construct()
    {
        parent::__construct('The task changed since it was read.');
    }
}
