<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tenancy;

use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers task ownership and its inherited assignee records. */
final readonly class TasksResourceRegistrar
{
    /** Register task resources and the standalone-to-tenant adopter. */
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoption): void
    {
        foreach (['content', 'media', 'metafields'] as $dependency) {
            $resources->requireCompatible('tasks', $dependency);
        }

        $resources->register(new TenantResourceDefinition('tasks.tasks', 'tasks', Task::class));
        $resources->register(new TenantResourceDefinition(
            'tasks.assignments',
            'tasks',
            TaskAssignment::class,
            TenantResourceKind::Inherited,
            'tasks.tasks',
            'task',
        ));
        $adoption->register('tasks', TasksAdoptionAdapter::class);
    }
}
