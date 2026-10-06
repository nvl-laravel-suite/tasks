<?php

declare(strict_types=1);

namespace Nvl\Tasks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Builds TaskTimeEntry fixture rows and their declared package parents.
 *
 * @extends Factory<TaskTimeEntry>
 *
 * @api
 */
final class TaskTimeEntryFactory extends Factory
{
    protected $model = TaskTimeEntry::class;

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
        })->afterMaking(function (TaskTimeEntry $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'performer_type', 'performer_id');
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
     * @return array<model-property<TaskTimeEntry>, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'performer_type' => null,
            'performer_id' => null,
            'started_at' => now(),
            'stopped_at' => now(),
            'duration_seconds' => 0,
        ];
    }

    /**
     * Associate an admitted persisted Task parent.
     *
     * @api
     */
    public function forTask(Task $parent): static
    {
        FactoryGuard::parent($parent, new TaskTimeEntry);

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
        FactoryGuard::parent($owner, new TaskTimeEntry);

        return $this->state([
            'performer_type' => $owner->getMorphClass(),
            'performer_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
