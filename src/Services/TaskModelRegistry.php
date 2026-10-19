<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Tasks\Models\Task;

/**
 * Resolves the one configured Task extension through the shared native model identity.
 *
 * @api
 */
final class TaskModelRegistry
{
    /** @var class-string<Task>|null */
    private ?string $task = null;

    private bool $validated = false;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        private readonly Repository $configuration,
        private readonly OwnerRegistry $identities,
    ) {}

    /** Resolve and freeze the concrete Task class before resource compilation.
     * @internal
     *
     * @return class-string<Task>
     */
    public function prepare(): string
    {
        if ($this->task !== null) {
            return $this->task;
        }
        $configured = $this->configuration->get('nvl-tasks.models.task', Task::class);
        if (! is_string($configured)) {
            throw new OwnershipConfigurationInvalid('Task model configuration must name a concrete Task subclass.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, Task::class);
        if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
            throw new OwnershipConfigurationInvalid('Task roots require a native column or concrete primary pivot.');
        }
        $this->identities->registerReplacement('tasks.tasks', Task::class, $class);
        $this->task = $class;

        return $class;
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<Task>
     */
    public function taskClass(): string
    {
        $class = $this->prepare();
        if ($this->validated) {
            return $class;
        }
        $model = new $class;
        $base = new Task;
        (new ConfiguredModelResolver)->assertOwnershipCapabilities($model, $base);
        $this->validated = true;

        return $class;
    }

    /** Resolve the immutable model from native framework entry points.
     * @internal
     *
     * @return class-string<Task>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->taskClass();
    }

    /**
     * Construct the exact configured class without retaining a request-scoped model.
     *
     * @internal
     */
    public static function newInstance(): Task
    {
        $class = Container::getInstance()->make(self::class)->taskClass();

        return new $class;
    }

    /** Persist a package-authored attribute map under the exact source assignment.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): Task
    {
        return Container::getInstance()->make(OwnershipWriter::class)->create(self::modelClass(), static function (array $ownership) use ($attributes): Task {
            $model = self::newInstance();
            $model->forceFill([...$attributes, ...$ownership])->save();

            return $model;
        });
    }

    /**
     * Resolve a fresh builder for the exact configured task.
     *
     * @return Builder<Task>
     *
     * @internal
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
