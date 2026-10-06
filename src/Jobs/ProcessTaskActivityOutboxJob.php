<?php

declare(strict_types=1);

namespace Nvl\Tasks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tasks\Services\TasksActivityDelivery;

/** Deliver one committed task activity event in its captured tenant scope. */
final class ProcessTaskActivityOutboxJob implements ShouldQueue, TenantQueuedJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Limit queue attempts for infrastructure failures; the outbox remains retryable. */
    public int $tries = 5;

    /** Construct an immutable outbox reference and tenant envelope. */
    public function __construct(public readonly string $eventId, private readonly TenantJobEnvelope $envelope)
    {
        $this->onConnection(PackageOptions::queueConnection('tasks'));
        $this->onQueue(PackageOptions::queueName('tasks'));
        $this->afterCommit();
    }

    /** Return the producer tenant captured before dispatch. */
    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    /** Attempt delivery; persistent failures remain available to the scheduled drain. */
    public function handle(TasksActivityDelivery $delivery): void
    {
        $delivery->deliver($this->eventId);
    }
}
