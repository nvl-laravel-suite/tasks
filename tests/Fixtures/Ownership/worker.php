<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Tasks\Tests\Fixtures\Ownership\WorkerProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

require dirname(__DIR__, 6).'/vendor/autoload.php';
$sources = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
foreach (['primary', 'remote'] as $key) {
    $source = $sources[$key] ?? null;
    if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql' || ! is_string($source['database'] ?? null)
        || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $source['database']) !== 1) {
        throw new RuntimeException('Tasks workers require explicitly dedicated PostgreSQL sources.');
    }
}
WorkerProvider::useSources($sources);
$application = TestbenchApplication::create(options: ['load_environment_variables' => false, 'extra' => ['dont-discover' => ['*'], 'providers' => [SupportServiceProvider::class, OwnershipServiceProvider::class, ActivityServiceProvider::class, TasksServiceProvider::class, WorkerProvider::class]]]);
$kernel = $application->make(Kernel::class);
$status = $kernel->call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 10, '--force' => true, '--no-interaction' => true]);
if ($status !== 0) {
    fwrite(STDERR, $kernel->output());
    exit($status);
}
fwrite(STDOUT, 'tasks-worker-complete');
