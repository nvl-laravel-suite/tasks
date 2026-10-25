<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

/**
 * Expresses task urgency without encoding app-specific scheduling policy.
 *
 * @api
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
