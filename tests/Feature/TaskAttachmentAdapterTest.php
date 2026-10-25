<?php

declare(strict_types=1);

use Nvl\Media\Database\Factories\MediaFactory;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Services\TaskLifecycle;

it('attaches private Media through the Tasks attachment boundary and preserves it across task restoration', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Review attachments'], $actor);
    $media = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 12, 'is_public' => false]);

    $attachments = app(TaskAttachments::class);
    expect($attachments->attach($task, $media->id, $actor))->toBeString()
        ->and($attachments->ids($task, $actor))->toBe([$media->id]);
    $task->delete();
    $task->restore();
    expect($attachments->ids($task, $actor))->toBe([$media->id])
        ->and($attachments->detach($task, $media->id, $actor))->toBe(1)
        ->and($attachments->ids($task, $actor))->toBe([]);
});

it('rejects public assets in the private task attachment adapter', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Private notes'], $actor);
    $media = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 12, 'is_public' => true]);

    expect(fn () => app(TaskAttachments::class)->attach($task, $media->id, $actor))
        ->toThrow(FileUnacceptableForCollection::class, 'visibility');
});

beforeEach(function (): void {
    $this->markTestSkipped('Native Media-enabled integration is certified with Task 12.');
});
