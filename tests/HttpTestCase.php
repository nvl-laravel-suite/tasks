<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

/** Boots the separately enabled management route group for HTTP tests. */
abstract class HttpTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'tasks.routes.management.enabled' => true,
            'tasks.routes.management.middleware' => ['api', 'auth'],
        ]);
    }
}
