<?php

declare(strict_types=1);

namespace Nvl\Tasks\Support;

use InvalidArgumentException;
use Nvl\Tasks\Definitions\Tables\TasksTables;

/** Provides validated access to package-owned persistence and limits. */
final class TasksConfiguration
{
    /** Return the optional package database connection. */
    public static function connection(): ?string
    {
        $connection = config('tasks.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('tasks.connection must be a connection name or null.');
        }

        return $connection;
    }

    /** Return one configured package table. */
    public static function table(string $key): string
    {
        return TasksTables::get($key);
    }

    /** Return a positive configured limit. */
    public static function limit(string $key, int $default): int
    {
        $path = str_contains($key, '.') ? $key : "limits.{$key}";
        $value = config("tasks.{$path}", $default);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("tasks.limits.{$key} must be a positive integer.");
        }

        return $value;
    }

    private function __construct() {}
}
