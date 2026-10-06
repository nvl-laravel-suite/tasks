<?php

declare(strict_types=1);
use Nvl\Tasks\Definitions\Tables\TasksTables;

return ['queue' => ['connection' => null, 'name' => null], 'connection' => null, 'tables' => [TasksTables::Tasks => TasksTables::Tasks, TasksTables::Assignments => TasksTables::Assignments, TasksTables::TimeEntries => TasksTables::TimeEntries, TasksTables::Relationships => TasksTables::Relationships, TasksTables::Dependencies => TasksTables::Dependencies, TasksTables::Tags => TasksTables::Tags, TasksTables::ActivityOutbox => TasksTables::ActivityOutbox], 'migrations' => ['enabled' => true], 'activity' => ['enabled' => null, 'schedule' => ['enabled' => true]], 'routes' => ['management' => ['enabled' => false, 'prefix' => 'nvl/api/v1/tasks', 'name' => 'nvl.tasks.management.', 'middleware' => ['api', 'auth', 'throttle:60,1']]], 'media' => ['enabled' => null]];
