<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Services\TaskTimeTracking;
use Nvl\Tasks\Support\TasksConfiguration;

it('reports a malformed task identifier as a missing task on every driver', function (string $id): void {
    $actor = nativeTaskUser('Reader');
    $task = app(TaskLifecycle::class)->create(['title' => 'Existing'], $actor);
    $interval = ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z'];

    foreach ([
        fn () => app(TaskLifecycle::class)->update($id, ['title' => 'Renamed'], 1, $actor),
        fn () => app(TaskLifecycle::class)->delete($id, $actor),
        fn () => app(TaskLifecycle::class)->restore($id, 1, $actor),
        fn () => app(TaskAssignments::class)->assign($id, $actor, $actor),
        fn () => app(TaskAssignments::class)->unassign($id, $actor, $actor),
        fn () => app(TaskChecklist::class)->add($id, 'Item', 1, $actor),
        fn () => app(TaskTags::class)->add($id, 'urgent', 1, $actor),
        fn () => app(TaskTags::class)->remove($id, 'urgent', 1, $actor),
        fn () => app(TaskTimeTracking::class)->add($id, $interval, 1, $actor),
        fn () => app(TaskTimeTracking::class)->startTimer($id, 1, $actor),
        fn () => app(TaskRelationships::class)->linkParent($id, $task, 1, 1, $actor),
        fn () => app(TaskRelationships::class)->addDependency($task, $id, 1, 1, $actor),
        fn () => app(TaskReader::class)->get($id, $actor),
        fn () => app(TaskReader::class)->checklist($id, $actor),
        fn () => app(TaskReader::class)->timeEntries($id, $actor),
    ] as $operation) {
        expect($operation)->toThrow(ModelNotFoundException::class);
    }

    expect(DB::connection(TasksConfiguration::connection())->transactionLevel())->toBeLessThanOrEqual(1)
        ->and(Task::query()->whereKey($task->id)->value('revision'))->toBe(1);
})->with(['not-a-uuid', '42']);

it('reports malformed checklist and time-entry identifiers as missing records on every driver', function (): void {
    $actor = nativeTaskUser('Editor');
    $task = app(TaskLifecycle::class)->create(['title' => 'Existing'], $actor);
    $interval = ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z'];

    foreach ([
        fn () => app(TaskChecklist::class)->update($task, 'not-a-uuid', 'Title', 1, $actor),
        fn () => app(TaskChecklist::class)->toggle($task, 'not-a-uuid', true, 1, $actor),
        fn () => app(TaskChecklist::class)->remove($task, 'not-a-uuid', 1, $actor),
        fn () => app(TaskTimeTracking::class)->update($task, 'not-a-uuid', $interval, 1, $actor),
        fn () => app(TaskTimeTracking::class)->delete($task, 'not-a-uuid', 1, $actor),
        fn () => app(TaskTimeTracking::class)->stopTimer($task, 'not-a-uuid', 1, $actor),
    ] as $operation) {
        expect($operation)->toThrow(ModelNotFoundException::class);
    }

    expect(Task::query()->whereKey($task->id)->value('revision'))->toBe(1);
});
