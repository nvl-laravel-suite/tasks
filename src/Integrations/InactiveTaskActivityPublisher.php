<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use Nvl\Tasks\Contracts\TaskActivityPublisher;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/** Leaves new task events inactive while preserving any previously committed outbox data. */
final class InactiveTaskActivityPublisher implements TaskActivityPublisher
{
    /**
     * Complete ordinary task mutations without staging disabled activity events.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributes
     * @param  array<string, mixed>|null  $old
     */
    public function record(Task $task, string $event, TaskActorData $actor, array $context = [], ?array $attributes = null, ?array $old = null): void {}
}
