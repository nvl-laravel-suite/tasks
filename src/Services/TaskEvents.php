<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Tasks\Enums\TaskChangeOperation;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Models\Task;

/** Snapshots semantic task changes on their actual writer connection. */
final readonly class TaskEvents
{
    /** Retain the source-aware dispatcher and current ownership boundary. */
    public function __construct(private DomainEventDispatcher $events, private OwnershipContextResolver $ownership, private OwnershipQueueContext $queues) {}

    /**
     * Publish the persisted task revision without retaining a model or private field values.
     *
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function record(Task $task, TaskChangeOperation $operation, Authenticatable|SystemActor $actor, array $context = []): void
    {
        $this->events->dispatch(new TaskChanged(
            $task->id,
            $task->revision,
            $operation,
            ['type' => TaskPrincipalGuard::type($actor), 'id' => TaskPrincipalGuard::id($actor) === null ? null : (string) TaskPrincipalGuard::id($actor), 'system' => $actor instanceof SystemActor],
            $context,
            ownership: $this->ownership->current()->snapshot()->mode === OwnershipContextMode::Unresolved ? $this->queues->captureUnowned() : OwnershipJobEnvelope::capture($this->ownership->current()),
        ), $task->getConnection());
    }
}
