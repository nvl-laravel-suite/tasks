<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskEventActorData;
use Nvl\Tasks\Enums\TaskChangeOperation;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Models\Task;

/** Snapshots semantic task changes on their actual writer connection. */
final readonly class TaskEvents
{
    /** Retain the source-aware dispatcher and current ownership boundary. */
    public function __construct(private DomainEventDispatcher $events, private TenantContext $tenantContext, private OwnershipContext $ownership) {}

    /**
     * Publish the persisted task revision without retaining a model or private field values.
     *
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function record(Task $task, TaskChangeOperation $operation, TaskActorData $actor, array $context = []): void
    {
        $this->events->dispatch(new TaskChanged(
            $task->id,
            $task->revision,
            $operation,
            TaskEventActorData::fromActor($actor),
            $context,
            TenantJobEnvelope::capture($this->tenantContext),
            ownership: $this->ownership->snapshot()->mode === OwnershipContextMode::Unresolved ? null : OwnershipJobEnvelope::capture($this->ownership),
        ), $task->getConnection());
    }
}
