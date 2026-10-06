<?php

declare(strict_types=1);

namespace Nvl\Tasks\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/** Names the tables owned by the Tasks package. */
final class TasksTables
{
    public const string Tasks = 'nvl_tasks_tasks';

    public const string Assignments = 'nvl_tasks_assignments';

    public const string ChecklistItems = 'nvl_tasks_checklist_items';

    public const string TimeEntries = 'nvl_tasks_time_entries';

    public const string Relationships = 'nvl_tasks_relationships';

    public const string Dependencies = 'nvl_tasks_dependencies';

    public const string Tags = 'nvl_tasks_tags';

    public const string ActivityOutbox = 'nvl_tasks_activity_outbox';

    /** Return a configured package table name. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('tasks', $key);
    }

    private function __construct() {}
}
