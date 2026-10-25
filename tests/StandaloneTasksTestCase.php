<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tasks\Providers\TasksServiceProvider;

/** Boots ordinary Tasks without discovering Activity or Media adapters. */
abstract class StandaloneTasksTestCase extends TestCase
{
    /**
     * Return the required Tasks providers only.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class, SupportServiceProvider::class,  TasksServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-tasks.activity.enabled', false);
    }
}
