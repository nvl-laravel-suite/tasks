<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Expresses task urgency without encoding app-specific scheduling policy. */
#[TypeScript]
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
