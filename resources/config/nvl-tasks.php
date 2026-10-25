<?php

declare(strict_types=1);
use Nvl\Tasks\Definitions\Tables\TasksTables;

return ['queue' => ['connection' => null, 'name' => null], 'connection' => null, 'tables' => [TasksTables::Tasks => TasksTables::Tasks, TasksTables::Assignments => TasksTables::Assignments, TasksTables::TimeEntries => TasksTables::TimeEntries, TasksTables::Relationships => TasksTables::Relationships, TasksTables::Dependencies => TasksTables::Dependencies, TasksTables::Tags => TasksTables::Tags, TasksTables::ActivityOutbox => TasksTables::ActivityOutbox], 'migrations' => ['enabled' => true], 'activity' => ['enabled' => false, 'schedule' => ['enabled' => true]], 'media' => ['enabled' => false]];
