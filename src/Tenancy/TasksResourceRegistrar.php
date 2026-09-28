<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tenancy;

use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers task ownership and the records inherited from canonical tasks. */
final readonly class TasksResourceRegistrar
{
    /** Register task resources and the standalone-to-tenant adopter. */
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoption): void
    {
        $resources->requireCompatible('tasks', 'media');
        $resources->requireCompatible('tasks', 'activity');
        $resources->register(new TenantResourceDefinition('tasks.tasks', 'tasks', Task::class));
        $resources->register(new TenantResourceDefinition(TaskActivityOutbox::TENANT_RESOURCE, 'tasks', TaskActivityOutbox::class));

        foreach ([
            'tasks.assignments' => [TaskAssignment::class, 'task'],
            'tasks.checklist_items' => [TaskChecklistItem::class, 'task'],
            'tasks.time_entries' => [TaskTimeEntry::class, 'task'],
            'tasks.tags' => [TaskTag::class, 'task'],
            'tasks.relationships' => [TaskRelationship::class, 'child'],
            'tasks.dependencies' => [TaskDependency::class, 'task'],
        ] as $key => [$model, $parentRelation]) {
            $resources->register(new TenantResourceDefinition(
                $key,
                'tasks',
                $model,
                TenantResourceKind::Inherited,
                'tasks.tasks',
                $parentRelation,
            ));
        }

        $adoption->register('tasks', TasksAdoptionAdapter::class);
    }
}
