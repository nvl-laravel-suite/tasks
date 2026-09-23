<?php

declare(strict_types=1);

namespace Nvl\Tasks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwnerRegistrar;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\ConfiguredTaskPrincipalResolver;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tasks\Support\TasksRouteConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;

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
        ContentOwnerRegistrar $contentOwners,
        MetafieldOwnerRegistry $metafieldOwners,
        TenantResourceRegistry $tenancyResources,
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
            'content.owner' => $contentOwners->registered('task') === Task::class,
            'metafields.owner' => isset($metafieldOwners->all()['task']),
            'tenancy.resource' => $tenancyResources->get(Task::TENANT_RESOURCE)->model === Task::class,
        ];

        if ($checks['tasks.table']) {
            $checks['tasks.columns'] = $schema->hasColumns($tasks, [
                'id', 'tenant_id', 'creator_type', 'creator_id', 'title', 'description',
                'status', 'priority', 'due_at', 'completed_at', 'metadata', 'revision',
                'created_at', 'updated_at', 'deleted_at',
            ]);
        }

        if ($checks['assignments.table']) {
            $checks['assignments.columns'] = $schema->hasColumns($assignments, [
                'id', 'task_id', 'tenant_id', 'assignee_type', 'assignee_id',
                'assigned_by_type', 'assigned_by_id', 'created_at', 'updated_at',
            ]);
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
