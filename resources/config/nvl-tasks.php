<?php

declare(strict_types=1);
use Nvl\Tasks\Definitions\Tables\TasksTables;

return ['queue' => ['connection' => null, 'name' => null], 'connection' => null, 'tables' => ['tasks' => TasksTables::Tasks, 'assignments' => TasksTables::Assignments, 'time_entries' => TasksTables::TimeEntries, 'relationships' => TasksTables::Relationships, 'dependencies' => TasksTables::Dependencies, 'tags' => TasksTables::Tags, 'activity_outbox' => TasksTables::ActivityOutbox], 'migrations' => ['enabled' => true], 'activity' => ['enabled' => false, 'schedule' => ['enabled' => true]], 'media' => ['enabled' => false]];
