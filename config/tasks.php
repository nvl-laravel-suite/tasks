<?php

declare(strict_types=1);

use Nvl\Tasks\Definitions\Tables\TasksTables;

return [
    'connection' => null,

    'tables' => [
        TasksTables::Tasks => TasksTables::Tasks,
        TasksTables::Assignments => TasksTables::Assignments,
    ],

    'migrations' => [
        'enabled' => true,
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
        'maximum_attachments' => 10,
        'maximum_file_bytes' => 20 * 1024 * 1024,
    ],

    'limits' => [
        'default_page_size' => 25,
        'maximum_page_size' => 100,
    ],
];
