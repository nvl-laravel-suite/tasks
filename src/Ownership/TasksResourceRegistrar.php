<?php

declare(strict_types=1);

namespace Nvl\Tasks\Ownership;

use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TaskModelRegistry;

/** Declares the canonical Task root, inherited children and independently retained outbox. @internal */
final readonly class TasksResourceRegistrar
{
    /** Retain the configured model extension boundary. */
    public function __construct(private TaskModelRegistry $models) {}

    /** Compile the exact graph without creating storage or selecting an owner. */
    public function register(OwnershipResourceRegistry $resources): void
    {
        $class = $this->models->taskClass();
        $ownership = $class::ownershipDefinition();
        $scoped = $ownership->strategy !== 'unscoped';
        $resources->register(new OwnershipResourceDefinition('tasks.tasks', 'tasks', $class, $ownership));
        $resources->register(new OwnershipResourceDefinition('tasks.activity_outbox', 'tasks', TaskActivityOutbox::class, $scoped ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped()));
        foreach ([
            'tasks.assignments' => [TaskAssignment::class, 'task'],
            'tasks.checklist_items' => [TaskChecklistItem::class, 'task'],
            'tasks.time_entries' => [TaskTimeEntry::class, 'task'],
            'tasks.tags' => [TaskTag::class, 'task'],
            'tasks.relationships' => [TaskRelationship::class, 'child'],
            'tasks.dependencies' => [TaskDependency::class, 'task'],
        ] as $key => [$model, $relation]) {
            $resources->register(new OwnershipResourceDefinition($key, 'tasks', $model, $scoped ? OwnershipDefinition::inherited($relation) : OwnershipDefinition::unscoped(), parentResource: $scoped ? 'tasks.tasks' : null));
        }
    }
}
