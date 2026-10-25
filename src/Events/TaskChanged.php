<?php

declare(strict_types=1);

namespace Nvl\Tasks\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Tasks\Enums\TaskChangeOperation;

/** A committed task operation independent of optional Activity delivery.
 *
 * @api
 */
final readonly class TaskChanged implements DomainEvent, OwnershipQueuedJob
{
    /**
     * Capture task revision and safe operation-specific scalar context.
     *
     * @param  array{type: string|null, id: string|null, system: bool}  $actor
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public string $taskId,
        public int $revision,
        public TaskChangeOperation $operation,
        public array $actor,
        public array $context = [],
        public int $schemaVersion = 1,
        private ?OwnershipJobEnvelope $ownership = null,
    ) {}

    /** Return native authority captured at the source, never the later dispatch scope. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownership ?? throw new OwnershipViolation('This legacy task payload must be drained before ownership cutover.');
    }

    /** Return the immutable payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
