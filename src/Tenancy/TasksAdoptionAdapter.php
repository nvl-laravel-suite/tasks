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

/** Adopts standalone tasks while deriving assignment ownership from each parent. */
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
        return ['tasks.tasks', 'tasks.assignments'];
    }

    /** Assert that the original migrations already include ownership columns. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->adoption->connection($plan, 'tasks.tasks')->getSchemaBuilder();

        foreach ([TasksTables::Tasks, TasksTables::Assignments] as $table) {
            if (! $schema->hasColumn(TasksConfiguration::table($table), 'tenant_id')) {
                throw new TenantBoundaryViolation('Tasks adoption requires the package ownership schema.');
            }
        }
    }

    /** Backfill a reviewed batch and all of its assignments atomically. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->adoption->connection($plan, 'tasks.tasks');
        $assignments = $this->adoption->assignments($plan, 'tasks.tasks', $cursor, $limit);

        $connection->transaction(function () use ($assignments, $connection): void {
            foreach ($assignments as $assignment) {
                $tenantId = $this->adoption->ownership($assignment, 'tasks.tasks')['tenant_id'];
                $connection->table(TasksConfiguration::table(TasksTables::Tasks))
                    ->where('id', $assignment->recordId)->update(['tenant_id' => $tenantId]);
                $connection->table(TasksConfiguration::table(TasksTables::Assignments))
                    ->where('task_id', $assignment->recordId)->update(['tenant_id' => $tenantId]);
            }
        });

        return $this->adoption->result($assignments);
    }

    /** Verify every task and assignment has matching tenant ownership. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->adoption->connection($plan, 'tasks.tasks');
        $tasks = TasksConfiguration::table(TasksTables::Tasks);
        $assignments = TasksConfiguration::table(TasksTables::Assignments);
        $errors = [];

        if ($connection->table($tasks)->whereNull('tenant_id')->exists()) {
            $errors[] = 'tasks.tenant_id';
        }

        if ($connection->table($assignments.' as assignment')
            ->join($tasks.' as task', 'task.id', '=', 'assignment.task_id')
            ->where(static fn (Builder $query): Builder => $query->whereNull('assignment.tenant_id')
                ->orWhereColumn('assignment.tenant_id', '!=', 'task.tenant_id'))
            ->exists()) {
            $errors[] = 'tasks.assignments.tenant_id';
        }

        if ($connection->table($assignments.' as assignment')
            ->leftJoin($tasks.' as task', 'task.id', '=', 'assignment.task_id')
            ->whereNull('task.id')->exists()) {
            $errors[] = 'tasks.assignments.task_id';
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
}
