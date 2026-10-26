<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Database\Factories\MediaFactory;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Exceptions\FileUnacceptableForCollection;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Services\DefaultMediaAuthorization;
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
    config(['nvl-tasks.media.enabled' => true]);
});

it('keeps only the configured latest private asset and rejects foreign associations and scan limits', function (): void {
    config(['nvl-tasks.media.maximum_attachments' => 1, 'nvl-tasks.media.maximum_file_bytes' => 20]);
    $actor = SystemActor::Background;
    $firstTask = app(TaskLifecycle::class)->create(['title' => 'First'], $actor);
    $secondTask = app(TaskLifecycle::class)->create(['title' => 'Second'], $actor);
    $first = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 12, 'is_public' => false]);
    $second = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 12, 'is_public' => false]);
    $attachments = app(TaskAttachments::class);
    $attachments->attach($firstTask, $first->id, $actor);
    expect(fn () => $attachments->attach($secondTask, $first->id, $actor))->toThrow(FileUnacceptableForCollection::class, 'foreign');
    $attachments->attach($firstTask, $second->id, $actor);
    expect($attachments->ids($firstTask, $actor))->toBe([$second->id])->and($first->fresh()->trashed())->toBeTrue();
    $oversize = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 21, 'is_public' => false]);
    expect(fn () => $attachments->attach($firstTask, $oversize->id, $actor))->toThrow(FileUnacceptableForCollection::class);
    $pending = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 12, 'is_public' => false, 'status' => MediaLifecycleStatus::PendingScan]);
    expect(fn () => $attachments->attach($firstTask, $pending->id, $actor))->toThrow(MediaUploadException::class);
    expect($attachments->ids($firstTask, $actor))->toBe([$second->id]);
});

it('requires an explicit Media grant even for the authorized Tasks system actor', function (): void {
    $actor = SystemActor::Background;
    $task = app(TaskLifecycle::class)->create(['title' => 'Policy boundary'], $actor);
    $media = MediaFactory::new()->create(['mime_type' => 'text/plain', 'size' => 12, 'is_public' => false]);
    app()->bind(MediaAuthorization::class, DefaultMediaAuthorization::class);
    app()->forgetScopedInstances();
    expect(fn () => app(TaskAttachments::class)->attach($task, $media->id, $actor))->toThrow(ModelNotFoundException::class);
    expect($media->associations()->count())->toBe(0);
});
