<?php

declare(strict_types=1);

namespace Nvl\Tasks\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tasks\Console\DrainTaskActivityOutboxCommand;
use Nvl\Tasks\Console\TasksDoctorCommand;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
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

    /** Boot migrations, diagnostics, and package assets. */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/tasks');

        if ((bool) config('tasks.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([TasksDoctorCommand::class, DrainTaskActivityOutboxCommand::class]);
            $this->registerActivityDrainSchedule();
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

    /** Schedule recovery of committed events missed by immediate queue dispatch. */
    private function registerActivityDrainSchedule(): void
    {
        if (config('tasks.activity.schedule.enabled', true) !== true) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('nvl:tasks:activity:drain')
                ->everyMinute()
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
