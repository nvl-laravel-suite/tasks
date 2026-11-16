<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Testing\TestServiceHosts;
use RuntimeException;

/** Real task commits on disposable storage, without a fixture-owned transaction. */
abstract class CommittedTasksTestCase extends ConcurrencyTestCase
{
    protected function migrateDatabases(): void
    {
        $source = DB::connection();
        $driver = $source->getDriverName();
        $expected = getenv('NVL_C1_TEST_DRIVER');
        if ($expected === 'pgsql') {
            $this->assertSame($expected, $driver);
        }
        $database = $source->getDatabaseName();
        $safe = $driver === 'sqlite' && $database === ':memory:';
        if ($driver === 'pgsql') {
            $safe = preg_match('/\Anvl_tasks_test_[a-f0-9]{8}_ci\z/D', $database) === 1
                && TestServiceHosts::admitsDatabaseHost($source->getConfig('host'));
        }
        if (! $safe) {
            throw new RuntimeException('Committed Tasks fixtures require memory SQLite or a dedicated loopback nvl_tasks_test_<8hex>_ci database.');
        }
        parent::migrateDatabases();
        $pdo = $source->getPdo();
        $this->beforeApplicationDestroyed(function () use ($source, $pdo, $database): void {
            if ($source->getRawPdo() !== $pdo || $source->getDatabaseName() !== $database) {
                throw new RuntimeException('The disposable Tasks source changed before cleanup.');
            }
            while ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            $source->getSchemaBuilder()->dropAllTables();
        });
    }
}
