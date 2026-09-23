<?php

declare(strict_types=1);

namespace Nvl\Tasks\Support;

use InvalidArgumentException;

/** Validates the opt-in management route configuration. */
final class TasksRouteConfiguration
{
    /** Return a safe route prefix. */
    public static function path(): string
    {
        $path = config('tasks.routes.management.prefix', 'api/v1/tasks');

        if (! is_string($path)) {
            throw new InvalidArgumentException('tasks.routes.management.prefix must be a string.');
        }

        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, '//')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#D', $path) !== 1) {
            throw new InvalidArgumentException('tasks.routes.management.prefix must be a safe route prefix.');
        }

        return $path;
    }

    /** Return a safe route-name prefix. */
    public static function name(): string
    {
        $name = config('tasks.routes.management.name', 'nvl.tasks.management.');

        if (! is_string($name)) {
            throw new InvalidArgumentException('tasks.routes.management.name must be a string.');
        }

        $name = rtrim(trim($name), '.');

        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('tasks.routes.management.name must be a safe route-name prefix.');
        }

        return $name.'.';
    }

    /** Return the non-empty host-selected middleware chain.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        $middleware = config('tasks.routes.management.middleware', ['api', 'auth']);

        if (! is_array($middleware) || $middleware === []) {
            throw new InvalidArgumentException('tasks.routes.management.middleware must be a non-empty list.');
        }

        foreach ($middleware as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('tasks.routes.management.middleware must contain middleware names.');
            }
        }

        return array_values($middleware);
    }

    private function __construct() {}
}
