<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

/** Activates an independent audit source with a committed task-side outbox. */
abstract class RemoteActivityOwnerScopeTestCase extends ActivityOwnerScopeTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
            $app['config']->set('database.connections.ownership_remote', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        }
        $app['config']->set(['nvl-activity.connection' => 'ownership_remote', 'nvl-tasks.queue.connection' => 'database', 'queue.default' => 'database']);
    }

    protected function createIntegrationSchema(): void
    {
        parent::createIntegrationSchema();
        config(['nvl-ownership.connection' => 'ownership_remote']);
        try {
            (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        } finally {
            config(['nvl-ownership.connection' => null]);
        }
    }
}
