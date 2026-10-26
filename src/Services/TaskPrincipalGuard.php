<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Reloads an assignee from its canonical class, actual source and persisted identity.
 *
 * @internal
 */
final readonly class TaskPrincipalGuard
{
    /** Retain declared resource policies without retaining principals. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary) {}

    /** Validate every explicit actor before consulting host policy. */
    public function actor(Authenticatable|SystemActor $actor): Authenticatable|SystemActor
    {
        if ($actor instanceof SystemActor) {
            return $actor;
        }
        if (! $actor instanceof Model) {
            throw new InvalidArgumentException('Task actors must be persisted Eloquent principals.');
        }

        return $this->resolve($actor);
    }

    /** Return attribution only; this never grants authority. */
    public static function type(Authenticatable|SystemActor $actor): ?string
    {
        return $actor instanceof Model ? $actor->getMorphClass() : null;
    }

    /** Return attribution only; canonical principals are checked at operation entry. */
    public static function id(Authenticatable|SystemActor $actor): int|string|null
    {
        if ($actor instanceof SystemActor) {
            return null;
        }
        $id = $actor->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new InvalidArgumentException('Task principals require scalar identifiers.');
        }

        return $id;
    }

    /** Require a live canonical assignee and any declared ownership policy. */
    public function resolve(Model&Authenticatable $principal): Model&Authenticatable
    {
        $class = $principal::class;
        $model = new $class;
        $key = $principal->getRawOriginal($model->getKeyName());
        if (! $principal->exists || (! is_int($key) && ! is_string($key))
            || $principal->isDirty($model->getKeyName()) || $principal->getTable() !== $model->getTable()
            || $principal->getKeyName() !== $model->getKeyName() || $principal->getKeyType() !== $model->getKeyType()
            || $principal->getConnection() !== $model->getConnection()) {
            throw new InvalidArgumentException('Task assignees require an unchanged persisted canonical identity.');
        }
        $fresh = $model->newQuery()->useWritePdo()->whereKey($key)->lockForUpdate()->firstOrFail();
        if (! $fresh instanceof Authenticatable) {
            throw new InvalidArgumentException('Task assignees must remain authenticatable principals.');
        }
        foreach ($this->resources->all() as $resource) {
            if ($resource->model === $class) {
                $this->boundary->assertRecord($fresh, $resource->key);
                break;
            }
        }

        return $fresh;
    }
}
