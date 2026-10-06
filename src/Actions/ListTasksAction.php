<?php

declare(strict_types=1);

namespace Nvl\Tasks\Actions;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;

/**
 * Lists tenant-scoped tasks through an explicitly authorized query.
 *
 * @api
 */
final readonly class ListTasksAction
{
    /** Construct the task listing boundary. */
    public function __construct(private TaskAuthorization $authorization, private TenantBoundary $boundary) {}

    /** Return a bounded task page with optional classification, actor, tag and date filters.
     *
     * @return LengthAwarePaginator<int, Task>
     */
    public function execute(
        TaskActorData $actor,
        BackedEnum|string|null $status = null,
        BackedEnum|string|null $priority = null,
        ?TaskActorData $assignee = null,
        ?int $perPage = null,
        BackedEnum|string|null $type = null,
        BackedEnum|string|null $category = null,
        BackedEnum|string|null $importance = null,
        ?string $tag = null,
        ?string $targetFrom = null,
        ?string $targetTo = null,
        ?string $dueFrom = null,
        ?string $dueTo = null,
        ?bool $overdue = null,
    ): LengthAwarePaginator {
        $this->authorization->authorize(TaskAbility::List, $actor);
        $pageSize = $perPage ?? TasksConfiguration::limit('default_page_size', 25);

        if ($pageSize < 1 || $pageSize > TasksConfiguration::limit('maximum_page_size', 100)) {
            throw new InvalidArgumentException('The task page size is outside the configured bounds.');
        }

        $query = Task::query()->withCount('assignments');

        if ($this->authorization instanceof TaskQueryScope) {
            $this->authorization->scopeTasks($query, $actor);
        }

        $this->boundary->query($query, Task::TENANT_RESOURCE);
        $this->applyEnumFilter($query, 'status', $status);
        $this->applyEnumFilter($query, 'priority', $priority);
        $this->applyEnumFilter($query, 'type', $type);
        $this->applyEnumFilter($query, 'category', $category);
        $this->applyEnumFilter($query, 'importance', $importance);

        if ($assignee !== null) {
            if ($assignee->system) {
                throw new InvalidArgumentException('System context cannot be a task assignee.');
            }

            $query->whereIn('id', TaskAssignment::query()
                ->select('task_id')
                ->where('assignee_type', $assignee->type)
                ->where('assignee_id', (string) $assignee->id));
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
            $now = CarbonImmutable::now();
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

        return $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($pageSize);
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

    /** Apply an inclusive calendar-date window to a timestamp column.
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
            $query->where($column, '>=', $first->startOfDay());
        }

        if ($last !== null) {
            $query->where($column, '<', $last->addDay()->startOfDay());
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
}
