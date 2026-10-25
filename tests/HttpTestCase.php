<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Nvl\Tasks\Tests\Fixtures\Consumer\TaskExceptionHandler;

/** Boots the separately enabled management route group for HTTP tests. */
abstract class HttpTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->singleton(ExceptionHandler::class, TaskExceptionHandler::class);

    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/Fixtures/Consumer/routes.php';
    }
}
