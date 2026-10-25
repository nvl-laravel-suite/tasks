<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Support\TasksConfiguration;

/** Native TaskTags operations with explicit actors and canonical persistence.
 *
 * @api
 */
final readonly class TaskTags
{
    /** Retain scoped domain collaborators without retaining caller state. */
    public function __construct(private TaskAuthorization $authorization, private OwnershipBoundary $boundary, private TasksActivity $activity, private TaskRecordWriter $records, private TaskPrincipalGuard $principals) {}

    /** Add a tag once and advance the task revision only for a new row. */
    public function add(Task|string $task, string $tag, int $expectedRevision, Authenticatable|SystemActor $actor): TaskTag
    {
        $actor = $this->principals->actor($actor);
        $id = $task instanceof Task ? $task->getKey() : $task;

        $record = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $tag, $expectedRevision, $id): TaskTag {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $tag = $this->normalize($tag);
            $existing = TaskTag::query()->where('task_id', $current->id)->where('tag', $tag)->first();

            if ($existing instanceof TaskTag) {
                return $existing;
            }

            if (TaskTag::query()->where('task_id', $current->id)->count() >= 20) {
                throw ValidationException::withMessages(['tag' => 'A task may have at most twenty tags.']);
            }

            $record = $this->records->tag([
                'task_id' => $current->id,
                'tenant_id' => $current->tenant_id,
                'tag' => $tag,
            ]);
            $current->forceFill(['revision' => $current->revision + 1])->save();
            $this->activity->tagAdded($current, $tag, $actor);

            return $record->refresh();
        });

        return $record;
    }

    /** Remove a tag and advance the task revision only when a row existed. */
    public function remove(Task|string $task, string $tag, int $expectedRevision, Authenticatable|SystemActor $actor): bool
    {
        $actor = $this->principals->actor($actor);
        $id = $task instanceof Task ? $task->getKey() : $task;

        $changedTask = DB::connection(TasksConfiguration::connection())->transaction(function () use ($actor, $tag, $expectedRevision, $id): ?Task {
            $current = $this->boundary->query(TaskModelRegistry::query(), Task::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize(TaskAbility::Update, $actor, $current);

            if ($current->revision !== $expectedRevision) {
                throw new TaskRevisionConflict;
            }

            $tag = $this->normalize($tag);
            $removed = TaskTag::query()->where('task_id', $current->id)
                ->where('tag', $tag)->delete() > 0;

            if ($removed) {
                $current->forceFill(['revision' => $current->revision + 1])->save();
                $this->activity->tagRemoved($current, $tag, $actor);
            }

            return $removed ? $current : null;
        });

        return $changedTask instanceof Task;
    }

    /** Normalize the bounded task label vocabulary. */
    private function normalize(string $tag): string
    {
        $tag = Str::lower(Str::squish($tag));
        Validator::make(['tag' => $tag], ['tag' => ['required', 'string', 'max:64']])->validate();

        return $tag;
    }
}
