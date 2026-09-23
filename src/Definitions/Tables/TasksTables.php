<?php

declare(strict_types=1);

namespace Nvl\Tasks\Definitions\Tables;

/** Names the two tables owned by the Tasks package. */
final class TasksTables
{
    public const string Tasks = 'nvl_tasks';

    public const string Assignments = 'nvl_task_assignments';

    /** Return a configured package table name. */
    public static function get(string $key): string
    {
        $value = config("tasks.tables.{$key}", $key);

        return is_string($value) && $value !== '' ? $value : $key;
    }

    private function __construct() {}
}
