<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

/**
 * Expresses task impact independently of urgency.
 *
 * @api
 */
enum TaskImportance: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
}
