<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use LogicException;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/** Fails explicit attachment requests while keeping ordinary task behavior available. */
final class UnavailableTaskAttachments implements TaskAttachments
{
    /** Reject attachment mutations until the Media adapter is loaded and selected. */
    public function attach(Task $task, string $mediaId, TaskActorData $actor): string
    {
        throw new LogicException('The Tasks Media adapter is unavailable; load the Media provider and enable tasks.media.enabled.');
    }

    /** Reject attachment removal rather than silently discarding an unavailable capability. */
    public function detach(Task $task, string $mediaId, TaskActorData $actor): int
    {
        throw new LogicException('The Tasks Media adapter is unavailable; load the Media provider and enable tasks.media.enabled.');
    }

    /**
     * Reject explicit attachment reads while the capability is unavailable.
     *
     * @return list<string>
     */
    public function ids(Task $task, TaskActorData $actor): array
    {
        throw new LogicException('The Tasks Media adapter is unavailable; load the Media provider and enable tasks.media.enabled.');
    }
}
