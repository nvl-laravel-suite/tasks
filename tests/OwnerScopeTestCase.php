<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Tasks\Tests\Fixtures\OwnedTask;
use Nvl\Tasks\Tests\Fixtures\TasksTestMaintenanceMode;

/** Boots native Tasks without Auth, Tenancy or unrelated domain integrations. */
abstract class OwnerScopeTestCase extends OwnershipTestCase
{
    /** @var array<string, class-string<Model>> */
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
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql' && getenv('NVL_OWNERSHIP_TEST_HOST') !== false) {
            $host = getenv('NVL_OWNERSHIP_TEST_HOST');
            $port = getenv('NVL_OWNERSHIP_TEST_PORT');
            if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! is_string($port) || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new \RuntimeException('Tasks PostgreSQL workers require an explicit loopback host and valid port.');
            }
            foreach (['sqlite', 'ownership_remote'] as $connection) {
                $app['config']->set("database.connections.{$connection}.host", $host);
                $app['config']->set("database.connections.{$connection}.port", $port);
                $app['config']->set("database.connections.{$connection}.username", getenv('NVL_OWNERSHIP_TEST_USERNAME') ?: 'nvl');
                $app['config']->set("database.connections.{$connection}.password", getenv('NVL_OWNERSHIP_TEST_PASSWORD') ?: '');
            }
        }

        $app['config']->set([
            'nvl-tasks.models.task' => OwnedTask::class,
            'nvl-tasks.migrations.enabled' => false,
            'nvl-tasks.activity.enabled' => false,
            'nvl-tasks.media.enabled' => false,
        ]);
    }

    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
        if (! $this->hasRetainedOwnershipSchema()) {
            $this->installSchema();
        }
        $this->app->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
        {
            public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

            public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new TasksTestMaintenanceMode);
        $this->app->make(MaintenanceMode::class)->activate([]);
        if (! $this->activateOwnership()) {
            return;
        }
        if (! $this->hasRetainedOwnershipSchema()) {
            $this->adoptSchema();
        }
        $this->app->make(MaintenanceMode::class)->deactivate();
        $this->allowOwners();
        $this->retainAdoptedOwnershipSchema([TasksTables::get(TasksTables::GraphLock)]);
    }

    private function installSchema(): void
    {
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        $connection = (new Task)->getConnection();
        $connection->getSchemaBuilder()->table((new Task)->getTable(), static function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
        });
        $connection->getSchemaBuilder()->table((new Task)->getTable(), static fn (Blueprint $table) => $table->foreign('company_id')->references('id')->on('ownership_test_companies'));
        $this->createIntegrationSchema();
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    }

    private function adoptSchema(): void
    {
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $operation = new OwnershipOperation('tasks-adopt', 'operator', '1');
        $plan = $coordinator->prepare($this->adoptionPackages(), [], $operation);
        while (! $coordinator->backfill($plan, 100, $operation)) {
        }
        $coordinator->activate($plan, $operation);
    }

    /** Allow adoption fixtures to inspect historical rows before activation. */
    protected function activateOwnership(): bool
    {
        return true;
    }

    /** Install optional integration fixtures before graph activation. */
    protected function createIntegrationSchema(): void {}

    /** @return list<string> */
    protected function adoptionPackages(): array
    {
        return ['tasks'];
    }
}
