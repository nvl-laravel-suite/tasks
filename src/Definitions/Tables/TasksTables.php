<?php

declare(strict_types=1);

namespace Nvl\Tasks\Definitions\Tables;

/** Names the tables owned by the Tasks package. */
final class TasksTables
{
    public const string Tasks = 'nvl_tasks';

    public const string Assignments = 'nvl_task_assignments';

    public const string ChecklistItems = 'nvl_task_checklist_items';

    public const string TimeEntries = 'nvl_task_time_entries';

    public const string Relationships = 'nvl_task_relationships';

    public const string Dependencies = 'nvl_task_dependencies';

    public const string Tags = 'nvl_task_tags';

    public const string ActivityOutbox = 'nvl_task_activity_outbox';

    /** Return a configured package table name. */
    public static function get(string $key): string
    {
        $value = config("tasks.tables.{$key}", $key);

        return is_string($value) && $value !== '' ? $value : $key;
    }

    private function __construct() {}
}
