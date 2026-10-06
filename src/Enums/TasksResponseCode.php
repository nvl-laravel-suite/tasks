<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response discriminators for Tasks.
 * @api
 */
enum TasksResponseCode: string implements ResponseCode
{
    case BindingRequired = 'binding_required';
    case OperationFailed = 'operation_failed';
    case TaskRevisionConflict = 'task_revision_conflict';
}
