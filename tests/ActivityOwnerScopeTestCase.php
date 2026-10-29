<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Tasks\Enums\TaskChangeOperation;

/** Activates the task and independent audit graph together without Tenancy. */
abstract class ActivityOwnerScopeTestCase extends OwnerScopeTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ActivityServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-activity.events', array_fill_keys(array_column(TaskChangeOperation::cases(), 'value'), ['change_fields' => null, 'metadata_fields' => null]));
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
