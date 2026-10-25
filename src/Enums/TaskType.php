<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

/**
 * Classifies the workflow a task represents.
 *
 * @api
 */
enum TaskType: string
{
    case General = 'general';
    case Action = 'action';
    case Review = 'review';
    case Approval = 'approval';
    case FollowUp = 'follow_up';
}
