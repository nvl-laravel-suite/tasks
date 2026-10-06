<?php

declare(strict_types=1);

namespace Nvl\Tasks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;

/**
 * Builds TaskTag fixture rows and their declared package parents.
 *
 * @extends Factory<TaskTag>
 *
 * @api
 */
final class TaskTagFactory extends Factory
{
    protected $model = TaskTag::class;

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
        })->afterMaking(function (TaskTag $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('task_id') !== null) {
                $parent = Task::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('task_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TaskTag>, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'tag' => $this->faker->unique()->word(),
        ];
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forTask(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskTag);

        return $this->state([
            'task_id' => $parent->getKey(),
        ]);
    }
}
