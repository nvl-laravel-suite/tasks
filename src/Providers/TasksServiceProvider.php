<?php

declare(strict_types=1);

namespace Nvl\Tasks\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Bindings\RequiredBindingDefinition;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tasks\Console\Commands\DrainTaskActivityOutboxCommand;
use Nvl\Tasks\Console\Commands\TasksDoctorCommand;
use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskSchedulerReadiness;
use Nvl\Tasks\Integrations\ActivityTaskPublisher;
use Nvl\Tasks\Integrations\InactiveTaskActivityPublisher;
use Nvl\Tasks\Integrations\MediaTaskAttachments;
use Nvl\Tasks\Integrations\UnavailableTaskAttachments;
use Nvl\Tasks\Ownership\TasksAdoptionAdapter as NativeTasksAdoptionAdapter;
use Nvl\Tasks\Ownership\TasksResourceRegistrar as NativeTasksResourceRegistrar;
use Nvl\Tasks\Services\CachedTaskSchedulerReadiness;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskModelRegistry;
use Nvl\Tasks\Services\TasksDoctor;
use Nvl\Tasks\Support\TaskIntegrations;

/** Registers the headless Tasks runtime and package-owned integrations. */
final class TasksServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /** Register safe defaults and the task ownership graph. */
    public function register(): void
    {

        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/tasks', function (): array {
            $report = $this->app->make(TasksDoctor::class)->inspect();

            return [...PackageDoctorContributor::booleanChecks($report['checks'], 'nvl:tasks:doctor'), ...$report['integrations']];
        });

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-tasks.php', 'tasks');
        $this->app->singleton(TaskModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(TaskModelRegistry::class)->prepare());
        $this->app->booted(function (): void {
            $this->app->make(NativeTasksResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('tasks', NativeTasksAdoptionAdapter::class);
            }
        });
        $this->app->bindIf(TaskAttachments::class, static function (Application $app): TaskAttachments {
            $active = $app->make(TaskIntegrations::class)->enabled('tasks.media.enabled', MediaServiceProvider::class);

            if ($active) {
                return $app->make(MediaTaskAttachments::class);
            }

            return $app->make(UnavailableTaskAttachments::class);
        });
        $this->app->bindIf(TaskActivityPublisher::class, static function (Application $app): TaskActivityPublisher {
            $active = $app->make(TaskIntegrations::class)->enabled('tasks.activity.enabled', ActivityServiceProvider::class);

            return $app->make($active ? ActivityTaskPublisher::class : InactiveTaskActivityPublisher::class);
        });

        $this->app->bindIf(TaskSchedulerReadiness::class, CachedTaskSchedulerReadiness::class);
        $this->app->bindIf(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
        $this->callAfterResolving(RequiredBindings::class, static function (RequiredBindings $bindings): void {
            $documentation = 'https://github.com/nvl-laravel-suite/tasks#required-bindings';
            $bindings->register(new RequiredBindingDefinition('tasks', TaskAuthorization::class, ConfiguredTaskAuthorization::class, 'user_task_mutation', null, $documentation));
        });
    }

    /** Boot migrations, diagnostics, and package assets. */
    public function boot(): void
    {
        $this->app->make(GlobalNames::class)->translations('tasks', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-tasks'),
        ], 'nvl-tasks-translations');

        if ((bool) config('nvl-tasks.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([TasksDoctorCommand::class]);
            if ($this->app->make(TaskIntegrations::class)->enabled('tasks.activity.enabled', ActivityServiceProvider::class)) {
                $this->commands([DrainTaskActivityOutboxCommand::class]);
                $this->registerActivityDrainSchedule();
            }
        }

        $this->publishes([
            __DIR__.'/../../config/nvl-tasks.php' => config_path('nvl-tasks.php'),
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
        if (config('nvl-tasks.activity.schedule.enabled', true) !== true) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('nvl:tasks:activity:drain')
                ->everyMinute()
                ->before(fn () => $this->app->make(CachedTaskSchedulerReadiness::class)->recordHeartbeat())
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
