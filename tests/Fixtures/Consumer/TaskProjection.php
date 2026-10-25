<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures\Consumer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Tasks\Models\Task;

/** An example consumer-owned presentation contract, deliberately outside package autoload. */
final class TaskProjection
{
    public static function model(Model $model): array
    {
        $attributes = $model->attributesToArray();
        unset($attributes['tenant_id'], $attributes['company_id'], $attributes['owner_scope_key'], $attributes['owner_reference']);
        $result = [];
        foreach ($attributes as $key => $value) {
            $result[Str::camel($key)] = $value;
        }
        if ($model instanceof Task) {
            $result['assigneesCount'] = $model->getAttribute('assignments_count') ?? $model->assignments()->count();
        }

        return $result;
    }

    public static function detail(Task $task): array
    {
        return [
            'task' => self::model($task),
            'checklist' => $task->checklistItems->map(self::model(...))->all(),
            'timeEntries' => $task->timeEntries->map(self::model(...))->all(),
            'tags' => $task->tags->pluck('tag')->all(),
            'loggedSeconds' => (int) $task->getAttribute('logged_seconds'),
            'parentId' => $task->getRelation('parentLink')->first()?->parent_task_id,
            'childIds' => $task->getRelation('childLinks')->pluck('child_task_id')->all(),
            'blockerIds' => $task->getRelation('blockerLinks')->pluck('blocker_task_id')->all(),
            'blockedTaskIds' => $task->getRelation('blockedTaskLinks')->pluck('task_id')->all(),
        ];
    }
}
