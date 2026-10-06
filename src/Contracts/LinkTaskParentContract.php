<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskRelationship;

/**
 * Defines the supported link task parent workflow.
 *
 * @api
 */
interface LinkTaskParentContract
{
    /** Link canonical tasks after checking both expected revisions. */
    public function execute(
        Task|string $child,
        Task|string $parent,
        int $expectedChildRevision,
        int $expectedParentRevision,
        TaskActorData $actor,
    ): TaskRelationship;
}
