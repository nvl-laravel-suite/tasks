<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Defines the supported unlink task parent workflow.
 *
 * @api
 */
interface UnlinkTaskParentContract
{
    /** Remove a canonical parent edge after checking both expected revisions. */
    public function execute(
        Task|string $child,
        Task|string $parent,
        int $expectedChildRevision,
        int $expectedParentRevision,
        TaskActorData $actor,
    ): bool;
}
