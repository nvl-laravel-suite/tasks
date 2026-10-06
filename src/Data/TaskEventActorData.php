<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

/** Immutable actor identity for events without the authorization principal model.
 *
 * @api
 */
final readonly class TaskEventActorData
{
    /** Capture only native identity and trusted system status. */
    public function __construct(public ?string $type, public int|string|null $id, public bool $system = false) {}

    /** Copy scalar facts from the validated authorization actor. */
    public static function fromActor(TaskActorData $actor): self
    {
        return new self($actor->type, $actor->id, $actor->system);
    }
}
