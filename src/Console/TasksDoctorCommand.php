<?php

declare(strict_types=1);

namespace Nvl\Tasks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Activity\Contracts\ActivityTenantWorklist;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\ConfiguredTaskPrincipalResolver;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tasks\Support\TasksRouteConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Throwable;

/** Inspects task schema and consumer bindings without mutating application data. */
final class TasksDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:tasks:doctor {--strict} {--format=text}';

    /** @var string */
    protected $description = 'Inspect the NVL Tasks installation';

    /** Report readiness of storage and package-owned integration boundaries. */
    public function handle(
        TaskAuthorization $authorization,
        TaskPrincipalResolver $principals,
        TenantResourceRegistry $tenancyResources,
        ActivityTenantWorklist $activityTenants,
    ): int {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('The Tasks Doctor format must be text or json.');
        }

        $schema = Schema::connection(TasksConfiguration::connection());
        $tasks = TasksConfiguration::table(TasksTables::Tasks);
        $assignments = TasksConfiguration::table(TasksTables::Assignments);
        $routesEnabled = config('tasks.routes.management.enabled', false) === true;
        $checks = [
            'tasks.table' => $schema->hasTable($tasks),
            'assignments.table' => $schema->hasTable($assignments),
            'authorization.bound' => ! $authorization instanceof ConfiguredTaskAuthorization,
            'routes.management.principals' => ! $routesEnabled || ! $principals instanceof ConfiguredTaskPrincipalResolver,
            'routes.management.registered' => Route::has(TasksRouteConfiguration::name().'index') === $routesEnabled,
            'tenancy.resource' => $tenancyResources->get(Task::TENANT_RESOURCE)->model === Task::class,
        ];

        try {
            $checks['activity_outbox.recovery_worklist'] = config('tenancy.enabled') !== true
                || config('tasks.activity.schedule.enabled', true) !== true
                || $activityTenants->activeTenantIds() !== [];
        } catch (Throwable) {
            $checks['activity_outbox.recovery_worklist'] = false;
        }

        foreach ([
            'checklist' => TasksTables::ChecklistItems,
            'time_entries' => TasksTables::TimeEntries,
            'relationships' => TasksTables::Relationships,
            'dependencies' => TasksTables::Dependencies,
            'tags' => TasksTables::Tags,
            'activity_outbox' => TasksTables::ActivityOutbox,
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
            'checklist' => [TasksTables::ChecklistItems, ['id', 'task_id', 'tenant_id', 'title', 'position', 'completed_at']],
            'time_entries' => [TasksTables::TimeEntries, ['id', 'task_id', 'tenant_id', 'performer_type', 'performer_id', 'started_at', 'stopped_at', 'duration_seconds']],
            'relationships' => [TasksTables::Relationships, ['id', 'tenant_id', 'child_task_id', 'parent_task_id']],
            'dependencies' => [TasksTables::Dependencies, ['id', 'tenant_id', 'task_id', 'blocker_task_id']],
            'tags' => [TasksTables::Tags, ['id', 'task_id', 'tenant_id', 'tag']],
            'activity_outbox' => [TasksTables::ActivityOutbox, [
                'id', 'task_id', 'tenant_id', 'payload', 'attempts', 'available_at',
                'lease_token', 'leased_until', 'delivered_at', 'last_error', 'created_at', 'updated_at',
            ]],
        ] as $name => [$table, $columns]) {
            if ($checks["{$name}.table"]) {
                $checks["{$name}.columns"] = $schema->hasColumns(TasksConfiguration::table($table), $columns);
            }
        }

        $healthy = ! in_array(false, $checks, true);
        $result = ['healthy' => $healthy, 'checks' => $checks];

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line(($passed ? 'PASS ' : 'FAIL ').$name);
            }
        }

        return $healthy || ! $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
    }
}
