<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tasks\Contracts\TaskActivityWorklist;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tasks\Support\TasksRouteConfiguration;
use Throwable;

/** Inspects task schema and consumer bindings without mutating application data. */
final class TasksDoctor
{
    /** Initialize the package-owned inspection dependencies. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TaskPrincipalResolver $principals,
        private TenantResourceRegistry $tenancyResources,
        private TaskActivityWorklist $activityTenants,
        private OptionalIntegration $integrations,
        private TaskSchedulerDiagnostics $scheduler,
    ) {}

    /**
     * Inspect package readiness without rendering a command or changing state.
     *
     * @return array{healthy: bool, checks: array<string, bool>, integrations: list<array{key: string, severity: string, passed: bool, message: string}>}
     */
    public function inspect(): array
    {
        $authorization = $this->authorization;
        $principals = $this->principals;
        $tenancyResources = $this->tenancyResources;
        $activityTenants = $this->activityTenants;

        $schema = Schema::connection(TasksConfiguration::connection());
        $tasks = TasksConfiguration::table(TasksTables::get(TasksTables::Tasks));
        $assignments = TasksConfiguration::table(TasksTables::get(TasksTables::Assignments));
        $routesEnabled = config('nvl-tasks.routes.management.enabled', false) === true;
        $checks = [
            'tasks.table' => $schema->hasTable($tasks),
            'assignments.table' => $schema->hasTable($assignments),
            'authorization.bound' => ! $authorization instanceof ConfiguredTaskAuthorization,
            'routes.management.principals' => ! $routesEnabled || ! $principals instanceof ConfiguredTaskPrincipalResolver,
            'routes.management.registered' => Route::has(TasksRouteConfiguration::name().'index') === $routesEnabled,
            'tenancy.resource' => $tenancyResources->get(Task::TENANT_RESOURCE)->model === Task::class,
        ];

        try {
            $checks['activity_outbox.recovery_worklist'] = ! $this->integrations->enabled('tasks.activity.enabled', ActivityServiceProvider::class)
                || config('nvl-tenancy.enabled') !== true
                || config('nvl-tasks.activity.schedule.enabled', true) !== true
                || $activityTenants->activeTenantIds() !== [];
        } catch (Throwable) {
            $checks['activity_outbox.recovery_worklist'] = false;
        }

        foreach ([
            'checklist' => TasksTables::get(TasksTables::ChecklistItems),
            'time_entries' => TasksTables::get(TasksTables::TimeEntries),
            'relationships' => TasksTables::get(TasksTables::Relationships),
            'dependencies' => TasksTables::get(TasksTables::Dependencies),
            'tags' => TasksTables::get(TasksTables::Tags),
            'activity_outbox' => TasksTables::get(TasksTables::ActivityOutbox),
        ] as $name => $table) {
            $checks["{$name}.table"] = $schema->hasTable(TasksConfiguration::table($table));
        }

        foreach (['status', 'priority', 'type', 'category', 'importance'] as $field) {
            try {
                TaskEnumConfiguration::defaultValue($field);
                $checks["enums.{$field}"] = true;
            } catch (InvalidArgumentException) {
                $checks["enums.{$field}"] = false;
            }
        }

        try {
            TaskEnumConfiguration::completedStatus();
            $checks['enums.completed_status'] = true;
        } catch (InvalidArgumentException) {
            $checks['enums.completed_status'] = false;
        }

        if ($checks['tasks.table']) {
            $checks['tasks.columns'] = $schema->hasColumns($tasks, [
                'id', 'tenant_id', 'creator_type', 'creator_id', 'title', 'description',
                'status', 'priority', 'type', 'category', 'importance',
                'target_at', 'due_at', 'estimated_seconds', 'completed_at', 'metadata', 'revision',
                'created_at', 'updated_at', 'deleted_at',
            ]);
        }

        if ($checks['assignments.table']) {
            $checks['assignments.columns'] = $schema->hasColumns($assignments, [
                'id', 'task_id', 'tenant_id', 'assignee_type', 'assignee_id',
                'assigned_by_type', 'assigned_by_id', 'created_at', 'updated_at',
            ]);
        }

        foreach ([
            'checklist' => [TasksTables::get(TasksTables::ChecklistItems), ['id', 'task_id', 'tenant_id', 'title', 'position', 'completed_at']],
            'time_entries' => [TasksTables::get(TasksTables::TimeEntries), ['id', 'task_id', 'tenant_id', 'performer_type', 'performer_id', 'started_at', 'stopped_at', 'duration_seconds']],
            'relationships' => [TasksTables::get(TasksTables::Relationships), ['id', 'tenant_id', 'child_task_id', 'parent_task_id']],
            'dependencies' => [TasksTables::get(TasksTables::Dependencies), ['id', 'tenant_id', 'task_id', 'blocker_task_id']],
            'tags' => [TasksTables::get(TasksTables::Tags), ['id', 'task_id', 'tenant_id', 'tag']],
            'activity_outbox' => [TasksTables::get(TasksTables::ActivityOutbox), [
                'id', 'task_id', 'tenant_id', 'payload', 'attempts', 'available_at',
                'lease_token', 'leased_until', 'delivered_at', 'last_error', 'created_at', 'updated_at',
            ]],
        ] as $name => [$table, $columns]) {
            if ($checks["{$name}.table"]) {
                $checks["{$name}.columns"] = $schema->hasColumns(TasksConfiguration::table($table), $columns);
            }
        }

        $integrations = [
            ...$this->scheduler->inspect(),
            $this->integrations->check('tasks.activity.enabled', ActivityServiceProvider::class),
            $this->integrations->check('tasks.media.enabled', MediaServiceProvider::class),
        ];
        $healthy = ! in_array(false, $checks, true)
            && ! in_array(false, array_column($integrations, 'passed'), true);
        $result = ['healthy' => $healthy, 'checks' => $checks, 'integrations' => $integrations];

        return $result;
    }
}
