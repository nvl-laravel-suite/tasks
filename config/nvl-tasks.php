<?php

declare(strict_types=1);

use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Enums\TaskType;
use Nvl\Tasks\Models\Task;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'models' => ['task' => Task::class],
    'queue' => ['connection' => null, 'name' => null],
    'connection' => null,

    'tables' => [
        'tasks' => TasksTables::Tasks,
        'assignments' => TasksTables::Assignments,
        'checklist_items' => TasksTables::ChecklistItems,
        'time_entries' => TasksTables::TimeEntries,
        'relationships' => TasksTables::Relationships,
        'dependencies' => TasksTables::Dependencies,
        'tags' => TasksTables::Tags,
        'activity_outbox' => TasksTables::ActivityOutbox,
        'graph_lock' => TasksTables::GraphLock,
    ],

    'enums' => [
        'status' => TaskStatus::class,
        'priority' => TaskPriority::class,
        'type' => TaskType::class,
        'category' => TaskCategory::class,
        'importance' => TaskImportance::class,
    ],

    'defaults' => [
        'status' => TaskStatus::Open->value,
        'priority' => TaskPriority::Normal->value,
        'type' => TaskType::General->value,
        'category' => TaskCategory::General->value,
        'importance' => TaskImportance::Normal->value,
    ],

    'lifecycle' => [
        'completed_status' => TaskStatus::Completed->value,
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'activity' => [
        'enabled' => false,
        'schedule' => [
            'enabled' => true,
        ],
        'drain_limit' => 100,
    ],

    'media' => [
        'enabled' => false,
        'maximum_attachments' => 10,
        'maximum_file_bytes' => 20 * 1024 * 1024,
    ],

    'dashboard' => [
        'due_soon_days' => 7,
    ],

    'detail' => [
        'maximum_checklist_items' => 200,
        'maximum_time_entries' => 100,
        'maximum_task_links' => 100,
    ],

    'limits' => [
        'default_page_size' => 25,
        'maximum_page_size' => 100,
    ],
];
