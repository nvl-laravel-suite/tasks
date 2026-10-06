<?php

declare(strict_types=1);

namespace Nvl\Tasks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskRelationship;

/**
 * Builds TaskRelationship fixture rows and their declared package parents.
 *
 * @extends Factory<TaskRelationship>
 *
 * @api
 */
final class TaskRelationshipFactory extends Factory
{
    protected $model = TaskRelationship::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (TaskRelationship $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('child_task_id') !== null) {
                $parent = Task::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('child_task_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
            if ($model->getAttribute('parent_task_id') !== null) {
                $parent = Task::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('parent_task_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
            if ($model->child_task_id === $model->parent_task_id) {
                throw new InvalidArgumentException('Task graph fixtures require two distinct tasks.');
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TaskRelationship>, mixed>
     */
    public function definition(): array
    {
        return [
            'child_task_id' => Task::factory(),
            'parent_task_id' => Task::factory(),
        ];
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forChild(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskRelationship);

        return $this->state([
            'child_task_id' => $parent->getKey(),
        ]);
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forParent(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskRelationship);

        return $this->state([
            'parent_task_id' => $parent->getKey(),
        ]);
    }
}
