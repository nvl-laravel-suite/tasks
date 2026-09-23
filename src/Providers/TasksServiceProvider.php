<?php

declare(strict_types=1);

namespace Nvl\Tasks\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwnerRegistrar;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tasks\Console\TasksDoctorCommand;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\ConfiguredTaskPrincipalResolver;
use Nvl\Tasks\Tenancy\TasksResourceRegistrar;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/** Registers the headless Tasks runtime and package-owned integrations. */
final class TasksServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /** Register safe defaults and the task ownership graph. */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/tasks.php', 'tasks');
        $this->app->register(TenancyServiceProvider::class);
        (new TasksResourceRegistrar)->register(
            $this->app->make(TenantResourceRegistry::class),
            $this->app->make(TenantAdoptionRegistry::class),
        );
        $this->app->bindIf(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
        $this->app->bindIf(TaskPrincipalResolver::class, ConfiguredTaskPrincipalResolver::class);
    }

    /** Boot migrations, owner aliases, diagnostics, and package assets. */
    public function boot(
        TypeScriptSourceRegistry $typeScriptSources,
        ContentOwnerRegistrar $contentOwners,
        MetafieldOwnerRegistry $metafieldOwners,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/tasks');

        $registered = $contentOwners->registered(Task::CONTENT_OWNER_TYPE);

        if ($registered === null) {
            $contentOwners->register(Task::CONTENT_OWNER_TYPE, Task::class);
        } elseif ($registered !== Task::class) {
            throw new InvalidArgumentException('Content owner alias [task] must resolve to Task.');
        }

        $metafieldOwners->register('task', Task::class, 'Tasks', ['general']);

        if ((bool) config('tasks.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([TasksDoctorCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../../config/tasks.php' => config_path('tasks.php'),
        ], 'tasks-config');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'tasks-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'tasks-skills');
    }
}
