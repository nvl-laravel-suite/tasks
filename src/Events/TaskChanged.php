<?php

declare(strict_types=1);

namespace Nvl\Tasks\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tasks\Data\TaskEventActorData;
use Nvl\Tasks\Enums\TaskChangeOperation;

/** A committed task operation independent of optional Activity delivery.
 *
 * @api
 */
final readonly class TaskChanged implements DomainEvent, TenantQueuedJob
{
    /**
     * Capture task revision and safe operation-specific scalar context.
     *
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public string $taskId,
        public int $revision,
        public TaskChangeOperation $operation,
        public TaskEventActorData $actor,
        public array $context = [],
        private ?TenantJobEnvelope $envelope = null,
        public int $schemaVersion = 1,
    ) {}

    /** Return ownership captured by the writer before commit. */
    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope ?? throw new TenantBoundaryViolation('Task events require captured tenant ownership.');
    }

    /** Return the immutable payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
