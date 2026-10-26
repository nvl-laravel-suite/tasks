<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Models\ConfiguredModelRegistration;
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
    /** @var ConfiguredModelRegistration<Task> */
    private readonly ConfiguredModelRegistration $registration;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        Repository $configuration,
        OwnerRegistry $identities,
    ) {
        $this->registration = new ConfiguredModelRegistration(
            $configuration,
            $identities,
            'nvl-tasks.models.task',
            Task::class,
            'tasks.tasks',
            static function (string $class): void {
                if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
                    throw new OwnershipConfigurationInvalid('Task roots require a native column or concrete primary pivot.');
                }
            },
        );
    }

    /**
     * Resolve and freeze the concrete Task class before resource compilation.
     *
     * @internal
     *
     * @return class-string<Task>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<Task>
     */
    public function taskClass(): string
    {
        return $this->registration->modelClass();
    }

    /**
     * Resolve the immutable model from native framework entry points.
     *
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

    /**
     * Persist a package-authored attribute map under the exact source assignment.
     *
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
