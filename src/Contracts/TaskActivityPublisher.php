<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;

/** Publishes semantic task events without requiring an external Activity runtime. */
interface TaskActivityPublisher
{
    /**
     * Publish a mutation from inside its task transaction.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $attributes
     * @param  array<string, mixed>|null  $old
     */
    public function record(Task $task, string $event, TaskActorData $actor, array $context = [], ?array $attributes = null, ?array $old = null): void;
}
