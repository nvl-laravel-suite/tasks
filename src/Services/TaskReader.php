<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;
use stdClass;
use UnexpectedValueException;

/**
 * Native TaskReader operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskReader
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskReadGuard $reads, private TaskAuthorization $authorization, private TaskPrincipalGuard $principals) {}

    /** Return one authorized task without loading unbounded associations. */
    public function get(Task|string $task, Authenticatable|SystemActor $actor): Task
    {
        $actor = $this->principals->actor($actor);
        $current = $this->reads->resolve($task, $actor);
        $limit = TasksConfiguration::limit('detail.maximum_task_links', 100);
        $current->setRelation('assignments', $current->assignments()->limit($limit)->get());
        $current->setRelation('tags', $current->tags()->limit(20)->get());
        $current->setRelation('checklistItems', $current->checklistItems()->orderBy('position')->orderBy('id')->limit(TasksConfiguration::limit('detail.maximum_checklist_items', 200))->get());
        $current->setRelation('timeEntries', $current->timeEntries()->orderByDesc('started_at')->orderByDesc('id')->limit(TasksConfiguration::limit('detail.maximum_time_entries', 100))->get());
        $current->loadSum('timeEntries as logged_seconds', 'duration_seconds');
        foreach (['parentLink' => 'parent_task_id', 'childLinks' => 'child_task_id', 'blockerLinks' => 'blocker_task_id', 'blockedTaskLinks' => 'task_id'] as $relation => $foreignKey) {
            $links = $current->{$relation}()
                ->whereIn($foreignKey, $this->reads->query($actor)->select('id'))
                ->orderBy('id')->limit($relation === 'parentLink' ? 1 : $limit)->get();
            $visible = [];
            foreach ($this->reads->query($actor)->whereKey($links->pluck($foreignKey)->all())->get() as $related) {
                try {
                    $this->authorization->authorize(TaskAbility::View, $actor, $related);
                    $visible[$related->id] = true;
                } catch (AuthorizationException) {
                    // The host's row policy may further restrict the bounded related models.
                }
            }
            $current->setRelation($relation, $links->filter(function ($link) use ($visible, $foreignKey): bool {
                $id = $link->getAttribute($foreignKey);

                return is_string($id) && isset($visible[$id]);
            })->values());
        }

        return $current;
    }

    /** Return a bounded task page with optional classification, actor, tag and date filters.
     * @param  array{status?: BackedEnum|string|null, priority?: BackedEnum|string|null, assignee?: Authenticatable|null, type?: BackedEnum|string|null, category?: BackedEnum|string|null, importance?: BackedEnum|string|null, tag?: string|null, target_from?: string|null, target_to?: string|null, due_from?: string|null, due_to?: string|null, overdue?: bool|null}  $criteria
     * @return LengthAwarePaginator<int, Task>
     */
    public function paginate(array $criteria, Authenticatable|SystemActor $actor, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $actor = $this->principals->actor($actor);
        $this->validateCriteria($criteria);
        $status = $criteria['status'] ?? null;
        $priority = $criteria['priority'] ?? null;
        $type = $criteria['type'] ?? null;
        $category = $criteria['category'] ?? null;
        $importance = $criteria['importance'] ?? null;
        $tag = $criteria['tag'] ?? null;
        $assignee = $criteria['assignee'] ?? null;
        if ($assignee !== null) {
            $assignee = $this->principals->actor($assignee);
        }
        $targetFrom = $criteria['target_from'] ?? null;
        $targetTo = $criteria['target_to'] ?? null;
        $dueFrom = $criteria['due_from'] ?? null;
        $dueTo = $criteria['due_to'] ?? null;
        $overdue = $criteria['overdue'] ?? null;
        $this->authorization->authorize(TaskAbility::List, $actor);
        $pageSize = $perPage;

        if ($page < 1 || $pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task page size is outside the configured bounds.');
        }

        $query = $this->reads->query($actor)->withCount('assignments');

        $this->applyEnumFilter($query, 'status', $status);
        $this->applyEnumFilter($query, 'priority', $priority);
        $this->applyEnumFilter($query, 'type', $type);
        $this->applyEnumFilter($query, 'category', $category);
        $this->applyEnumFilter($query, 'importance', $importance);

        if ($assignee !== null) {
            if (($assignee instanceof SystemActor)) {
                throw new InvalidArgumentException('System context cannot be a task assignee.');
            }

            $query->whereIn('id', TaskAssignment::query()
                ->select('task_id')
                ->where('assignee_type', TaskPrincipalGuard::type($assignee))
                ->where('assignee_id', (string) TaskPrincipalGuard::id($assignee)));
        }

        if ($tag !== null) {
            $normalizedTag = Str::lower(Str::squish($tag));

            if ($normalizedTag === '' || Str::length($normalizedTag) > 64) {
                throw ValidationException::withMessages(['tag' => 'The selected task tag is invalid.']);
            }

            $query->whereIn('id', TaskTag::query()->select('task_id')->where('tag', $normalizedTag));
        }

        $this->applyDateWindow($query, 'target_at', $targetFrom, $targetTo);
        $this->applyDateWindow($query, 'due_at', $dueFrom, $dueTo);

        if ($overdue !== null) {
            $now = CarbonImmutable::now('UTC');
            $completedStatus = $this->status('nvl-tasks.dashboard.statuses.completed', TaskEnumConfiguration::completedStatus());
            $cancelledStatus = $this->status('nvl-tasks.dashboard.statuses.cancelled', TaskStatus::Cancelled->value);

            if ($overdue) {
                $query->where('due_at', '<', $now)->whereNotIn('status', [$completedStatus, $cancelledStatus]);
            } else {
                $query->where(function (Builder $group) use ($now, $completedStatus, $cancelledStatus): void {
                    $group->whereNull('due_at')
                        ->orWhere('due_at', '>=', $now)
                        ->orWhereIn('status', [$completedStatus, $cancelledStatus]);
                });
            }
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    /** Resolve an optional dashboard status mapping used by deadline filters. */
    private function status(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The dashboard status mapping [{$key}] must be a nonempty string.");
        }

        return $value;
    }

    /** Apply one configured string-backed enum filter to the query.
     *
     * @param  Builder<Task>  $query
     */
    private function applyEnumFilter(Builder $query, string $field, BackedEnum|string|null $value): void
    {
        if ($value === null) {
            return;
        }

        $candidate = $value instanceof BackedEnum ? $value->value : $value;
        $enum = TaskEnumConfiguration::enumClass($field);

        if (! is_string($candidate) || $enum::tryFrom($candidate) === null) {
            throw ValidationException::withMessages([$field => ["The selected {$field} is invalid."]]);
        }

        $query->where($field, $candidate);
    }

    /** Apply inclusive host-calendar dates as UTC instants, preserving DST edges.
     *
     * @param  Builder<Task>  $query
     */
    private function applyDateWindow(Builder $query, string $column, ?string $from, ?string $to): void
    {
        $first = $this->calendarDate($from);
        $last = $this->calendarDate($to);

        if ($first !== null && $last !== null && $first > $last) {
            throw new InvalidArgumentException('The task date window starts after it ends.');
        }

        if ($first !== null) {
            $query->where($column, '>=', $first->startOfDay()->utc());
        }

        if ($last !== null) {
            $query->where($column, '<', $last->addDay()->startOfDay()->utc());
        }
    }

    /** Parse a strict YYYY-MM-DD calendar date for a task date window. */
    private function calendarDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Task date windows require YYYY-MM-DD values.');
        }

        return CarbonImmutable::instance($date);
    }

    /** Return one stable page of checklist items.
     *
     * @return LengthAwarePaginator<int, TaskChecklistItem>
     */
    public function checklist(Task|string $task, Authenticatable|SystemActor $actor, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $actor = $this->principals->actor($actor);
        $current = $this->reads->resolve($task, $actor);
        $pageSize = $perPage ?? TasksConfiguration::limit('default_page_size', 25);

        if ($page < 1 || $pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task checklist page is outside the configured bounds.');
        }

        return $current->checklistItems()
            ->orderBy('position')->orderBy('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    /** Return one stable page of manual intervals and timers.
     *
     * @return LengthAwarePaginator<int, TaskTimeEntry>
     */
    public function timeEntries(Task|string $task, Authenticatable|SystemActor $actor, ?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $actor = $this->principals->actor($actor);
        $current = $this->reads->resolve($task, $actor);
        $pageSize = $perPage ?? TasksConfiguration::limit('default_page_size', 25);

        if ($page < 1 || $pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task time-entry page is outside the configured bounds.');
        }

        return $current->timeEntries()
            ->orderByDesc('started_at')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    /** Return fixed-query task and effort aggregates for a bounded deadline window.
     * @return array{total: int, open: int, in_progress: int, blocked: int, completed: int, overdue: int, due_soon: int, due_soon_days: int, unassigned: int, estimated_seconds: int, logged_seconds: int}
     */
    public function dashboard(Authenticatable|SystemActor $actor, ?int $dueSoonDays = null): array
    {
        $actor = $this->principals->actor($actor);
        $this->authorization->authorize(TaskAbility::List, $actor);

        $days = $dueSoonDays ?? config('nvl-tasks.dashboard.due_soon_days', 7);

        if (! is_int($days) || $days < 1 || $days > 90) {
            throw new InvalidArgumentException('The dashboard due-soon window must be between 1 and 90 days.');
        }

        $open = $this->status('nvl-tasks.dashboard.statuses.open', $this->status('nvl-tasks.defaults.status', TaskStatus::Open->value));
        $inProgress = $this->status('nvl-tasks.dashboard.statuses.in_progress', TaskStatus::InProgress->value);
        $blocked = $this->status('nvl-tasks.dashboard.statuses.blocked', TaskStatus::Blocked->value);
        $completed = $this->status('nvl-tasks.dashboard.statuses.completed', $this->status('nvl-tasks.lifecycle.completed_status', TaskStatus::Completed->value));
        $cancelled = $this->status('nvl-tasks.dashboard.statuses.cancelled', TaskStatus::Cancelled->value);
        $now = CarbonImmutable::now();
        $soonEnd = $now->addDays($days)->utc();
        $now = $now->utc();

        $query = $this->reads->query($actor);

        $summary = $this->aggregate($query, $open, $inProgress, $blocked, $completed, $cancelled, $now, $soonEnd);
        $loggedSeconds = TaskTimeEntry::query()
            ->whereIn('task_id', (clone $query)->select('id'))
            ->sum('duration_seconds');

        return [

            'total' => $summary['total'],
            'open' => $summary['open_count'],
            'in_progress' => $summary['in_progress_count'],
            'blocked' => $summary['blocked_count'],
            'completed' => $summary['completed_count'],
            'overdue' => $summary['overdue_count'],
            'due_soon' => $summary['due_soon_count'],
            'due_soon_days' => $days,
            'unassigned' => $summary['unassigned_count'],
            'estimated_seconds' => $summary['estimated_seconds'],
            'logged_seconds' => (int) $loggedSeconds,
        ];
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

    /** Validate direct runtime calls independently of documentation types.
     * @param  array<string, mixed>  $criteria
     */
    private function validateCriteria(array $criteria): void
    {
        $allowed = ['status', 'priority', 'assignee', 'type', 'category', 'importance', 'tag', 'target_from', 'target_to', 'due_from', 'due_to', 'overdue'];
        TaskMutationValues::keys($criteria, $allowed);
        foreach (['status', 'priority', 'type', 'category', 'importance'] as $field) {
            if (isset($criteria[$field]) && ! is_string($criteria[$field]) && ! $criteria[$field] instanceof BackedEnum) {
                throw ValidationException::withMessages([$field => 'Expected a string or enum.']);
            }
        }
        foreach (['tag', 'target_from', 'target_to', 'due_from', 'due_to'] as $field) {
            if (isset($criteria[$field]) && ! is_string($criteria[$field])) {
                throw ValidationException::withMessages([$field => 'Expected a string.']);
            }
        }
        if (isset($criteria['overdue']) && ! is_bool($criteria['overdue'])) {
            throw ValidationException::withMessages(['overdue' => 'Expected a boolean.']);
        }
        if (($criteria['assignee'] ?? null) !== null && ! $criteria['assignee'] instanceof Authenticatable) {
            throw ValidationException::withMessages(['assignee' => 'Expected a persisted principal.']);
        }
    }
}
