<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Reloads an assignee from its canonical class, actual source and persisted identity. @internal */
final readonly class TaskPrincipalGuard
{
    /** Retain declared resource policies without retaining principals. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary) {}

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
