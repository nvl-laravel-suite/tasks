<?php

declare(strict_types=1);

use Nvl\Activity\Models\ActivityLog;
use Nvl\Tasks\Services\TaskLifecycle;

it('records an update whose documented maximum fields exceed one Activity change record', function (): void {
    $actor = nativeTaskUser('Editor');
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft', 'description' => 'Short', 'metadata' => ['source' => 'editor']], $actor);
    $metadata = ['notes' => array_fill(0, 600, str_repeat('"quoted" value ', 6))];
    $description = str_repeat('я', 10000);

    $updated = app(TaskLifecycle::class)->update($task, ['title' => 'Final', 'description' => $description, 'metadata' => $metadata], 1, $actor);

    expect($updated->revision)->toBe(2)
        ->and($updated->description)->toBe($description)
        ->and($updated->metadata)->toEqual($metadata);
    $activity = ActivityLog::query()->where('subject_id', $task->id)->where('event', 'updated')->sole();
    $attributes = $activity->attribute_changes?->get('attributes');
    $old = $activity->attribute_changes?->get('old');
    expect($attributes['title'] ?? null)->toBe('Final')
        ->and($attributes['description'] ?? null)->toBe($description)
        ->and($attributes['metadata'] ?? null)->toBe('[OMITTED]')
        ->and($old['title'] ?? null)->toBe('Draft')
        ->and($old['description'] ?? null)->toBe('Short')
        ->and(json_decode($old['metadata'] ?? '', true))->toEqual(['source' => 'editor']);
});

it('records an update between two maximum-length descriptions', function (): void {
    $actor = nativeTaskUser('Editor');
    $before = str_repeat('я', 10000);
    $after = str_repeat('ю', 10000);
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft', 'description' => $before], $actor);

    $updated = app(TaskLifecycle::class)->update($task, ['description' => $after], 1, $actor);

    $activity = ActivityLog::query()->where('subject_id', $task->id)->where('event', 'updated')->sole();
    expect($updated->description)->toBe($after)
        ->and($activity->attribute_changes?->get('attributes')['description'] ?? null)->toBe($after)
        ->and($activity->attribute_changes?->get('old')['description'] ?? null)->toBe($before);
});
