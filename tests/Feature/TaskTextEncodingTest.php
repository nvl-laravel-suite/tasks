<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Services\TaskTimeTracking;

it('rejects text that is not valid UTF-8 before it reaches storage or a query', function (): void {
    $actor = nativeTaskUser('Writer');
    $task = app(TaskLifecycle::class)->create(['title' => 'Existing'], $actor);
    $invalid = "broken \xC3\x28 text";
    $interval = ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z', 'description' => $invalid];

    foreach ([
        fn () => app(TaskLifecycle::class)->create(['title' => $invalid], $actor),
        fn () => app(TaskLifecycle::class)->update($task, ['description' => $invalid], 1, $actor),
        fn () => app(TaskChecklist::class)->add($task, $invalid, 1, $actor),
        fn () => app(TaskTags::class)->add($task, $invalid, 1, $actor),
        fn () => app(TaskTags::class)->remove($task, $invalid, 1, $actor),
        fn () => app(TaskTimeTracking::class)->add($task, $interval, 1, $actor),
        fn () => app(TaskReader::class)->paginate(['tag' => $invalid], $actor),
    ] as $operation) {
        expect($operation)->toThrow(ValidationException::class);
    }

    expect(Task::query()->count())->toBe(1)
        ->and($task->fresh()?->revision)->toBe(1);
});

it('rejects an invalid UTF-8 replacement title for an existing checklist item', function (): void {
    $actor = nativeTaskUser('Writer');
    $task = app(TaskLifecycle::class)->create(['title' => 'Existing'], $actor);
    $item = app(TaskChecklist::class)->add($task, 'Valid', 1, $actor);

    expect(fn () => app(TaskChecklist::class)->update($task, $item, "broken \xC3\x28 text", 2, $actor))
        ->toThrow(ValidationException::class);
    expect($item->fresh()?->title)->toBe('Valid')
        ->and($task->fresh()?->revision)->toBe(2);
});

it('rejects NUL bytes that PostgreSQL would silently truncate on every text path', function (): void {
    $actor = nativeTaskUser('Writer');
    $task = app(TaskLifecycle::class)->create(['title' => 'Existing', 'description' => 'Kept'], $actor);
    $item = app(TaskChecklist::class)->add($task, 'Valid', 1, $actor);
    $tag = app(TaskTags::class)->add($task, 'kept', 2, $actor);
    $entry = app(TaskTimeTracking::class)->add($task, ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z', 'description' => 'Kept'], 3, $actor);
    $nul = "quiet\0subject";
    $interval = ['started_at' => '2026-09-28T09:00:00Z', 'ended_at' => '2026-09-28T10:00:00Z', 'description' => "private\0tail"];
    Event::fake([TaskChanged::class]);

    foreach ([
        fn () => app(TaskLifecycle::class)->create(['title' => $nul], $actor),
        fn () => app(TaskLifecycle::class)->create(['title' => 'Fine', 'description' => $nul], $actor),
        fn () => app(TaskLifecycle::class)->update($task, ['title' => $nul], 4, $actor),
        fn () => app(TaskLifecycle::class)->update($task, ['description' => $nul], 4, $actor),
        fn () => app(TaskChecklist::class)->add($task, $nul, 4, $actor),
        fn () => app(TaskChecklist::class)->update($task, $item, $nul, 4, $actor),
        fn () => app(TaskTags::class)->add($task, "kept\0tail", 4, $actor),
        fn () => app(TaskTags::class)->remove($task, "kept\0tail", 4, $actor),
        fn () => app(TaskTimeTracking::class)->add($task, $interval, 4, $actor),
        fn () => app(TaskTimeTracking::class)->update($task, $entry, $interval, 4, $actor),
        fn () => app(TaskReader::class)->paginate(['tag' => "kept\0tail"], $actor),
    ] as $operation) {
        expect($operation)->toThrow(ValidationException::class);
    }

    $task->refresh();
    expect(Task::query()->count())->toBe(1)
        ->and($task->revision)->toBe(4)
        ->and($task->title)->toBe('Existing')
        ->and($task->description)->toBe('Kept')
        ->and($item->fresh()?->title)->toBe('Valid')
        ->and($tag->fresh())->not->toBeNull()
        ->and($entry->fresh()?->description)->toBe('Kept');
    Event::assertNotDispatched(TaskChanged::class);
});
