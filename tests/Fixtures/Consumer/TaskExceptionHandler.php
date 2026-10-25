<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures\Consumer;

use Illuminate\Foundation\Exceptions\Handler;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;

final class TaskExceptionHandler extends Handler
{
    public function register(): void
    {
        $this->renderable(static fn (TaskRevisionConflict $exception) => response()->json(['message' => $exception->getMessage()], 409));
        $this->renderable(static fn (\InvalidArgumentException $exception) => response()->json(['message' => $exception->getMessage()], 422));
    }
}
