<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Groups tasks for broad reporting without binding them to an application domain.
 *
 * @api
 */
#[TypeScript]
enum TaskCategory: string
{
    case General = 'general';
    case Project = 'project';
    case Operations = 'operations';
    case Administrative = 'administrative';
}
