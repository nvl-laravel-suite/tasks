<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Activity\Models\ActivityLog as ActivityLogModel;
use Nvl\Activity\Ownership\ActivityOwnershipGuard;
use Nvl\Activity\Support\ActivityCauserReference;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Activity\Support\TimelineActivityRules;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Facades\PackageLog;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Jobs\ProcessTaskActivityOutboxJob;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Throwable;

/** Stages and delivers canonical Activity envelopes for active Tasks integration. */
final class ActivityTaskPublisher implements TaskActivityPublisher
{
    /** Construct the task activity staging boundary. */
    public function __construct(
        private readonly OwnershipBoundary $boundary,
        private readonly TenantContext $tenantContext,
        private readonly ActivityOwnershipGuard $activityOwnership,
        private readonly OwnershipContextResolver $context,
        private readonly OwnershipResourceRegistry $resources,
        private readonly ConnectionCommitCallbacks $commits,
    ) {}

    /** Stage one immutable event within the task transaction.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributes
     * @param  array<string, mixed>|null  $old
     */
    public function record(
        Task $task,
        string $event,
        TaskActorData $actor,
        array $context = [],
        ?array $attributes = null,
        ?array $old = null,
    ): void {
        if ($event === 'updated' && $attributes !== null) {
            $attributes = array_filter($attributes,
                static fn (mixed $value, string $key): bool => ! TimelineActivityRules::isNoisyChangeKey($key), ARRAY_FILTER_USE_BOTH);
            if ($attributes === []) {
                return;
            }
            $old = $old === null ? null : array_intersect_key($old, $attributes);
        }
        $taskConnection = $task->getConnection();

        if ($taskConnection->transactionLevel() < 1) {
            throw new LogicException('Task activity must be staged inside the task mutation transaction.');
        }

        $native = $this->resources->get(Task::TENANT_RESOURCE)->ownership->strategy !== 'unscoped';
        if ($native && $this->resources->get('activity.events')->ownership->strategy === 'unscoped') {
            throw new OwnershipViolation('Owned task activity requires independently owned audit storage.');
        }
        $ownership = $this->boundary->attributes(TaskActivityOutbox::TENANT_RESOURCE);
        $tenantId = $ownership['tenant_id'] ?? $task->tenant_id;

        if (! $native && $tenantId !== $task->tenant_id) {
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
        $eventName = $event;
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
        $ownershipEnvelope = $this->context->current()->snapshot()->mode === OwnershipContextMode::Unresolved ? null : OwnershipJobEnvelope::capture($this->context->current());
        $this->commits->afterCommit($taskConnection, static function () use ($eventId, $tenantEnvelope, $ownershipEnvelope): void {
            try {
                ProcessTaskActivityOutboxJob::dispatch($eventId, $tenantEnvelope, $ownershipEnvelope);
            } catch (Throwable $exception) {
                PackageLog::log('tasks', 'error', 'nvl.tasks.activity_dispatch.failed', [
                    'event_id' => $eventId,
                    'exception' => $exception,
                ], 'normal');
            }
        });
    }
}
