<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tenancy;

use Illuminate\Database\Query\Builder;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionBoundary;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Adopts standalone tasks while deriving every child from its canonical task. */
final readonly class TasksAdoptionAdapter implements TenantAdoptionAdapter
{
    /** Bind adoption to the canonical task connection. */
    public function __construct(private TenantAdoptionBoundary $adoption) {}

    /** Return the package's root and inherited tenant resources.
     *
     * @return list<string>
     */
    public function resources(): array
    {
        return ['tasks.tasks', 'tasks.activity_outbox', ...array_keys($this->children())];
    }

    /** Assert that every package table already includes ownership columns. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->adoption->connection($plan, 'tasks.tasks')->getSchemaBuilder();
        $tables = [
            TasksConfiguration::table(TasksTables::Tasks),
            TasksConfiguration::table(TasksTables::ActivityOutbox),
        ];

        foreach ($this->children() as $child) {
            $tables[] = $child['table'];
        }

        foreach ($tables as $table) {
            if (! $schema->hasColumn($table, 'tenant_id')) {
                throw new TenantBoundaryViolation('Tasks adoption requires the package ownership schema.');
            }
        }
    }

    /** Backfill a reviewed task batch and each inherited child atomically. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'tasks.tasks');
        $assignments = $this->adoption->assignments($plan, 'tasks.tasks', $cursor, $limit);

        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $tenantId = $this->adoption->ownership($assignment, 'tasks.tasks')['tenant_id'];
                $connection->table(TasksConfiguration::table(TasksTables::Tasks))
                    ->where('id', $assignment->recordId)->update(['tenant_id' => $tenantId]);

                $connection->table(TasksConfiguration::table(TasksTables::ActivityOutbox))
                    ->where('task_id', $assignment->recordId)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenantId]);

                foreach ($this->children() as $child) {
                    $connection->table($child['table'])
                        ->where($child['parent_key'], $assignment->recordId)
                        ->update(['tenant_id' => $tenantId]);
                }
            }
        });

        return $this->adoption->result($assignments);
    }

    /** Verify every task child and both endpoints of cross-task edges. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'tasks.tasks');
        $tasks = TasksConfiguration::table(TasksTables::Tasks);
        $errors = [];

        if ($connection->table($tasks)->whereNull('tenant_id')->exists()) {
            $errors[] = 'tasks.tenant_id';
        }

        $outbox = TasksConfiguration::table(TasksTables::ActivityOutbox);

        if ($connection->table($outbox.' as outbox')
            ->leftJoin($tasks.' as task', 'task.id', '=', 'outbox.task_id')
            ->whereNull('task.id')
            ->exists()) {
            $errors[] = 'tasks.activity_outbox.task_id';
        }

        if ($connection->table($outbox.' as outbox')
            ->join($tasks.' as task', 'task.id', '=', 'outbox.task_id')
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('outbox.tenant_id')
                ->orWhereColumn('outbox.tenant_id', '!=', 'task.tenant_id'))
            ->exists()) {
            $errors[] = 'tasks.activity_outbox.tenant_id';
        }

        foreach ($this->children() as $resource => $child) {
            $table = $child['table'];
            $parentKey = $child['parent_key'];

            if ($connection->table($table.' as child')
                ->leftJoin($tasks.' as task', 'task.id', '=', 'child.'.$parentKey)
                ->where(static fn (Builder $query): Builder => $query
                    ->whereNull('child.tenant_id')
                    ->orWhereNull('task.id')
                    ->orWhereColumn('child.tenant_id', '!=', 'task.tenant_id'))
                ->exists()) {
                $errors[] = $resource.'.tenant_id';
            }

            if ($child['other_key'] !== null && $connection->table($table.' as child')
                ->leftJoin($tasks.' as other', 'other.id', '=', 'child.'.$child['other_key'])
                ->where(static fn (Builder $query): Builder => $query
                    ->whereNull('other.id')
                    ->orWhereColumn('child.tenant_id', '!=', 'other.tenant_id'))
                ->exists()) {
                $errors[] = $resource.'.'.$child['other_key'];
            }
        }

        return new TenantVerification($errors);
    }

    /** Activate only a fully verified tenant partition. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Tasks ownership did not verify.');
        }
    }

    /** Return canonical parent keys and optional second task endpoints.
     *
     * @return array<string, array{table: string, parent_key: string, other_key: string|null}>
     */
    private function children(): array
    {
        return [
            'tasks.assignments' => [
                'table' => TasksConfiguration::table(TasksTables::Assignments),
                'parent_key' => 'task_id',
                'other_key' => null,
            ],
            'tasks.checklist_items' => [
                'table' => TasksConfiguration::table(TasksTables::ChecklistItems),
                'parent_key' => 'task_id',
                'other_key' => null,
            ],
            'tasks.time_entries' => [
                'table' => TasksConfiguration::table(TasksTables::TimeEntries),
                'parent_key' => 'task_id',
                'other_key' => null,
            ],
            'tasks.tags' => [
                'table' => TasksConfiguration::table(TasksTables::Tags),
                'parent_key' => 'task_id',
                'other_key' => null,
            ],
            'tasks.relationships' => [
                'table' => TasksConfiguration::table(TasksTables::Relationships),
                'parent_key' => 'child_task_id',
                'other_key' => 'parent_task_id',
            ],
            'tasks.dependencies' => [
                'table' => TasksConfiguration::table(TasksTables::Dependencies),
                'parent_key' => 'task_id',
                'other_key' => 'blocker_task_id',
            ],
        ];
    }
}
