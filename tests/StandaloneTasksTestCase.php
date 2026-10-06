<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Nvl\Data\Providers\DataServiceProvider;
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
            LocaleServiceProvider::class, SupportServiceProvider::class, DataServiceProvider::class, TasksServiceProvider::class];
    }
}
