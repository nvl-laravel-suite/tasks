<?php

declare(strict_types=1);

namespace Nvl\Tasks\Exceptions;

use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Nvl\Tasks\Enums\TasksResponseCode;
use RuntimeException;

/** Base public Tasks runtime failure.
 * @api
 */
class TasksException extends RuntimeException implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            TaskRevisionConflict::class => new ExceptionResponse('tasks', TasksResponseCode::TaskRevisionConflict, 409),
            default => new ExceptionResponse('tasks', TasksResponseCode::OperationFailed),
        };
    }
}
