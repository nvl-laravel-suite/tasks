<?php

declare(strict_types=1);

namespace Nvl\Tasks\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tasks\Console\DrainTaskActivityOutboxCommand;
use Nvl\Tasks\Console\TasksDoctorCommand;
use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Contracts\TaskActivityWorklist;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Integrations\ActivityTaskPublisher;
use Nvl\Tasks\Integrations\ActivityTaskWorklist;
use Nvl\Tasks\Integrations\EmptyTaskActivityWorklist;
use Nvl\Tasks\Integrations\InactiveTaskActivityPublisher;
use Nvl\Tasks\Integrations\MediaTaskAttachments;
use Nvl\Tasks\Integrations\UnavailableTaskAttachments;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\ConfiguredTaskPrincipalResolver;
use Nvl\Tasks\Services\TasksDoctor;
use Nvl\Tasks\Tenancy\TasksResourceRegistrar;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Registers the headless Tasks runtime and package-owned integrations. */
final class TasksServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /** Register safe defaults and the task ownership graph. */
    public function register(): void
    {
        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/tasks', function (): array {
            $report = $this->app->make(TasksDoctor::class)->inspect();

            return [...PackageDoctorContributor::booleanChecks($report['checks'], 'nvl:tasks:doctor'), ...$report['integrations']];
        });

        $this->mergePackageConfiguration(__DIR__.'/../../config/tasks.php', 'tasks');
        $this->app->register(TenantServiceProvider::class);
        $this->app->bindIf(TaskAttachments::class, static function (Application $app): TaskAttachments {
            $active = $app->make(OptionalIntegration::class)->enabled('tasks.media.enabled', MediaServiceProvider::class);

            return $app->make($active ? MediaTaskAttachments::class : UnavailableTaskAttachments::class);
        });
        $this->app->bindIf(TaskActivityPublisher::class, static function (Application $app): TaskActivityPublisher {
            $active = $app->make(OptionalIntegration::class)->enabled('tasks.activity.enabled', ActivityServiceProvider::class);

            return $app->make($active ? ActivityTaskPublisher::class : InactiveTaskActivityPublisher::class);
        });
        $this->app->bindIf(TaskActivityWorklist::class, static function (Application $app): TaskActivityWorklist {
            $active = $app->make(OptionalIntegration::class)->enabled('tasks.activity.enabled', ActivityServiceProvider::class);

            return $app->make($active ? ActivityTaskWorklist::class : EmptyTaskActivityWorklist::class);
        });

        $this->app->booting(function (): void {
            foreach (['media' => MediaServiceProvider::class, 'activity' => ActivityServiceProvider::class] as $integration => $provider) {
                if ($this->app->make(OptionalIntegration::class)->enabled('tasks.'.$integration.'.enabled', $provider)) {
                    $this->app->make(TenantResourceRegistry::class)->requireCompatible('tasks', $integration);
                }
            }

            (new TasksResourceRegistrar)->register(
                $this->app->make(TenantResourceRegistry::class),
                $this->app->bound(TenantAdoptionRegistry::class) ? $this->app->make(TenantAdoptionRegistry::class) : null,
            );
        });
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
            $this->commands([TasksDoctorCommand::class]);
            if ($this->app->make(OptionalIntegration::class)->enabled('tasks.activity.enabled', ActivityServiceProvider::class)) {
                $this->commands([DrainTaskActivityOutboxCommand::class]);
                $this->registerActivityDrainSchedule();
            }
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
