<?php

declare(strict_types=1);

namespace Nvl\Tasks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskEnumConfiguration;

/**
 * Builds Task fixture rows and their declared package parents.
 *
 * @extends Factory<Task>
 *
 * @api
 */
final class TaskFactory extends Factory
{
    protected $model = Task::class;

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
        })->afterMaking(function (Task $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'tasks.tasks');
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<Task>, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'status' => TaskEnumConfiguration::defaultValue('status'),
            'priority' => TaskEnumConfiguration::defaultValue('priority'),
            'type' => TaskEnumConfiguration::defaultValue('type'),
            'category' => TaskEnumConfiguration::defaultValue('category'),
            'importance' => TaskEnumConfiguration::defaultValue('importance'),
            'revision' => 1,
        ];
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new Task);

        return $this->state([
            'creator_type' => $owner->getMorphClass(),
            'creator_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
