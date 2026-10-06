<?php

declare(strict_types=1);

namespace Nvl\Tasks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskDependency;

/**
 * Builds TaskDependency fixture rows and their declared package parents.
 *
 * @extends Factory<TaskDependency>
 *
 * @api
 */
final class TaskDependencyFactory extends Factory
{
    protected $model = TaskDependency::class;

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
        })->afterMaking(function (TaskDependency $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('task_id') !== null) {
                $parent = Task::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('task_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
            if ($model->getAttribute('blocker_task_id') !== null) {
                $parent = Task::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('blocker_task_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
            if ($model->task_id === $model->blocker_task_id) {
                throw new InvalidArgumentException('Task graph fixtures require two distinct tasks.');
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TaskDependency>, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'blocker_task_id' => Task::factory(),
        ];
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forTask(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskDependency);

        return $this->state([
            'task_id' => $parent->getKey(),
        ]);
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forBlocker(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskDependency);

        return $this->state([
            'blocker_task_id' => $parent->getKey(),
        ]);
    }
}
