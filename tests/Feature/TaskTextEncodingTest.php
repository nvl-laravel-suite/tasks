<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
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
