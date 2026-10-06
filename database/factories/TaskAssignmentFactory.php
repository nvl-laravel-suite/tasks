<?php

declare(strict_types=1);

namespace Nvl\Tasks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskAssignment;

/**
 * Builds TaskAssignment fixture rows and their declared package parents.
 *
 * @extends Factory<TaskAssignment>
 *
 * @api
 */
final class TaskAssignmentFactory extends Factory
{
    protected $model = TaskAssignment::class;

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
        })->afterMaking(function (TaskAssignment $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'assignee_type', 'assignee_id');
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
     * @return array<model-property<TaskAssignment>, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'assignee_type' => null,
            'assignee_id' => null,
        ];
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forTask(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskAssignment);

        return $this->state([
            'task_id' => $parent->getKey(),
        ]);
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new TaskAssignment);

        return $this->state([
            'assignee_type' => $owner->getMorphClass(),
            'assignee_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
