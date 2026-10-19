<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Nvl\Activity\Providers\ActivityServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;

/** Activates the task and independent audit graph together without Tenancy. */
abstract class ActivityOwnerScopeTestCase extends OwnerScopeTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ActivitylogServiceProvider::class, ActivityServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(['nvl-tasks.activity.enabled' => true, 'nvl-activity.migrations.enabled' => false]);
    }

    protected function createIntegrationSchema(): void
    {
        foreach (glob(__DIR__.'/../../activity/database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
    }

    protected function adoptionPackages(): array
    {
        return ['tasks', 'activity'];
    }
}
