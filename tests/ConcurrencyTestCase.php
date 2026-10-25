<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** Committed fixtures are visible to separate native database worker processes. */
abstract class ConcurrencyTestCase extends StandaloneTasksTestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        }
    }

    protected function refreshTestDatabase(): void
    {
        $this->migrateDatabases();
        $this->app[Kernel::class]->setArtisan(null);
    }
}
