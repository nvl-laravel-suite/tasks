<?php

declare(strict_types=1);

use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Enums\TaskType;

return [
    'connection' => null,

    'tables' => [
        TasksTables::Tasks => TasksTables::Tasks,
        TasksTables::Assignments => TasksTables::Assignments,
        TasksTables::ChecklistItems => TasksTables::ChecklistItems,
        TasksTables::TimeEntries => TasksTables::TimeEntries,
        TasksTables::Relationships => TasksTables::Relationships,
        TasksTables::Dependencies => TasksTables::Dependencies,
        TasksTables::Tags => TasksTables::Tags,
        TasksTables::ActivityOutbox => TasksTables::ActivityOutbox,
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
        'enabled' => null,
        'queue' => 'maintenance',
        'schedule' => [
            'enabled' => true,
        ],
        'drain_limit' => 100,
    ],

    'routes' => [
        'management' => [
            'enabled' => false,
            'prefix' => 'api/v1/tasks',
            'name' => 'nvl.tasks.management.',
            'middleware' => ['api', 'auth', 'throttle:60,1'],
        ],
    ],

    'media' => [
        'enabled' => null,
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
