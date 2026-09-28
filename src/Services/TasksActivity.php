<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Nvl\Activity\Enums\ActivityEvent;
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Activity\Models\ActivityLog as ActivityLogModel;
use Nvl\Activity\Support\ActivityCauserReference;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\TimelineActivityRules;
use Nvl\Activity\Tenancy\ActivityOwnershipGuard;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Jobs\ProcessTaskActivityOutboxJob;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use Throwable;

/** Composes one canonical Activity event for each meaningful task mutation. */
final class TasksActivity
{
    /** Construct the task activity staging boundary. */
    public function __construct(
        private readonly TenantBoundary $boundary,
        private readonly TenantContext $tenantContext,
        private readonly ActivityOwnershipGuard $activityOwnership,
    ) {}

    /** Record a newly created task. */
    public function created(Task $task, TaskActorData $actor): void
    {
        $this->record($task, ActivityEvent::Created, $actor);
    }

    /** Record a task field replacement with inferred saved-model changes. */
    public function updated(Task $task, TaskActorData $actor): void
    {
        $changes = [];

        foreach ($task->getChanges() as $field => $value) {
            if ($field !== 'revision' && ! TimelineActivityRules::isNoisyChangeKey((string) $field)) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->record($task, ActivityEvent::Updated, $actor, attributes: $changes, old: array_intersect_key($task->getPrevious(), $changes));
    }

    /** Record a task's soft deletion. */
    public function deleted(Task $task, TaskActorData $actor): void
    {
        $this->record($task, ActivityEvent::Deleted, $actor);
    }

    /** Record restoration of a soft-deleted task. */
    public function restored(Task $task, TaskActorData $actor): void
    {
        $this->record($task, ActivityEvent::Restored, $actor);
    }

    /** Record a newly assigned principal. */
    public function assigned(Task $task, TaskActorData $actor, TaskActorData $assignee): void
    {
        $this->record($task, ActivityEvent::Assigned, $actor, $this->assignmentContext($assignee));
    }

    /** Record removal of an assigned principal. */
    public function unassigned(Task $task, TaskActorData $actor, TaskActorData $assignee): void
    {
        $this->record($task, 'unassigned', $actor, $this->assignmentContext($assignee));
    }

    /** Record addition of a checklist row. */
    public function checklistItemAdded(Task $task, string $itemId, TaskActorData $actor): void
    {
        $this->record($task, 'checklist_item_added', $actor, ['item_id' => $itemId]);
    }

    /** Record a changed checklist title. */
    public function checklistItemUpdated(Task $task, string $itemId, TaskActorData $actor): void
    {
        $this->record($task, 'checklist_item_updated', $actor, ['item_id' => $itemId]);
    }

    /** Record a changed checklist completion state. */
    public function checklistItemCompletionChanged(Task $task, string $itemId, bool $completed, TaskActorData $actor): void
    {
        $this->record($task, $completed ? 'checklist_item_completed' : 'checklist_item_reopened', $actor, ['item_id' => $itemId]);
    }

    /** Record a changed checklist order. */
    public function checklistReordered(Task $task, TaskActorData $actor): void
    {
        $this->record($task, 'checklist_reordered', $actor);
    }

    /** Record removal of a checklist row. */
    public function checklistItemRemoved(Task $task, string $itemId, TaskActorData $actor): void
    {
        $this->record($task, 'checklist_item_removed', $actor, ['item_id' => $itemId]);
    }

    /** Record addition of a normalized task tag. */
    public function tagAdded(Task $task, string $tag, TaskActorData $actor): void
    {
        $this->record($task, 'tag_added', $actor, ['tag' => $tag]);
    }

    /** Record removal of a normalized task tag. */
    public function tagRemoved(Task $task, string $tag, TaskActorData $actor): void
    {
        $this->record($task, 'tag_removed', $actor, ['tag' => $tag]);
    }

    /** Record addition of a manual time interval. */
    public function timeEntryAdded(Task $task, string $entryId, int $durationSeconds, TaskActorData $actor): void
    {
        $this->record($task, 'time_entry_added', $actor, ['entry_id' => $entryId, 'duration_seconds' => $durationSeconds]);
    }

    /** Record correction of a manual time interval. */
    public function timeEntryUpdated(Task $task, string $entryId, int $durationSeconds, TaskActorData $actor): void
    {
        $this->record($task, 'time_entry_updated', $actor, ['entry_id' => $entryId, 'duration_seconds' => $durationSeconds]);
    }

    /** Record removal of a time interval or timer. */
    public function timeEntryRemoved(Task $task, string $entryId, TaskActorData $actor): void
    {
        $this->record($task, 'time_entry_removed', $actor, ['entry_id' => $entryId]);
    }

    /** Record the start of a task timer. */
    public function timerStarted(Task $task, string $entryId, TaskActorData $actor): void
    {
        $this->record($task, 'timer_started', $actor, ['entry_id' => $entryId]);
    }

    /** Record a stopped timer and its server-calculated duration. */
    public function timerStopped(Task $task, string $entryId, int $durationSeconds, TaskActorData $actor): void
    {
        $this->record($task, 'timer_stopped', $actor, ['entry_id' => $entryId, 'duration_seconds' => $durationSeconds]);
    }

    /** Record a new parent edge on the child task. */
    public function parentLinked(Task $child, string $parentId, TaskActorData $actor): void
    {
        $this->record($child, 'parent_linked', $actor, ['parent_task_id' => $parentId]);
    }

    /** Record removal of a parent edge on the child task. */
    public function parentUnlinked(Task $child, string $parentId, TaskActorData $actor): void
    {
        $this->record($child, 'parent_unlinked', $actor, ['parent_task_id' => $parentId]);
    }

    /** Record a new blocker edge on the blocked task. */
    public function blockerAdded(Task $task, string $blockerId, TaskActorData $actor): void
    {
        $this->record($task, 'blocker_added', $actor, ['blocker_task_id' => $blockerId]);
    }

    /** Record removal of a blocker edge on the blocked task. */
    public function blockerRemoved(Task $task, string $blockerId, TaskActorData $actor): void
    {
        $this->record($task, 'blocker_removed', $actor, ['blocker_task_id' => $blockerId]);
    }

    /** Return the safe identity context of an assignment target.
     *
     * @return array{assignee_type: string|null, assignee_id: string|null}
     */
    private function assignmentContext(TaskActorData $assignee): array
    {
        return [
            'assignee_type' => $assignee->type,
            'assignee_id' => $assignee->id === null ? null : (string) $assignee->id,
        ];
    }

    /** Stage one immutable event within the task transaction.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributes
     * @param  array<string, mixed>|null  $old
     */
    private function record(
        Task $task,
        ActivityEvent|string $event,
        TaskActorData $actor,
        array $context = [],
        ?array $attributes = null,
        ?array $old = null,
    ): void {
        $taskConnection = $task->getConnection();

        if ($taskConnection->transactionLevel() < 1) {
            throw new LogicException('Task activity must be staged inside the task mutation transaction.');
        }

        $ownership = $this->boundary->attributes(TaskActivityOutbox::TENANT_RESOURCE);
        $tenantId = $ownership['tenant_id'] ?? $task->tenant_id;

        if ($tenantId !== $task->tenant_id) {
            throw new TenantBoundaryViolation('Task activity ownership differs from its task.');
        }

        $this->activityOwnership->assertSubject($task);
        $principal = $actor->principal();
        $canonicalCauser = $principal === null ? null : $this->activityOwnership->canonicalCauser($principal);
        $causerId = $canonicalCauser?->getKey();

        if ($canonicalCauser !== null && ! is_string($causerId) && ! is_int($causerId)) {
            throw new LogicException('The canonical task activity causer has no scalar key.');
        }

        $eventId = (string) Str::uuid();
        $eventName = $event instanceof ActivityEvent ? $event->value : $event;
        $causer = $canonicalCauser !== null
            ? new ActivityCauserReference($canonicalCauser->getMorphClass(), $causerId)
            : null;
        $envelope = new ActivityRecordEnvelope(
            id: $eventId,
            subject: new ActivitySubjectReference($task->getMorphClass(), $task->id),
            causer: $causer,
            event: $eventName,
            logName: 'tasks',
            occurredAt: CarbonImmutable::now(),
            context: ['actor_type' => $actor->type, ...$context],
            attributes: $attributes,
            old: $old,
            scalarActorId: $causer === null && ! $actor->system && $actor->id !== null ? (string) $actor->id : null,
        );
        $outbox = new TaskActivityOutbox;
        $outbox->forceFill([
            'id' => $eventId,
            'task_id' => $task->id,
            'tenant_id' => $tenantId,
            'payload' => $envelope->toArray(),
            'available_at' => CarbonImmutable::now(),
        ])->save();

        if ($taskConnection === (new ActivityLogModel)->getConnection()) {
            ActivityLog::recordEnvelope($envelope);
            $outbox->forceFill(['delivered_at' => CarbonImmutable::now()])->save();

            return;
        }

        $tenantEnvelope = TenantJobEnvelope::capture($this->tenantContext);
        $taskConnection->afterCommit(static function () use ($eventId, $tenantEnvelope): void {
            try {
                $queue = config('tasks.activity.queue', 'maintenance');
                ProcessTaskActivityOutboxJob::dispatch($eventId, $tenantEnvelope)
                    ->onQueue(is_string($queue) && $queue !== '' ? $queue : 'maintenance');
            } catch (Throwable $exception) {
                Log::error('Task activity delivery dispatch failed; the outbox event remains pending.', [
                    'event_id' => $eventId,
                    'exception' => $exception,
                ]);
            }
        });
    }
}
