<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Tasks\Tests\Fixtures\RemoteTask;

/** Exercises a standalone unowned consumer replacement on its actual separate source. */
abstract class ConfiguredStorageTestCase extends OwnershipTestCase
{
    /** @var array<string,class-string<Model>> */
    private array $originalMorphMap = [];

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Relation::morphMap($this->originalMorphMap, false);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app),  TasksServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (! in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
            $app['config']->set('database.connections.ownership_remote', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        }
        $app['config']->set(['nvl-tasks.models.task' => RemoteTask::class, 'nvl-tasks.migrations.enabled' => false, 'nvl-tasks.activity.enabled' => false, 'nvl-tasks.media.enabled' => false]);
    }

    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
        config(['nvl-tasks.connection' => 'ownership_remote', 'nvl-tasks.tables.tasks' => 'consumer_tasks']);
        try {
            foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
                (require $path)->up();
            }
        } finally {
            config(['nvl-tasks.connection' => null, 'nvl-tasks.tables.tasks' => 'nvl_tasks_tasks']);
        }
        $this->app->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
        {
            public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

            public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
        });
    }
}
