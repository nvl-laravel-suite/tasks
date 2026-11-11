<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskTimeTracking;

it('preserves the task revision when a checklist title is saved unchanged', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Review release'], $actor);
    $item = app(TaskChecklist::class)->add($task, 'Check notes', 1, $actor);
    Event::fake([TaskChanged::class]);

    $same = app(TaskChecklist::class)->update($task, $item, '  Check notes ', 2, $actor);

    expect($same->title)->toBe('Check notes')
        ->and($task->fresh()?->revision)->toBe(2);
    Event::assertNotDispatched(TaskChanged::class);
});

it('preserves the task revision when a checklist is reordered into its current order', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Prepare launch'], $actor);
    $first = app(TaskChecklist::class)->add($task, 'One', 1, $actor);
    $second = app(TaskChecklist::class)->add($task, 'Two', 2, $actor);
    Event::fake([TaskChanged::class]);

    $unchanged = app(TaskChecklist::class)->reorder($task, [$first->id, $second->id], 3, $actor);

    expect($unchanged->revision)->toBe(3)
        ->and($task->fresh()?->revision)->toBe(3);
    Event::assertNotDispatched(TaskChanged::class);
});

it('preserves the task revision when a manual time entry is saved unchanged', function (): void {
    $actor = nativeTaskUser('Keeper');
    $task = app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor);
    $interval = ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z', 'description' => 'Review'];
    $entry = app(TaskTimeTracking::class)->add($task, $interval, 1, $actor);
    Event::fake([TaskChanged::class]);

    $same = app(TaskTimeTracking::class)->update($task, $entry, $interval, 2, $actor);

    expect($same->duration_seconds)->toBe(3600)
        ->and($task->fresh()?->revision)->toBe(2);
    Event::assertNotDispatched(TaskChanged::class);
});

it('treats metadata resent with the same members as a no-op on every driver', function (array $resent): void {
    $actor = nativeTaskUser('Editor');
    $metadata = ['source' => 'editorial', 'batch' => 7, 'nested' => ['z' => 1, 'a' => [3, 1, 2]]];
    $task = app(TaskLifecycle::class)->create(['title' => 'Audit task', 'metadata' => $metadata], $actor);
    Event::fake([TaskChanged::class]);

    $updated = app(TaskLifecycle::class)->update($task, ['metadata' => $resent], 1, $actor);

    expect($updated->revision)->toBe(1)
        ->and($updated->metadata)->toEqual($metadata);
    Event::assertNotDispatched(TaskChanged::class);
})->with([
    'identical order' => [['source' => 'editorial', 'batch' => 7, 'nested' => ['z' => 1, 'a' => [3, 1, 2]]]],
    'reordered object keys' => [['nested' => ['a' => [3, 1, 2], 'z' => 1], 'batch' => 7, 'source' => 'editorial']],
]);

it('still records metadata whose list order or scalar type changed', function (array $changed): void {
    $actor = nativeTaskUser('Editor');
    $task = app(TaskLifecycle::class)->create(['title' => 'Audit task', 'metadata' => ['batch' => 7, 'steps' => [3, 1, 2]]], $actor);

    $updated = app(TaskLifecycle::class)->update($task, ['metadata' => $changed], 1, $actor);

    expect($updated->revision)->toBe(2)
        ->and($updated->metadata)->toEqual($changed);
})->with([
    'reordered list' => [['batch' => 7, 'steps' => [1, 2, 3]]],
    'string instead of integer' => [['batch' => '7', 'steps' => [3, 1, 2]]],
]);
