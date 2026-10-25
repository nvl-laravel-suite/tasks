<?php

declare(strict_types=1);

namespace Nvl\Tasks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Tasks\Services\TasksActivityDelivery;

/** Deliver one committed task activity event in its captured ownership scope. */
final class ProcessTaskActivityOutboxJob implements OwnershipQueuedJob, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Limit queue attempts for infrastructure failures; the outbox remains retryable. */
    public int $tries = 5;

    /** Construct an immutable outbox reference and native ownership envelope. */
    public function __construct(public readonly string $eventId, private readonly ?OwnershipJobEnvelope $ownership = null)
    {
        $this->onConnection(PackageOptions::queueConnection('tasks'));
        $this->onQueue(PackageOptions::queueName('tasks'));
        $this->afterCommit();
    }

    /** Return native authority captured at the source, never the later dispatch scope. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownership ?? throw new OwnershipViolation('This legacy task payload must be drained before ownership cutover.');
    }

    /** Attempt delivery; persistent failures remain available to the scheduled drain. */
    public function handle(TasksActivityDelivery $delivery): void
    {
        $delivery->deliver($this->eventId);
    }
}
