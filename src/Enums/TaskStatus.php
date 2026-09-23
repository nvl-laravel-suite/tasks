<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Describes the durable lifecycle of a task. */
#[TypeScript]
enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
