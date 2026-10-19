<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
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
        return [...parent::getPackageProviders($app), DataServiceProvider::class, TasksServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
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
        $this->app->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
        {
            public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new TasksTestMaintenanceMode);
        $this->app->make(MaintenanceMode::class)->activate([]);
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $operation = new OwnershipOperation('tasks-adopt', 'operator', '1');
        $plan = $coordinator->prepare($this->adoptionPackages(), [], $operation);
        while (! $coordinator->backfill($plan, 100, $operation)) {
        }
        $coordinator->activate($plan, $operation);
        $this->app->make(MaintenanceMode::class)->deactivate();
        $this->allowOwners();
    }

    /** Install optional integration fixtures before graph activation. */
    protected function createIntegrationSchema(): void {}

    /** @return list<string> */
    protected function adoptionPackages(): array
    {
        return ['tasks'];
    }
}
