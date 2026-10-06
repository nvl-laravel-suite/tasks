<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/**
 * Exposes explicit authorized task attachments without foreign model traits.
 *
 * @api
 */
interface TaskAttachments
{
    /** Associate an existing private Media asset and return its association identifier. */
    public function attach(Task $task, string $mediaId, TaskActorData $actor): string;

    /** Remove one attachment through the active Media lifecycle boundary. */
    public function detach(Task $task, string $mediaId, TaskActorData $actor): int;

    /**
     * Return the current attachment identifiers for an authorized task reader.
     *
     * @return list<string>
     */
    public function ids(Task $task, TaskActorData $actor): array;
}
