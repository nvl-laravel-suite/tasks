<?php

declare(strict_types=1);

namespace Nvl\Tasks\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Bindings\RequiredBindingDefinition;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tasks\Actions\AddTaskChecklistItemAction;
use Nvl\Tasks\Actions\AddTaskDependencyAction;
use Nvl\Tasks\Actions\AddTaskTagAction;
use Nvl\Tasks\Actions\AddTaskTimeEntryAction;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\DeleteTaskTimeEntryAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\GetTaskDashboardAction;
use Nvl\Tasks\Actions\GetTaskDetailAction;
use Nvl\Tasks\Actions\LinkTaskParentAction;
use Nvl\Tasks\Actions\ListTaskChecklistItemsAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Actions\ListTaskTimeEntriesAction;
use Nvl\Tasks\Actions\RemoveTaskChecklistItemAction;
use Nvl\Tasks\Actions\RemoveTaskDependencyAction;
use Nvl\Tasks\Actions\RemoveTaskTagAction;
use Nvl\Tasks\Actions\ReorderTaskChecklistItemsAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\StartTaskTimerAction;
use Nvl\Tasks\Actions\StopTaskTimerAction;
use Nvl\Tasks\Actions\ToggleTaskChecklistItemAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UnlinkTaskParentAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Actions\UpdateTaskChecklistItemAction;
use Nvl\Tasks\Actions\UpdateTaskTimeEntryAction;
use Nvl\Tasks\Console\DrainTaskActivityOutboxCommand;
use Nvl\Tasks\Console\TasksDoctorCommand;
use Nvl\Tasks\Contracts\AddTaskChecklistItemContract;
use Nvl\Tasks\Contracts\AddTaskDependencyContract;
use Nvl\Tasks\Contracts\AddTaskTagContract;
use Nvl\Tasks\Contracts\AddTaskTimeEntryContract;
use Nvl\Tasks\Contracts\AssignTaskContract;
use Nvl\Tasks\Contracts\CreateTaskContract;
use Nvl\Tasks\Contracts\DeleteTaskContract;
use Nvl\Tasks\Contracts\DeleteTaskTimeEntryContract;
use Nvl\Tasks\Contracts\GetTaskContract;
use Nvl\Tasks\Contracts\GetTaskDashboardContract;
use Nvl\Tasks\Contracts\GetTaskDetailContract;
use Nvl\Tasks\Contracts\LinkTaskParentContract;
use Nvl\Tasks\Contracts\ListTaskChecklistItemsContract;
use Nvl\Tasks\Contracts\ListTasksContract;
use Nvl\Tasks\Contracts\ListTaskTimeEntriesContract;
use Nvl\Tasks\Contracts\RemoveTaskChecklistItemContract;
use Nvl\Tasks\Contracts\RemoveTaskDependencyContract;
use Nvl\Tasks\Contracts\RemoveTaskTagContract;
use Nvl\Tasks\Contracts\ReorderTaskChecklistItemsContract;
use Nvl\Tasks\Contracts\RestoreTaskContract;
use Nvl\Tasks\Contracts\StartTaskTimerContract;
use Nvl\Tasks\Contracts\StopTaskTimerContract;
use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Contracts\TaskActivityWorklist;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Contracts\TaskSchedulerReadiness;
use Nvl\Tasks\Contracts\ToggleTaskChecklistItemContract;
use Nvl\Tasks\Contracts\UnassignTaskContract;
use Nvl\Tasks\Contracts\UnlinkTaskParentContract;
use Nvl\Tasks\Contracts\UpdateTaskChecklistItemContract;
use Nvl\Tasks\Contracts\UpdateTaskContract;
use Nvl\Tasks\Contracts\UpdateTaskTimeEntryContract;
use Nvl\Tasks\Integrations\ActivityTaskPublisher;
use Nvl\Tasks\Integrations\ActivityTaskWorklist;
use Nvl\Tasks\Integrations\EmptyTaskActivityWorklist;
use Nvl\Tasks\Integrations\InactiveTaskActivityPublisher;
use Nvl\Tasks\Integrations\MediaTaskAttachments;
use Nvl\Tasks\Integrations\UnavailableTaskAttachments;
use Nvl\Tasks\Services\CachedTaskSchedulerReadiness;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\ConfiguredTaskPrincipalResolver;
use Nvl\Tasks\Services\TasksDoctor;
use Nvl\Tasks\Tenancy\TasksResourceRegistrar;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Registers the headless Tasks runtime and package-owned integrations. */
final class TasksServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /** Register safe defaults and the task ownership graph. */
    public function register(): void
    {
        $this->app->bindIf(AddTaskChecklistItemContract::class, AddTaskChecklistItemAction::class);
        $this->app->bindIf(AddTaskDependencyContract::class, AddTaskDependencyAction::class);
        $this->app->bindIf(AddTaskTagContract::class, AddTaskTagAction::class);
        $this->app->bindIf(AddTaskTimeEntryContract::class, AddTaskTimeEntryAction::class);
        $this->app->bindIf(AssignTaskContract::class, AssignTaskAction::class);
        $this->app->bindIf(CreateTaskContract::class, CreateTaskAction::class);
        $this->app->bindIf(DeleteTaskContract::class, DeleteTaskAction::class);
        $this->app->bindIf(DeleteTaskTimeEntryContract::class, DeleteTaskTimeEntryAction::class);
        $this->app->bindIf(GetTaskContract::class, GetTaskAction::class);
        $this->app->bindIf(GetTaskDashboardContract::class, GetTaskDashboardAction::class);
        $this->app->bindIf(GetTaskDetailContract::class, GetTaskDetailAction::class);
        $this->app->bindIf(LinkTaskParentContract::class, LinkTaskParentAction::class);
        $this->app->bindIf(ListTaskChecklistItemsContract::class, ListTaskChecklistItemsAction::class);
        $this->app->bindIf(ListTaskTimeEntriesContract::class, ListTaskTimeEntriesAction::class);
        $this->app->bindIf(ListTasksContract::class, ListTasksAction::class);
        $this->app->bindIf(RemoveTaskChecklistItemContract::class, RemoveTaskChecklistItemAction::class);
        $this->app->bindIf(RemoveTaskDependencyContract::class, RemoveTaskDependencyAction::class);
        $this->app->bindIf(RemoveTaskTagContract::class, RemoveTaskTagAction::class);
        $this->app->bindIf(ReorderTaskChecklistItemsContract::class, ReorderTaskChecklistItemsAction::class);
        $this->app->bindIf(RestoreTaskContract::class, RestoreTaskAction::class);
        $this->app->bindIf(StartTaskTimerContract::class, StartTaskTimerAction::class);
        $this->app->bindIf(StopTaskTimerContract::class, StopTaskTimerAction::class);
        $this->app->bindIf(ToggleTaskChecklistItemContract::class, ToggleTaskChecklistItemAction::class);
        $this->app->bindIf(UnassignTaskContract::class, UnassignTaskAction::class);
        $this->app->bindIf(UnlinkTaskParentContract::class, UnlinkTaskParentAction::class);
        $this->app->bindIf(UpdateTaskContract::class, UpdateTaskAction::class);
        $this->app->bindIf(UpdateTaskChecklistItemContract::class, UpdateTaskChecklistItemAction::class);
        $this->app->bindIf(UpdateTaskTimeEntryContract::class, UpdateTaskTimeEntryAction::class);

        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/tasks', function (): array {
            $report = $this->app->make(TasksDoctor::class)->inspect();

            return [...PackageDoctorContributor::booleanChecks($report['checks'], 'nvl:tasks:doctor'), ...$report['integrations']];
        });

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-tasks.php', 'tasks');
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
        $this->app->bindIf(TaskSchedulerReadiness::class, CachedTaskSchedulerReadiness::class);
        $this->app->bindIf(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
        $this->app->bindIf(TaskPrincipalResolver::class, ConfiguredTaskPrincipalResolver::class);
        $this->callAfterResolving(RequiredBindings::class, static function (RequiredBindings $bindings): void {
            $documentation = 'https://github.com/nvl-laravel-suite/tasks#required-bindings';
            $bindings->register(new RequiredBindingDefinition('tasks', TaskAuthorization::class, ConfiguredTaskAuthorization::class, 'user_task_mutation', null, $documentation));
            $bindings->register(new RequiredBindingDefinition('tasks', TaskPrincipalResolver::class, ConfiguredTaskPrincipalResolver::class, 'http_assignment', 'nvl-tasks.routes.management.enabled', $documentation));
        });
    }

    /** Boot migrations, diagnostics, and package assets. */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $this->app->make(GlobalNames::class)->translations('tasks', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-tasks'),
        ], 'nvl-tasks-translations');
        $typeScriptSources->register(__DIR__.'/..', 'nvl/tasks');

        if ((bool) config('nvl-tasks.migrations.enabled', true)) {
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
