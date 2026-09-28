<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskDashboardData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tenancy\Services\TenantBoundary;
use stdClass;
use UnexpectedValueException;

/** Aggregates only tasks visible to the actor in the active tenant. */
final readonly class GetTaskDashboardAction
{
    /** Construct the authorized dashboard reader. */
    public function __construct(
        private TaskAuthorization $authorization,
        private TenantBoundary $boundary,
    ) {}

    /** Return fixed-query task and effort aggregates for a bounded deadline window. */
    public function execute(TaskActorData $actor, ?int $dueSoonDays = null): TaskDashboardData
    {
        $this->authorization->authorize(TaskAbility::List, $actor);

        $days = $dueSoonDays ?? config('tasks.dashboard.due_soon_days', 7);

        if (! is_int($days) || $days < 1 || $days > 90) {
            throw new InvalidArgumentException('The dashboard due-soon window must be between 1 and 90 days.');
        }

        $open = $this->status('tasks.dashboard.statuses.open', $this->status('tasks.defaults.status', TaskStatus::Open->value));
        $inProgress = $this->status('tasks.dashboard.statuses.in_progress', TaskStatus::InProgress->value);
        $blocked = $this->status('tasks.dashboard.statuses.blocked', TaskStatus::Blocked->value);
        $completed = $this->status('tasks.dashboard.statuses.completed', $this->status('tasks.lifecycle.completed_status', TaskStatus::Completed->value));
        $cancelled = $this->status('tasks.dashboard.statuses.cancelled', TaskStatus::Cancelled->value);
        $now = CarbonImmutable::now();
        $soonEnd = $now->addDays($days);

        $query = Task::query();

        if ($this->authorization instanceof TaskQueryScope) {
            $this->authorization->scopeTasks($query, $actor);
        }

        $this->boundary->query($query, Task::TENANT_RESOURCE);
        $summary = $this->aggregate($query, $open, $inProgress, $blocked, $completed, $cancelled, $now, $soonEnd);
        $loggedSeconds = TaskTimeEntry::query()
            ->whereIn('task_id', (clone $query)->select('id'))
            ->sum('duration_seconds');

        return new TaskDashboardData(
            total: $summary['total'],
            open: $summary['open_count'],
            inProgress: $summary['in_progress_count'],
            blocked: $summary['blocked_count'],
            completed: $summary['completed_count'],
            overdue: $summary['overdue_count'],
            dueSoon: $summary['due_soon_count'],
            dueSoonDays: $days,
            unassigned: $summary['unassigned_count'],
            estimatedSeconds: $summary['estimated_seconds'],
            loggedSeconds: (int) $loggedSeconds,
        );
    }

    /** Aggregate visible task rows without hydrating individual tasks.
     *
     * @param  Builder<Task>  $query
     * @return array{total: int, open_count: int, in_progress_count: int, blocked_count: int, completed_count: int, overdue_count: int, due_soon_count: int, unassigned_count: int, estimated_seconds: int}
     */
    private function aggregate(
        Builder $query,
        string $open,
        string $inProgress,
        string $blocked,
        string $completed,
        string $cancelled,
        CarbonImmutable $now,
        CarbonImmutable $soonEnd,
    ): array {
        $assignmentIds = TaskAssignment::query()->select('task_id')->distinct();
        $builder = $query->toBase()
            ->leftJoinSub($assignmentIds, 'assigned_task_ids', 'assigned_task_ids.task_id', '=', $query->getModel()->getTable().'.id')
            ->selectRaw(
                'COUNT(*) AS total, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS open_count, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS in_progress_count, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS blocked_count, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_count, '
                .'COALESCE(SUM(CASE WHEN due_at < ? AND status NOT IN (?, ?) THEN 1 ELSE 0 END), 0) AS overdue_count, '
                .'COALESCE(SUM(CASE WHEN due_at >= ? AND due_at <= ? AND status NOT IN (?, ?) THEN 1 ELSE 0 END), 0) AS due_soon_count, '
                .'COALESCE(SUM(CASE WHEN assigned_task_ids.task_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned_count, '
                .'COALESCE(SUM(estimated_seconds), 0) AS estimated_seconds',
                [
                    $open,
                    $inProgress,
                    $blocked,
                    $completed,
                    $now,
                    $completed,
                    $cancelled,
                    $now,
                    $soonEnd,
                    $completed,
                    $cancelled,
                ],
            );
        $row = $builder->first();

        if ($row === null) {
            throw new UnexpectedValueException('The task dashboard aggregate returned no row.');
        }

        return [
            'total' => $this->integer($row, 'total'),
            'open_count' => $this->integer($row, 'open_count'),
            'in_progress_count' => $this->integer($row, 'in_progress_count'),
            'blocked_count' => $this->integer($row, 'blocked_count'),
            'completed_count' => $this->integer($row, 'completed_count'),
            'overdue_count' => $this->integer($row, 'overdue_count'),
            'due_soon_count' => $this->integer($row, 'due_soon_count'),
            'unassigned_count' => $this->integer($row, 'unassigned_count'),
            'estimated_seconds' => $this->integer($row, 'estimated_seconds'),
        ];
    }

    /** Read a nonnegative integer aggregate from a database result.
     *
     */
    private function integer(stdClass $row, string $key): int
    {
        $value = $row->{$key} ?? null;

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && $value !== '' && strspn($value, '0123456789') === strlen($value)) {
            $digits = ltrim($value, '0');

            if ($digits === '') {
                return 0;
            }

            $maximum = (string) PHP_INT_MAX;

            if (strlen($digits) < strlen($maximum)
                || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0)) {
                return (int) $digits;
            }
        }

        throw new UnexpectedValueException("The task dashboard aggregate [{$key}] was not a nonnegative integer.");
    }

    /** Resolve a configured status value without accepting empty mappings. */
    private function status(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The dashboard status mapping [{$key}] must be a nonempty string.");
        }

        return $value;
    }
}
