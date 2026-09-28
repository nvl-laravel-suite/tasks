<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Tasks\Actions\AddTaskChecklistItemAction;
use Nvl\Tasks\Actions\AddTaskDependencyAction;
use Nvl\Tasks\Actions\AddTaskTagAction;
use Nvl\Tasks\Actions\AddTaskTimeEntryAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskTimeEntryAction;
use Nvl\Tasks\Actions\LinkTaskParentAction;
use Nvl\Tasks\Actions\RemoveTaskChecklistItemAction;
use Nvl\Tasks\Actions\RemoveTaskDependencyAction;
use Nvl\Tasks\Actions\RemoveTaskTagAction;
use Nvl\Tasks\Actions\ReorderTaskChecklistItemsAction;
use Nvl\Tasks\Actions\StartTaskTimerAction;
use Nvl\Tasks\Actions\StopTaskTimerAction;
use Nvl\Tasks\Actions\ToggleTaskChecklistItemAction;
use Nvl\Tasks\Actions\UnlinkTaskParentAction;
use Nvl\Tasks\Actions\UpdateTaskChecklistItemAction;
use Nvl\Tasks\Actions\UpdateTaskTimeEntryAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\AddTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\RemoveTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\ReorderTaskChecklistItemsData;
use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\Mutations\TimeEntryData;
use Nvl\Tasks\Data\Mutations\ToggleTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\UpdateTaskChecklistItemData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;

/** @return list<string> */
function childActivityEvents(Task $task): array
{
    return ActivityLog::query()->where('subject_id', $task->id)->pluck('event')->all();
}

function childActivityActor(): TaskActorData
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill(['name' => 'Worker', 'email' => 'worker@example.test', 'password' => 'unused'])->save();

    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });

    return TaskActorData::fromAuthenticatable($user);
}

it('records meaningful checklist changes on the task', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Launch'), $actor);
    $first = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('First', 1), $actor);
    $second = app(AddTaskChecklistItemAction::class)->execute($task, new AddTaskChecklistItemData('Second', 2), $actor);
    app(UpdateTaskChecklistItemAction::class)->execute($task, $first, new UpdateTaskChecklistItemData('Revised', 3), $actor);
    app(ToggleTaskChecklistItemAction::class)->execute($task, $first, new ToggleTaskChecklistItemData(true, 4), $actor);
    app(ReorderTaskChecklistItemsAction::class)->execute($task, new ReorderTaskChecklistItemsData([$second->id, $first->id], 5), $actor);
    app(RemoveTaskChecklistItemAction::class)->execute($task, $first, new RemoveTaskChecklistItemData(6), $actor);

    expect(childActivityEvents($task))->toEqualCanonicalizing([
        'created', 'checklist_item_added', 'checklist_item_added', 'checklist_item_updated',
        'checklist_item_completed', 'checklist_reordered', 'checklist_item_removed',
    ]);
});

it('records tag changes without duplicate events for idempotent calls', function (): void {
    $actor = TaskActorData::system();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Launch'), $actor);
    app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('Urgent', 1), $actor);
    app(AddTaskTagAction::class)->execute($task, new TaskTagMutationData('urgent', 2), $actor);
    app(RemoveTaskTagAction::class)->execute($task, new TaskTagMutationData('URGENT', 2), $actor);
    app(RemoveTaskTagAction::class)->execute($task, new TaskTagMutationData('urgent', 3), $actor);

    expect(childActivityEvents($task))->toEqualCanonicalizing(['created', 'tag_added', 'tag_removed']);
});

it('records manual time and timer transitions on the task', function (): void {
    $actor = childActivityActor();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Launch'), $actor);
    $entry = app(AddTaskTimeEntryAction::class)->execute($task, new TimeEntryData(
        '2026-09-28T09:00:00Z', '2026-09-28T10:00:00Z', 1,
    ), $actor);
    app(UpdateTaskTimeEntryAction::class)->execute($task, $entry, new TimeEntryData(
        '2026-09-28T09:00:00Z', '2026-09-28T09:30:00Z', 2,
    ), $actor);
    app(DeleteTaskTimeEntryAction::class)->execute($task, $entry, 3, $actor);

    Carbon::setTestNow('2026-09-28 11:00:00 UTC');

    try {
        $timer = app(StartTaskTimerAction::class)->execute($task, 4, $actor);
        Carbon::setTestNow('2026-09-28 11:15:00 UTC');
        app(StopTaskTimerAction::class)->execute($task, $timer, 5, $actor);
    } finally {
        Carbon::setTestNow();
    }

    expect(childActivityEvents($task))->toEqualCanonicalizing([
        'created', 'time_entry_added', 'time_entry_updated', 'time_entry_removed', 'timer_started', 'timer_stopped',
    ]);
});

it('records graph edges once on the primary task', function (): void {
    $actor = TaskActorData::system();
    $child = app(CreateTaskAction::class)->execute(new CreateTaskData('Child'), $actor);
    $parent = app(CreateTaskAction::class)->execute(new CreateTaskData('Parent'), $actor);
    $blocker = app(CreateTaskAction::class)->execute(new CreateTaskData('Blocker'), $actor);
    app(LinkTaskParentAction::class)->execute($child, $parent, 1, 1, $actor);
    app(LinkTaskParentAction::class)->execute($child, $parent, 2, 2, $actor);
    app(UnlinkTaskParentAction::class)->execute($child, $parent, 2, 2, $actor);
    app(UnlinkTaskParentAction::class)->execute($child, $parent, 3, 3, $actor);
    app(AddTaskDependencyAction::class)->execute($child, $blocker, 3, 1, $actor);
    app(AddTaskDependencyAction::class)->execute($child, $blocker, 4, 2, $actor);
    app(RemoveTaskDependencyAction::class)->execute($child, $blocker, 4, 2, $actor);
    app(RemoveTaskDependencyAction::class)->execute($child, $blocker, 5, 3, $actor);

    expect(childActivityEvents($child))->toEqualCanonicalizing([
        'created', 'parent_linked', 'parent_unlinked', 'blocker_added', 'blocker_removed',
    ])
        ->and(childActivityEvents($parent))->toBe(['created'])
        ->and(childActivityEvents($blocker))->toBe(['created']);
});
