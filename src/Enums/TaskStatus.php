<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

/**
 * Describes the durable lifecycle of a task.
 *
 * @api
 */
enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
