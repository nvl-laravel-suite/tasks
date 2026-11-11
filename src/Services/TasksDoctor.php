<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TaskIntegrations;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Inspects task schema and consumer bindings without mutating application data.
 *
 * @internal
 */
final class TasksDoctor
{
    /** Initialize the package-owned inspection dependencies. */
    public function __construct(
        private TaskAuthorization $authorization,
        private OwnershipResourceRegistry $resources,
        private TaskIntegrations $integrations,
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

        $schema = Schema::connection(TasksConfiguration::connection());
        $tasks = TaskModelRegistry::newInstance()->getTable();
        $assignments = TasksConfiguration::table(TasksTables::get(TasksTables::Assignments));
        $checks = [
            'tasks.table' => $schema->hasTable($tasks),
            'assignments.table' => $schema->hasTable($assignments),
            'authorization.bound' => ! $authorization instanceof ConfiguredTaskAuthorization,
            'ownership.resource' => $this->resources->get(Task::TENANT_RESOURCE)->model === TaskModelRegistry::modelClass(),
        ];

        foreach ([
            'checklist' => TasksTables::get(TasksTables::ChecklistItems),
            'time_entries' => TasksTables::get(TasksTables::TimeEntries),
            'relationships' => TasksTables::get(TasksTables::Relationships),
            'dependencies' => TasksTables::get(TasksTables::Dependencies),
            'graph_lock' => TasksTables::get(TasksTables::GraphLock),
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
            'graph_lock' => [TasksTables::get(TasksTables::GraphLock), ['id']],
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

        if ($checks['graph_lock.table']) {
            $checks['graph_lock.row'] = DB::connection(TasksConfiguration::connection())
                ->table(TasksTables::get(TasksTables::GraphLock))->where('id', TaskGraphGuard::LOCK_ID)->exists();
        }

        if ($checks['activity_outbox.table']) {
            $checks['activity_outbox.ready_index'] = collect($schema->getIndexes(TasksConfiguration::table(TasksTables::ActivityOutbox)))
                ->contains(static fn (array $index): bool => array_slice($index['columns'], 0, 2) === ['delivered_at', 'available_at']);
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
